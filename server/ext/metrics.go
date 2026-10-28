package ext

import (
	"runtime"

	"ehang.io/nps/lib/version"
	"ehang.io/nps/server"

	"github.com/shirou/gopsutil/v3/cpu"
	"github.com/shirou/gopsutil/v3/load"
	"github.com/shirou/gopsutil/v3/mem"
	gnet "github.com/shirou/gopsutil/v3/net"
)

// GetSystemMetrics 系统监控指标（DESIGN.md §5.2 /ext/system）。
//
// 关键约束：绝不调用 server.GetDashboardData / dealClientData——
// 二者在 10 万级 client 下复杂度为 O(C×(H+T)) 且会清零重算 Flow
//（DESIGN.md §3）。本函数为 O(在线数)（仅 Bridge.Client 计数），
// 其余指标全部 O(1) 直读。
//
// 注意：不返回 tool.ServerStatus 历史采样——该切片由采集协程无锁
// append，直接序列化会引入数据竞争；历史曲线由集中管理端自行
// 按 /ext/system 轮询采样落库。
func GetSystemMetrics() map[string]interface{} {
	m := map[string]interface{}{
		"version":      version.VERSION,
		"core_version": version.GetVersion(),
		"ext_version":  ExtVersion,
		"goroutines":   runtime.NumGoroutine(),
	}

	// CPU：非阻塞模式，返回自上次采样以来的均值（tool 包周期采样已预热）
	if per, err := cpu.Percent(0, true); err == nil && len(per) > 0 {
		var total float64
		for _, v := range per {
			total += v
		}
		m["cpu_percent"] = total / float64(len(per))
		m["cpu_count"] = len(per)
	}

	// 内存
	if vm, err := mem.VirtualMemory(); err == nil {
		m["mem_total"] = vm.Total
		m["mem_used"] = vm.Used
		m["mem_used_percent"] = vm.UsedPercent
	}
	if sm, err := mem.SwapMemory(); err == nil {
		m["swap_total"] = sm.Total
		m["swap_used_percent"] = sm.UsedPercent
	}

	// 负载
	if la, err := load.Avg(); err == nil {
		m["load1"] = la.Load1
		m["load5"] = la.Load5
		m["load15"] = la.Load15
	}

	// TCP 连接数（遍历 /proc/net/tcp，10 万级连接约几十毫秒，
	// 调用方按 30s+ 间隔轮询）
	if conns, err := gnet.Connections("tcp"); err == nil {
		var estab int
		for _, c := range conns {
			if c.Status == "ESTABLISHED" {
				estab++
			}
		}
		m["tcp_established"] = estab
		m["tcp_total"] = len(conns)
	}

	// 在线 client 数（Bridge.Client 由 bridge.ping() 每 5 秒维护）
	if server.Bridge != nil {
		count := 0
		server.Bridge.Client.Range(func(key, value interface{}) bool {
			count++
			return true
		})
		m["client_online"] = count
	} else {
		m["client_online"] = 0
	}
	return m
}
