package ext

import (
	"fmt"
	"io/ioutil"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"

	"ehang.io/nps/lib/common"
)

// confPath nps.conf 路径（与 cmd/nps 的 beego.LoadAppConfig 一致）
func confPath() string {
	return filepath.Join(common.GetRunPath(), "conf", "nps.conf")
}

// mask 敏感值读取时的脱敏占位；写回时遇到该值视为"不修改"
const mask = "******"

type confKeyMeta struct {
	sensitive bool
	kind      string // "port" / "int" / "bool" / "string"
}

// confKeys 配置键白名单：不在名单内的键一律拒绝写入。
// 键集合来自 conf/nps.conf 与源码中全部 AppConfig 读取点。
var confKeys = map[string]confKeyMeta{
	"bridge_type":             {kind: "string"},
	"bridge_port":             {kind: "port"},
	"bridge_ip":               {kind: "string"},
	"public_vkey":             {kind: "string", sensitive: true},
	"ip_limit":                {kind: "bool"},
	"disconnect_timeout":      {kind: "int"},
	"log_level":               {kind: "int"},
	"log_path":                {kind: "string"},
	"p2p_port":                {kind: "port"},
	"p2p_ip":                  {kind: "string"},
	"http_proxy_port":         {kind: "port"},
	"https_proxy_port":        {kind: "port"},
	"https_default_cert_file": {kind: "string"},
	"https_default_key_file":  {kind: "string"},
	"https_just_proxy":        {kind: "bool"},
	"http_cache":              {kind: "bool"},
	"http_cache_length":       {kind: "int"},
	"http_add_origin_header":  {kind: "bool"},
	"allow_ports":             {kind: "string"},
	"flow_store_interval":     {kind: "int"},
	"web_host":                {kind: "string"},
	"web_port":                {kind: "port"},
	"web_username":            {kind: "string"},
	"web_password":            {kind: "string", sensitive: true},
	"web_base_url":            {kind: "string"},
	"web_open_ssl":            {kind: "bool"},
	"web_cert_file":           {kind: "string"},
	"web_key_file":            {kind: "string"},
	"auth_key":                {kind: "string", sensitive: true},
	"auth_crypt_key":          {kind: "string", sensitive: true},
	"allow_user_login":        {kind: "bool"},
	"allow_user_register":     {kind: "bool"},
	"allow_user_change_username": {kind: "bool"},
	"allow_flow_limit":        {kind: "bool"},
	"allow_rate_limit":        {kind: "bool"},
	"allow_connection_num_limit": {kind: "bool"},
	"allow_multi_ip":          {kind: "bool"},
	"allow_tunnel_num_limit":  {kind: "bool"},
	"allow_local_proxy":       {kind: "bool"},
	"system_info_display":     {kind: "bool"},
}

// ConfEntry 配置读取结果的一条键值
type ConfEntry struct {
	Key       string `json:"key"`
	Value     string `json:"value"`
	Sensitive bool   `json:"sensitive"`
}

// confLine conf 文件的一行（保留注释与顺序，支持原样写回）
type confLine struct {
	isKV  bool
	key   string
	value string
	raw   string
}

// parseConf 解析 nps.conf 为有序行列表。
// 格式：顶层 key=value；# / ; 开头为注释；空行与未知行原样保留。
func parseConf(path string) ([]confLine, error) {
	b, err := ioutil.ReadFile(path)
	if err != nil {
		return nil, err
	}
	var lines []confLine
	for _, line := range strings.Split(string(b), "\n") {
		line = strings.TrimSuffix(line, "\r")
		trim := strings.TrimSpace(line)
		if trim == "" || strings.HasPrefix(trim, "#") || strings.HasPrefix(trim, ";") {
			lines = append(lines, confLine{raw: line})
			continue
		}
		idx := strings.Index(trim, "=")
		if idx <= 0 {
			lines = append(lines, confLine{raw: line})
			continue
		}
		lines = append(lines, confLine{
			isKV:  true,
			key:   strings.TrimSpace(trim[:idx]),
			value: strings.TrimSpace(trim[idx+1:]),
		})
	}
	return lines, nil
}

