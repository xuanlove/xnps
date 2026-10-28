package controllers

import (
	"encoding/base64"
	"encoding/json"
	"errors"
	"io/ioutil"
	"sort"
	"sync"
	"time"

	"ehang.io/nps/bridge"
	"ehang.io/nps/lib/changelog"
	"ehang.io/nps/lib/file"
	"ehang.io/nps/lib/version"
	"ehang.io/nps/server"
	"ehang.io/nps/server/ext"
)

// ExtController nps-admin-ext 扩展端点（DESIGN.md §5.2）。
//
// 路由（AutoRouter 自动注册为 /ext/*）：
//   POST /ext/system    系统监控 JSON（O(1)，不调 dealClientData）
//   POST /ext/version   变更计数 + 版本信息（轻量变更探测）
//   POST /ext/changes   增量变更日志（since 参数）
//   POST /ext/online    在线 client id 集合
//   POST /ext/flow      流量聚合（shard/total 分片）
//   POST /ext/list      游标分页列表（不做 Flow 聚合）
//   GET/POST /ext/config  nps.conf 读取/白名单写入
//   GET/POST /ext/cert    证书指纹查询/下发激活
//   POST /ext/restart   重启（202 + 延迟触发）
//   POST /ext/upgrade   二进制升级（inline/url 模式）
//
// 鉴权：继承 BaseController 的 auth_key 签名机制（md5(auth_key+timestamp)，
// ±20 秒窗口）；并强制要求 admin——普通用户 session 无法穿透
//（BaseController.CheckUserAuth 对未知 controller 名不设防，故此处显式拦截）。
type ExtController struct {
	BaseController
}

// Prepare 复用既有签名/登录校验，并强制 admin 权限
func (s *ExtController) Prepare() {
	s.BaseController.Prepare()
	if s.GetSession("isAdmin") != true {
		s.StopRun()
	}
}

// okData / failMsg 统一响应格式：{"status":1,"data":...} / {"status":0,"msg":...}
func (s *ExtController) okData(data interface{}) {
	s.Data["json"] = map[string]interface{}{"status": 1, "data": data}
	s.ServeJSON()
}

func (s *ExtController) failMsg(msg string) {
	s.Data["json"] = map[string]interface{}{"status": 0, "msg": msg}
	s.ServeJSON()
}

// readJSON 解析 JSON 请求体：优先 beego 缓存的 RequestBody，
// 为空则直接读原始 body（兼容 beego 未缓存 JSON body 的情况）
func (s *ExtController) readJSON(v interface{}) error {
	b := s.Ctx.Input.RequestBody
	if len(b) == 0 {
		var err error
		if b, err = ioutil.ReadAll(s.Ctx.Request.Body); err != nil {
			return err
		}
	}
	if len(b) == 0 {
		return errors.New("empty request body")
	}
	return json.Unmarshal(b, v)
}

// System 系统监控指标
func (s *ExtController) System() {
	s.okData(ext.GetSystemMetrics())
}

// Version 变更计数与版本信息（集中管理端每 10s 轮询）
func (s *ExtController) Version() {
	s.okData(map[string]interface{}{
		"version":      version.VERSION,
		"core_version": version.GetVersion(),
		"ext_version":  ext.ExtVersion,
		"change_seq":   changelog.Version(),
	})
}

// Changes 增量变更日志：POST /ext/changes?since=<seq>
// truncated=true 表示请求的 seq 早于环形日志起点，应改走全量对账
func (s *ExtController) Changes() {
	since := int64(s.GetIntNoErr("since"))
	entries, truncated := changelog.Since(since)
	if entries == nil {
		entries = make([]changelog.Entry, 0)
	}
	s.okData(map[string]interface{}{
		"version":   changelog.Version(),
		"truncated": truncated,
		"changes":   entries,
	})
}

// Online 在线 client id 集合（连接断开不产生 file 变更，
// 在线状态只能经此端点轮询）
func (s *ExtController) Online() {
	ids := make([]int, 0)
	if server.Bridge != nil {
		server.Bridge.Client.Range(func(key, value interface{}) bool {
			ids = append(ids, key.(int))
			return true
		})
	}
	sort.Ints(ids)
	s.okData(map[string]interface{}{"count": len(ids), "online": ids})
}

