package ext

import (
	"crypto/sha256"
	"encoding/base64"
	"encoding/binary"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"io/ioutil"
	"net/http"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"time"

	"github.com/astaxie/beego/logs"
)

const (
	// maxInlineSize inline 模式（base64 直传）的大小上限；
	// 大文件请走 url 模式（从集中管理端制品库下载）
	maxInlineSize = 64 << 20
	// maxDownloadSize url 模式下载上限
	maxDownloadSize = 256 << 20
)

// UpgradeRequest 升级请求（DESIGN.md §9 /ext/upgrade POST）
type UpgradeRequest struct {
	Mode    string `json:"mode"`    // "inline"（base64 直传）或 "url"（制品库下载）
	Binary  string `json:"binary"`  // inline 模式：base64 编码的二进制
	Url     string `json:"url"`     // url 模式：制品下载地址
	Sha256  string `json:"sha256"`  // 期望 sha256（hex），必填以防传输损坏
	Version string `json:"version"` // 目标版本号（仅记录）
}

// Upgrade 校验并替换 nps 二进制。
//
// 替换机制：Linux 下不允许覆盖运行中的 ELF（ETXTBSY），但允许
// rename 替换其路径——运行中进程持有旧 inode，下次 systemd 拉起
// 使用新文件，实现原子替换。Windows 下运行中 exe 被锁，回退为
// 官方 update 同款做法：写 binPath-update，由调用方提示手动替换。
//
// 下载/写入全程流式（AUDIT P1-5）：url 模式不再把 256MB 制品读入内存，
// 并在落盘后校验 ELF/PE/Mach-O 头与当前 GOOS/GOARCH 是否匹配。
//
// 注意：官方 install.UpdateNps() 不可用于本方案（只拉 GitHub latest、
// 无版本选择、无回滚、且官方二进制不含 ext 模块）——升级一律由
// 集中管理端的自建发布流水线驱动（DESIGN.md §9.1）。
func Upgrade(req *UpgradeRequest) (map[string]interface{}, error) {
	binPath, err := os.Executable()
	if err != nil {
		return nil, err
	}
	binPath, _ = filepath.EvalSymlinks(binPath)

	tmp := binPath + ".ext-upgrade.tmp"
	cleanup := true
	defer func() {
		if cleanup {
			_ = os.Remove(tmp)
		}
	}()

	var digest string
	switch req.Mode {
	case "inline":
		if req.Binary == "" {
			return nil, errors.New("empty binary")
		}
		if base64.StdEncoding.DecodedLen(len(req.Binary)) > maxInlineSize {
			return nil, errors.New("binary too large, use url mode")
		}
		data, err := base64.StdEncoding.DecodeString(req.Binary)
		if err != nil {
			return nil, fmt.Errorf("base64 decode error: %s", err.Error())
		}
		if len(data) > maxInlineSize {
			return nil, errors.New("binary too large, use url mode")
		}
		if err = ioutil.WriteFile(tmp, data, 0755); err != nil {
			return nil, err
		}
		sum := sha256.Sum256(data)
		digest = hex.EncodeToString(sum[:])
	case "url":
		if req.Url == "" {
			return nil, errors.New("empty url")
		}
		digest, err = downloadToFile(req.Url, tmp)
		if err != nil {
			return nil, fmt.Errorf("download error: %s", err.Error())
		}
	default:
		return nil, errors.New("mode must be inline or url")
	}

	// sha256 校验（必填，防止传输损坏或制品被篡改）
	if req.Sha256 == "" {
		return nil, errors.New("sha256 is required")
	}
	if !strings.EqualFold(digest, req.Sha256) {
		return nil, errors.New("sha256 mismatch")
	}
	if err = validateBinary(tmp); err != nil {
		return nil, err
	}

	res := map[string]interface{}{
		"version":     req.Version,
		"sha256":      digest,
		"binary_path": binPath,
	}

	if runtime.GOOS == "windows" {
		// Windows：运行中 exe 不可覆盖，写 -update 文件，手动替换后重启
		newPath := binPath + "-update"
		_ = os.Remove(newPath)
		if err = copyFile(tmp, newPath); err != nil {
			return nil, err
		}
		res["replaced"] = false
		res["note"] = "windows cannot replace a running exe, binary written to " + newPath + ", replace and restart manually"
		return res, nil
	}

	// 本地回滚备份（AUDIT M6）：替换前把当前二进制复制为 .bak-ext，
	// 旧的 .bak-ext 顺移为 .bak-ext.old（共保留 2 份），供新二进制
	// 无法启动时带外手动恢复。备份失败仅告警不阻断升级。
	backup := binPath + ".bak-ext"
	if _, statErr := os.Stat(backup); statErr == nil {
		_ = os.Rename(backup, binPath+".bak-ext.old")
	}
	if werr := copyFile(binPath, backup); werr != nil {
		logs.Warn("ext: backup current binary failed: %s", werr.Error())
	}

	// Linux：rename 原子替换（运行中进程持旧 inode）
	if err = os.Rename(tmp, binPath); err != nil {
		return nil, err
	}
	cleanup = false
	res["replaced"] = true
	res["backup_path"] = backup
	res["note"] = "binary replaced, restart required to take effect"
	return res, nil
}