// writeConf 原子写回（tmp + rename，与 lib/file 的落盘方式一致）。
// 权限 0600（AUDIT M5）：文件含 auth_key/web_password 等敏感值，
// 不可对同机其他用户可读。
func writeConf(path string, lines []confLine) error {
	var sb strings.Builder
	for _, l := range lines {
		if l.isKV {
			sb.WriteString(l.key + "=" + l.value + "\n")
		} else {
			sb.WriteString(l.raw + "\n")
		}
	}
	tmp := path + ".tmp"
	if err := ioutil.WriteFile(tmp, []byte(sb.String()), 0600); err != nil {
		return err
	}
	return os.Rename(tmp, path)
}

// backupConf 变更前备份（保留最近 10 份，权限 0600 同主文件，AUDIT M5）
func backupConf(path string) error {
	b, err := ioutil.ReadFile(path)
	if err != nil {
		return err
	}
	name := fmt.Sprintf("%s.bak.%d", path, time.Now().UnixNano())
	if err := ioutil.WriteFile(name, b, 0600); err != nil {
		return err
	}
	// 清理旧备份，仅保留最近 10 份
	matches, _ := filepath.Glob(path + ".bak.*")
	if len(matches) > 10 {
		sort.Strings(matches)
		for _, old := range matches[:len(matches)-10] {
			_ = os.Remove(old)
		}
	}
	return nil
}

// ReadConfig 读取 nps.conf 全量键值（敏感值脱敏为 ******）。
// 注意：读的是磁盘文件而非 beego 内存配置——集中管理端需要的是
// "下次重启将生效的持久化状态"。
func ReadConfig() ([]ConfEntry, error) {
	lines, err := parseConf(confPath())
	if err != nil {
		return nil, err
	}
	entries := make([]ConfEntry, 0)
	for _, l := range lines {
		if !l.isKV {
			continue
		}
		e := ConfEntry{Key: l.key, Value: l.value}
		if meta, ok := confKeys[l.key]; ok && meta.sensitive {
			e.Sensitive = true
			e.Value = mask
		}
		entries = append(entries, e)
	}
	return entries, nil
}

func validateValue(key, val string, meta confKeyMeta) error {
	switch meta.kind {
	case "port":
		n, err := strconv.Atoi(val)
		if err != nil || n < 1 || n > 65535 {
			return fmt.Errorf("%s must be a port between 1 and 65535", key)
		}
	case "int":
		if _, err := strconv.Atoi(val); err != nil {
			return fmt.Errorf("%s must be an integer", key)
		}
	case "bool":
		if val != "true" && val != "false" && val != "1" && val != "0" {
			return fmt.Errorf("%s must be true or false", key)
		}
	}
	return nil
}

// WriteConfigEntries 应用键值变更：白名单校验 + 备份 + 原子写。
// 敏感键传入 "******" 或空串视为不修改（防止读-改-写回写脱敏值）。
// beego 配置在启动时加载，写文件不热生效——调用方需联动 /ext/restart。
// 返回 applied（实际写入的键）与 skipped（脱敏跳过的键）。
func WriteConfigEntries(entries map[string]string) (applied, skipped []string, err error) {
	applied = make([]string, 0)
	skipped = make([]string, 0)
	if len(entries) == 0 {
		return
	}
	path := confPath()
	lines, err := parseConf(path)
	if err != nil {
		return
	}
	// 第一遍：校验白名单与值
	for k, v := range entries {
		meta, ok := confKeys[k]
		if !ok {
			return nil, nil, fmt.Errorf("key %s is not in the whitelist", k)
		}
		if meta.sensitive && (v == "" || v == mask) {
			skipped = append(skipped, k)
			continue
		}
		if err = validateValue(k, v, meta); err != nil {
			return
		}
	}
	skipSet := make(map[string]bool)
	for _, k := range skipped {
		skipSet[k] = true
	}
	// 备份
	if err = backupConf(path); err != nil {
		return
	}
	// 第二遍：替换已有键
	existing := make(map[string]bool)
	for i := range lines {
		if !lines[i].isKV || skipSet[lines[i].key] {
			continue
		}
		if v, ok := entries[lines[i].key]; ok {
			lines[i].value = v
			existing[lines[i].key] = true
			applied = append(applied, lines[i].key)
		}
	}
	// 第三遍：新增键追加到末尾
	for k, v := range entries {
		if existing[k] || skipSet[k] {
			continue
		}
		lines = append(lines, confLine{isKV: true, key: k, value: v})
		applied = append(applied, k)
	}
	sort.Strings(applied)
	sort.Strings(skipped)
	err = writeConf(path, lines)
	return
}
