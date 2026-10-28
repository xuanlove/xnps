package changelog

import "testing"

func TestAppendAndVersion(t *testing.T) {
	// 独立实例无法构造（包级状态），此处依赖测试顺序：
	// 本文件内所有用例共享包级状态，先记录基线
	base := Version()

	Append("add", "client", 1)
	Append("update", "client", 1)
	Append("del", "client", 1)

	if got := Version(); got != base+3 {
		t.Fatalf("Version() = %d, want %d", got, base+3)
	}
	entries, truncated := Since(base)
	if truncated {
		t.Fatal("unexpected truncated")
	}
	if len(entries) != 3 {
		t.Fatalf("len(entries) = %d, want 3", len(entries))
	}
	wantOps := []string{"add", "update", "del"}
	for i, e := range entries {
		if e.Op != wantOps[i] || e.Type != "client" || e.Id != 1 {
			t.Fatalf("entries[%d] = %+v", i, e)
		}
		if e.Seq != base+int64(i)+1 {
			t.Fatalf("entries[%d].Seq = %d, want %d", i, e.Seq, base+int64(i)+1)
		}
	}
}

func TestSinceCurrentReturnsEmpty(t *testing.T) {
	cur := Version()
	entries, truncated := Since(cur)
	if entries != nil || truncated {
		t.Fatalf("Since(cur) = %v, %v; want nil, false", entries, truncated)
	}
	// 超前 seq 同样返回空
	entries, truncated = Since(cur + 100)
	if entries != nil || truncated {
		t.Fatalf("Since(cur+100) = %v, %v; want nil, false", entries, truncated)
	}
}

func TestSinceZeroReturnsAll(t *testing.T) {
	cur := Version()
	entries, truncated := Since(0)
	if truncated {
		t.Fatal("unexpected truncated for since=0")
	}
	if int64(len(entries)) != cur {
		t.Fatalf("len(entries) = %d, want %d", len(entries), cur)
	}
}

func TestRingWraparound(t *testing.T) {
	// 填满环形缓冲（capacity 条）再追加一条，触发回绕
	before := Version()
	if before%int64(capacity) != 0 {
		// 先填齐到 capacity 的整数倍边界，便于断言
		for i := int64(0); i < int64(capacity)-(before%int64(capacity)); i++ {
			Append("add", "task", int(i))
		}
	}
	aligned := Version()
	if aligned%int64(capacity) != 0 {
		t.Fatalf("aligned = %d, not multiple of capacity", aligned)
	}
	// 追加 capacity+1 条：正好回绕并覆盖最老一条
	for i := 0; i < capacity+1; i++ {
		Append("update", "task", i)
	}
	after := Version()

	// since = aligned-2：早于环形日志起点（最老为 after-capacity），
	// 应 truncated=true
	entries, truncated := Since(aligned - 2)
	if !truncated {
		t.Fatal("expected truncated=true when since is older than ring start")
	}
	// 但仍应返回环内全部 capacity 条
	if len(entries) != capacity {
		t.Fatalf("len(entries) = %d, want %d", len(entries), capacity)
	}
	// 返回条目按 Seq 升序且均 > since
	for i := 1; i < len(entries); i++ {
		if entries[i].Seq <= entries[i-1].Seq {
			t.Fatalf("entries not ascending at %d", i)
		}
	}
	if entries[0].Seq <= aligned-2 {
		t.Fatalf("entries[0].Seq = %d, want > %d", entries[0].Seq, aligned-2)
	}

	// since = after-1：只返回最后一条，不 truncated
	entries, truncated = Since(after - 1)
	if truncated || len(entries) != 1 || entries[0].Seq != after {
		t.Fatalf("Since(after-1) = %d entries, truncated=%v; want 1, false", len(entries), truncated)
	}
}
