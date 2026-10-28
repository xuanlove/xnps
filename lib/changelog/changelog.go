// Package changelog 提供 nps-admin-ext 的内存变更日志：
// 供集中管理端（Laravel）做增量同步——轮询 Version()（几十字节），
// 变化时调 Since() 拉取增量，避免高频调用 O(C×T) 的原生 list 端点。
//
// 数据为纯内存态：进程重启后计数归零，调用方检测 Version() 回退
// 即触发一次全量对账（见 DESIGN.md §5.2）。
package changelog

import (
	"sync"
	"sync/atomic"
)

// Entry 一条变更记录
type Entry struct {
	Seq  int64  `json:"seq"`  // 全局递增序号
	Op   string `json:"op"`   // add / update / del
	Type string `json:"type"` // client / task / host
	Id   int    `json:"id"`   // 对象在 nps 内的 id
}

// capacity 环形日志容量（1 万条，覆盖一次滚动升级期间的变更量）
const capacity = 10000

var (
	counter int64             // 全局变更计数（atomic）
	buf     [capacity]Entry   // 环形缓冲
	pos     int               // 下一个写入位置
	size    int               // 已写入条数（<= capacity）
	mu      sync.Mutex        // 保护 buf/pos/size
)

// Append 记录一条变更。在 lib/file 与 web/controllers 的写操作
// 成功路径调用（每处一行，语义零变化）。
func Append(op, typ string, id int) {
	seq := atomic.AddInt64(&counter, 1)
	mu.Lock()
	buf[pos] = Entry{Seq: seq, Op: op, Type: typ, Id: id}
	pos = (pos + 1) % capacity
	if size < capacity {
		size++
	}
	mu.Unlock()
}

// Version 返回当前变更计数。重启后归零，调用方检测回退触发全量对账。
func Version() int64 {
	return atomic.LoadInt64(&counter)
}

// Since 返回 seq 之后的全部变更（按 Seq 升序）。
// 若 seq 早于环形日志起点（日志已回绕丢弃），truncated=true，
// 调用方应改走全量对账。
func Since(seq int64) (entries []Entry, truncated bool) {
	mu.Lock()
	defer mu.Unlock()
	cur := atomic.LoadInt64(&counter)
	if seq >= cur || size == 0 {
		return nil, false
	}
	// 环形日志中最老一条的 Seq
	var oldest int64
	if size < capacity {
		oldest = buf[0].Seq
	} else {
		oldest = buf[pos].Seq
	}
	if seq+1 < oldest {
		truncated = true
	}
	for i := 0; i < size; i++ {
		idx := (pos - size + i + 2*capacity) % capacity
		if e := buf[idx]; e.Seq > seq {
			entries = append(entries, e)
		}
	}
	return
}