// flowAgg 按 client 聚合的流量条目
type flowAgg struct {
	Id  int   `json:"client_id"`
	In  int64 `json:"in"`
	Out int64 `json:"out"`
}

// Flow 流量聚合：POST /ext/flow?shard=n&total=16
// 一次 Range tasks+hosts 聚合 {client_id:{in,out}}，按 clientId 取模分片，
// 集中管理端分片轮转采集（每 5min 一片），避免单次返回过大。
// 复杂度 O(T+H)/片，绝不调用 dealClientData（其 O(C×(H+T)) 且清零重算）。
func (s *ExtController) Flow() {
	shard := s.GetIntNoErr("shard")
	total := s.GetIntNoErr("total")
	if total <= 0 || total > 1024 {
		total = 1
		shard = 0
	}
	if shard < 0 || shard >= total {
		s.failMsg("invalid shard param")
		return
	}
	agg := make(map[int]*flowAgg)
	add := func(clientId int, in, out int64) {
		e := agg[clientId]
		if e == nil {
			e = &flowAgg{Id: clientId}
			agg[clientId] = e
		}
		e.In += in
		e.Out += out
	}
	file.GetDb().JsonDb.Tasks.Range(func(key, value interface{}) bool {
		t := value.(*file.Tunnel)
		if t.Client == nil || t.Flow == nil || t.Client.Id%total != shard {
			return true
		}
		t.Flow.RLock()
		in, out := t.Flow.InletFlow, t.Flow.ExportFlow
		t.Flow.RUnlock()
		add(t.Client.Id, in, out)
		return true
	})
	file.GetDb().JsonDb.Hosts.Range(func(key, value interface{}) bool {
		h := value.(*file.Host)
		if h.Client == nil || h.Flow == nil || h.Client.Id%total != shard {
			return true
		}
		h.Flow.RLock()
		in, out := h.Flow.InletFlow, h.Flow.ExportFlow
		h.Flow.RUnlock()
		add(h.Client.Id, in, out)
		return true
	})
	list := make([]*flowAgg, 0, len(agg))
	for _, v := range agg {
		list = append(list, v)
	}
	sort.Slice(list, func(i, j int) bool { return list[i].Id < list[j].Id })
	s.okData(map[string]interface{}{
		"shard": shard,
		"total": total,
		"flows": list,
	})
}

// List 游标分页列表：POST /ext/list?type=client|tunnel|host&cursor=<lastId>&limit=500
// 只返回必要字段、不做 Flow 聚合（client 的 Flow 聚合走 /ext/flow），
// 用于冷启动导入与低频全量对账。
func (s *ExtController) List() {
	typ := s.GetString("type")
	cursor := s.GetIntNoErr("cursor")
	limit := s.GetIntNoErr("limit")
	if limit <= 0 || limit > 1000 {
		limit = 500
	}
	switch typ {
	case "client":
		items, next, total := s.listClients(cursor, limit)
		s.okData(map[string]interface{}{"items": items, "next_cursor": next, "total": total})
	case "tunnel":
		items, next, total := s.listTunnels(cursor, limit)
		s.okData(map[string]interface{}{"items": items, "next_cursor": next, "total": total})
	case "host":
		items, next, total := s.listHosts(cursor, limit)
		s.okData(map[string]interface{}{"items": items, "next_cursor": next, "total": total})
	default:
		s.failMsg("type must be client, tunnel or host")
	}
}

// sortedKeys 收集 sync.Map 的 int key 并升序排序。
//（不使用 file.GetMapKeys：其按值传递 sync.Map，会触发 vet 的 lock copy 警告）
func sortedKeys(m *sync.Map) []int {
	keys := make([]int, 0)
	m.Range(func(key, value interface{}) bool {
		keys = append(keys, key.(int))
		return true
	})
	sort.Ints(keys)
	return keys
}