// downloadToFile 流式下载到本地文件并返回 sha256（AUDIT P1-5）
func downloadToFile(url, path string) (string, error) {
	client := &http.Client{Timeout: 10 * time.Minute}
	resp, err := client.Get(url)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("http status %d", resp.StatusCode)
	}

	f, err := os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, 0755)
	if err != nil {
		return "", err
	}
	h := sha256.New()
	n, copyErr := io.Copy(io.MultiWriter(f, h), io.LimitReader(resp.Body, maxDownloadSize+1))
	closeErr := f.Close()
	if copyErr != nil {
		_ = os.Remove(path)
		return "", copyErr
	}
	if closeErr != nil {
		_ = os.Remove(path)
		return "", closeErr
	}
	if n > maxDownloadSize {
		_ = os.Remove(path)
		return "", errors.New("binary too large (max 256MB)")
	}

	return hex.EncodeToString(h.Sum(nil)), nil
}

func copyFile(src, dst string) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	out, err := os.OpenFile(dst, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, 0755)
	if err != nil {
		return err
	}
	if _, err = io.Copy(out, in); err != nil {
		_ = out.Close()
		return err
	}
	return out.Close()
}

// validateBinary 校验可执行文件格式与当前平台架构一致（AUDIT P1-5）
func validateBinary(path string) error {
	f, err := os.Open(path)
	if err != nil {
		return err
	}
	defer f.Close()

	head := make([]byte, 64)
	n, err := io.ReadFull(f, head)
	if err != nil && err != io.ErrUnexpectedEOF && err != io.EOF {
		return err
	}
	if n < 4 {
		return errors.New("binary is too small to be an executable")
	}

	switch runtime.GOOS {
	case "windows":
		return validatePE(f, head, n)
	case "darwin":
		return validateMachO(head, n)
	default:
		return validateELF(head, n)
	}
}

func validateELF(head []byte, n int) error {
	if n < 20 || head[0] != 0x7f || string(head[1:4]) != "ELF" {
		return fmt.Errorf("binary is not an ELF executable (target %s/%s)", runtime.GOOS, runtime.GOARCH)
	}
	var order binary.ByteOrder = binary.LittleEndian
	if head[5] == 2 {
		order = binary.BigEndian
	}
	machine := order.Uint16(head[18:20])
	expected, ok := map[string]uint16{
		"amd64": 62, "386": 3, "arm64": 183, "arm": 40,
		"mips": 8, "mipsle": 8, "mips64": 10, "mips64le": 10,
	}[runtime.GOARCH]
	if !ok {
		return fmt.Errorf("unsupported local architecture %s", runtime.GOARCH)
	}
	if machine != expected {
		return fmt.Errorf("binary ELF machine %d does not match local architecture %s (expected %d)", machine, runtime.GOARCH, expected)
	}
	return nil
}

func validatePE(f *os.File, head []byte, n int) error {
	if n < 64 || string(head[:2]) != "MZ" {
		return fmt.Errorf("binary is not a Windows PE executable (target windows/%s)", runtime.GOARCH)
	}
	peOffset := int(binary.LittleEndian.Uint32(head[0x3c:0x40]))
	if peOffset <= 0 || peOffset > 1<<20 {
		return errors.New("invalid PE header offset")
	}
	pe := make([]byte, 6)
	if _, err := f.ReadAt(pe, int64(peOffset)); err != nil {
		return errors.New("truncated PE header")
	}
	if string(pe[:4]) != "PE\x00\x00" {
		return errors.New("invalid PE signature")
	}
	machine := binary.LittleEndian.Uint16(pe[4:6])
	expected, ok := map[string]uint16{
		"amd64": 0x8664, "386": 0x014c, "arm64": 0xaa64, "arm": 0x01c4,
	}[runtime.GOARCH]
	if !ok {
		return fmt.Errorf("unsupported local architecture %s", runtime.GOARCH)
	}
	if machine != expected {
		return fmt.Errorf("binary PE machine 0x%x does not match local architecture %s (expected 0x%x)", machine, runtime.GOARCH, expected)
	}
	return nil
}

func validateMachO(head []byte, n int) error {
	if n < 8 {
		return fmt.Errorf("binary is not a Mach-O executable (target darwin/%s)", runtime.GOARCH)
	}
	magicLE := binary.LittleEndian.Uint32(head[0:4])
	var order binary.ByteOrder
	switch magicLE {
	case 0xfeedface, 0xfeedfacf:
		order = binary.LittleEndian
	case 0xcefaedfe, 0xcffaedfe:
		order = binary.BigEndian
	default:
		return fmt.Errorf("binary is not a Mach-O executable (target darwin/%s)", runtime.GOARCH)
	}
	cpu := order.Uint32(head[4:8])
	expected, ok := map[string]uint32{
		"amd64": 0x01000007, "arm64": 0x0100000c, "386": 7, "arm": 12,
	}[runtime.GOARCH]
	if !ok {
		return fmt.Errorf("unsupported local architecture %s", runtime.GOARCH)
	}
	if cpu != expected {
		return fmt.Errorf("binary Mach-O CPU 0x%x does not match local architecture %s (expected 0x%x)", cpu, runtime.GOARCH, expected)
	}
	return nil
}