func (s *ExtController) listClients(cursor, limit int) (items []map[string]interface{}, next, total int) {
	items = make([]map[string]interface{}, 0)
	keys := sortedKeys(&file.GetDb().JsonDb.Clients)
	total = len(keys)
	for _, key := range keys {
		if key <= cursor {
			continue
		}
		if len(items) >= limit {
			next = items[len(items)-1]["id"].(int)
			return
		}
		v, ok := file.GetDb().JsonDb.Clients.Load(key)
		if !ok {
			continue
		}
		c := v.(*file.Client)
		item := map[string]interface{}{
			"id":                c.Id,
			"verify_key":        c.VerifyKey,
			"remark":            c.Remark,
			"status":            c.Status,
			"addr":              c.Addr,
			"rate_limit":        c.RateLimit,
			"max_conn":          c.MaxConn,
			"max_tunnel":        c.MaxTunnelNum,
			"config_conn_allow": c.ConfigConnAllow,
			"no_store":          c.NoStore,
			"no_display":        c.NoDisplay,
		}
		if c.Cnf != nil {
			item["compress"] = c.Cnf.Compress
			item["crypt"] = c.Cnf.Crypt
			item["basic_user"] = c.Cnf.U
		}
		if c.Flow != nil {
			c.Flow.RLock()
			item["flow_limit"] = c.Flow.FlowLimit
			item["flow_in"] = c.Flow.InletFlow
			item["flow_out"] = c.Flow.ExportFlow
			c.Flow.RUnlock()
		}
		// 在线状态与 npc 版本直查 Bridge（O(1)），不做全表重算
		item["is_connect"] = false
		if server.Bridge != nil {
			if vv, ok := server.Bridge.Client.Load(c.Id); ok {
				item["is_connect"] = true
				item["npc_version"] = vv.(*bridge.Client).Version
			}
		}
		items = append(items, item)
	}
	return
}

func (s *ExtController) listTunnels(cursor, limit int) (items []map[string]interface{}, next, total int) {
	items = make([]map[string]interface{}, 0)
	keys := sortedKeys(&file.GetDb().JsonDb.Tasks)
	total = len(keys)
	for _, key := range keys {
		if key <= cursor {
			continue
		}
		if len(items) >= limit {
			next = items[len(items)-1]["id"].(int)
			return
		}
		v, ok := file.GetDb().JsonDb.Tasks.Load(key)
		if !ok {
			continue
		}
		t := v.(*file.Tunnel)
		item := map[string]interface{}{
			"id":     t.Id,
			"port":   t.Port,
			"mode":   t.Mode,
			"status": t.Status,
			"remark": t.Remark,
		}
		if t.Client != nil {
			item["client_id"] = t.Client.Id
		}
		if t.Target != nil {
			item["target"] = t.Target.TargetStr
			item["local_proxy"] = t.Target.LocalProxy
		}
		if _, ok := server.RunList.Load(t.Id); ok {
			item["run_status"] = true
		} else {
			item["run_status"] = false
		}
		if t.Flow != nil {
			t.Flow.RLock()
			item["flow_in"] = t.Flow.InletFlow
			item["flow_out"] = t.Flow.ExportFlow
			t.Flow.RUnlock()
		}
		items = append(items, item)
	}
	return
}

func (s *ExtController) listHosts(cursor, limit int) (items []map[string]interface{}, next, total int) {
	items = make([]map[string]interface{}, 0)
	keys := sortedKeys(&file.GetDb().JsonDb.Hosts)
	total = len(keys)
	for _, key := range keys {
		if key <= cursor {
			continue
		}
		if len(items) >= limit {
			next = items[len(items)-1]["id"].(int)
			return
		}
		v, ok := file.GetDb().JsonDb.Hosts.Load(key)
		if !ok {
			continue
		}
		h := v.(*file.Host)
		item := map[string]interface{}{
			"id":       h.Id,
			"host":     h.Host,
			"location": h.Location,
			"scheme":   h.Scheme,
			"remark":   h.Remark,
			"is_close": h.IsClose,
		}
		if h.Client != nil {
			item["client_id"] = h.Client.Id
		}
		if h.Target != nil {
			item["target"] = h.Target.TargetStr
		}
		if h.Flow != nil {
			h.Flow.RLock()
			item["flow_in"] = h.Flow.InletFlow
			item["flow_out"] = h.Flow.ExportFlow
			h.Flow.RUnlock()
		}
		items = append(items, item)
	}
	return
}

// Config nps.conf 管理：GET 读取（敏感值脱敏），POST 白名单写入。
// beego 配置启动时加载，写文件不热生效——POST 带 restart=true 联动重启。
func (s *ExtController) Config() {
	switch s.Ctx.Request.Method {
	case "GET":
		entries, err := ext.ReadConfig()
		if err != nil {
			s.failMsg(err.Error())
			return
		}
		s.okData(map[string]interface{}{"entries": entries})
	case "POST":
		var req struct {
			Entries map[string]string `json:"entries"`
			Restart bool              `json:"restart"`
		}
		if err := s.readJSON(&req); err != nil {
			s.failMsg(err.Error())
			return
		}
		applied, skipped, err := ext.WriteConfigEntries(req.Entries)
		if err != nil {
			s.failMsg(err.Error())
			return
		}
		if req.Restart {
			_ = ext.TriggerRestart(time.Second)
		}
		s.okData(map[string]interface{}{
			"applied": applied,
			"skipped": skipped,
			"note":    "config written to nps.conf, restart required to take effect",
		})
	default:
		s.failMsg("method not allowed")
	}
}

// Cert 证书管理：GET 查询当前证书指纹（对账），POST 下发激活。
// 激活入口唯一（certd 与 native 通道最终都调此处）。
func (s *ExtController) Cert() {
	switch s.Ctx.Request.Method {
	case "GET":
		info, err := ext.CertFingerprint()
		if err != nil {
			s.failMsg(err.Error())
			return
		}
		s.okData(info)
	case "POST":
		var req struct {
			CertPemB64 string `json:"cert_pem_b64"`
			KeyPemB64  string `json:"key_pem_b64"`
			Restart    bool   `json:"restart"`
		}
		if err := s.readJSON(&req); err != nil {
			s.failMsg(err.Error())
			return
		}
		certPem, err := base64.StdEncoding.DecodeString(req.CertPemB64)
		if err != nil {
			s.failMsg("invalid cert base64")
			return
		}
		keyPem, err := base64.StdEncoding.DecodeString(req.KeyPemB64)
		if err != nil {
			s.failMsg("invalid key base64")
			return
		}
		info, err := ext.ApplyCert(certPem, keyPem)
		if err != nil {
			s.failMsg(err.Error())
			return
		}
		if req.Restart {
			_ = ext.TriggerRestart(time.Second)
		}
		s.okData(info)
	default:
		s.failMsg("method not allowed")
	}
}

// Restart 重启：先回 202，延迟 1 秒触发（保证响应先于进程终止发出）。
// 重载与重启等价（nps 无热重载语义）。
func (s *ExtController) Restart() {
	if s.Ctx.Request.Method != "POST" {
		s.failMsg("post only")
		return
	}
	if err := ext.TriggerRestart(time.Second); err != nil {
		s.failMsg(err.Error())
		return
	}
	s.Ctx.Output.SetStatus(202)
	s.Data["json"] = map[string]interface{}{"status": 1, "msg": "restarting"}
	s.ServeJSON()
}

// AcmeIssue 内置 ACME 签发：POST /ext/acme/issue
// 由 nps 自身通过 HTTP-01 完成域名校验（挑战由 HTTP 监听器应答），
// 证书与私钥在 nps 侧生成后回传集中管理端入库。
func (s *ExtController) AcmeIssue() {
	if s.Ctx.Request.Method != "POST" {
		s.failMsg("post only")
		return
	}
	var req ext.ACMERequest
	if err := s.readJSON(&req); err != nil {
		s.failMsg(err.Error())
		return
	}
	res, err := ext.IssueACME(&req)
	if err != nil {
		s.failMsg(err.Error())
		return
	}
	s.okData(res)
}

// Upgrade 二进制升级：inline（base64 直传，小文件）或
// url（从集中管理端制品库下载，大文件主路径）。
// sha256 必填；成功后可带 restart=true 联动重启。
func (s *ExtController) Upgrade() {
	if s.Ctx.Request.Method != "POST" {
		s.failMsg("post only")
		return
	}
	var req struct {
		ext.UpgradeRequest
		Restart bool `json:"restart"`
	}
	if err := s.readJSON(&req); err != nil {
		s.failMsg(err.Error())
		return
	}
	res, err := ext.Upgrade(&req.UpgradeRequest)
	if err != nil {
		s.failMsg(err.Error())
		return
	}
	if req.Restart {
		_ = ext.TriggerRestart(time.Second)
		res["restart"] = "scheduled"
	}
	s.okData(res)
}
