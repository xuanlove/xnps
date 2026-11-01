// Command loadtest 是 xnps /ext/* 管理面回归压测工具（I3）。
//
// 用途：以固定并发对 /ext 读端点（version/system/online/list/flow）施压，
// 输出 RPS 与延迟分位，可与基线 JSON 对比，用于发布前回归。
//
// 用法：
//
//	go run ./scripts/loadtest -url http://127.0.0.1:8080 -key <auth_key> \
//	    -duration 60s -concurrency 64 -out baseline.json
//
//	# 与基线对比（p95 回退 > 25% 或 RPS 下降 > 25% 时退出码 1）
//	go run ./scripts/loadtest -url ... -key ... -baseline baseline.json
package main

import (
	"crypto/md5"
	"encoding/hex"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"math/rand"
	"net/http"
	"os"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

type endpoint struct {
	path   string
	weight int
}

var endpoints = []endpoint{
	{"/ext/version", 35},
	{"/ext/online", 20},
	{"/ext/system", 10},
	{"/ext/list?type=client&limit=500", 15},
	{"/ext/list?type=tunnel&limit=500", 10},
	{"/ext/flow?shard=0&total=16", 5},
	{"/ext/changes?since=0", 5},
}

type summary struct {
	At          string  `json:"at"`
	Requests    int64   `json:"requests"`
	Errors      int64   `json:"errors"`
	RPS         float64 `json:"rps"`
	P50Ms       float64 `json:"p50_ms"`
	P95Ms       float64 `json:"p95_ms"`
	P99Ms       float64 `json:"p99_ms"`
	MaxMs       float64 `json:"max_ms"`
	DurationS   float64 `json:"duration_s"`
	Concurrency int     `json:"concurrency"`
}

func main() {
	baseURL := flag.String("url", "http://127.0.0.1:8080", "nps webapi base url")
	authKey := flag.String("key", "", "nps.conf auth_key (required)")
	duration := flag.Duration("duration", 30*time.Second, "test duration")
	concurrency := flag.Int("concurrency", 32, "concurrent workers")
	out := flag.String("out", "", "write summary JSON to this file")
	baseline := flag.String("baseline", "", "compare against baseline JSON")
	maxRegress := flag.Float64("max-regress", 25, "allowed percent regression for p95 and rps")
	flag.Parse()

	if *authKey == "" {
		fmt.Fprintln(os.Stderr, "-key is required")
		os.Exit(2)
	}
	if *concurrency <= 0 || *duration <= 0 {
		fmt.Fprintln(os.Stderr, "-concurrency and -duration must be positive")
		os.Exit(2)
	}

	client := &http.Client{
		Timeout: 30 * time.Second,
		Transport: &http.Transport{
			MaxIdleConns:        *concurrency * 2,
			MaxIdleConnsPerHost: *concurrency * 2,
			IdleConnTimeout:     60 * time.Second,
		},
	}

	// 预生成加重轮询表，避免每请求 rand 开销/锁竞争
	pool := make([]endpoint, 0, 100)
	for _, e := range endpoints {
		for i := 0; i < e.weight; i++ {
			pool = append(pool, e)
		}
	}

	var (
		mu        sync.Mutex
		latencies []float64
		requests  int64
		errors    int64
	)

	deadline := time.Now().Add(*duration)
	var wg sync.WaitGroup
	for w := 0; w < *concurrency; w++ {
		wg.Add(1)
		go func(seed int) {
			defer wg.Done()
			r := rand.New(rand.NewSource(time.Now().UnixNano() + int64(seed)))
			for time.Now().Before(deadline) {
				e := pool[r.Intn(len(pool))]
				code, ms, err := doRequest(client, *baseURL, *authKey, e.path)
				atomic.AddInt64(&requests, 1)
				if err != nil || code != http.StatusOK {
					atomic.AddInt64(&errors, 1)
				}
				mu.Lock()
				latencies = append(latencies, ms)
				mu.Unlock()
			}
		}(w)
	}
	wg.Wait()

	total := atomic.LoadInt64(&requests)
	errCount := atomic.LoadInt64(&errors)
	elapsed := duration.Seconds()
	s := summary{
		At:          time.Now().UTC().Format(time.RFC3339),
		Requests:    total,
		Errors:      errCount,
		DurationS:   elapsed,
		Concurrency: *concurrency,
	}
	if total > 0 {
		s.RPS = float64(total) / elapsed
	}
	sort.Float64s(latencies)
	s.P50Ms = percentile(latencies, 0.50)
	s.P95Ms = percentile(latencies, 0.95)
	s.P99Ms = percentile(latencies, 0.99)
	if len(latencies) > 0 {
		s.MaxMs = latencies[len(latencies)-1]
	}

	enc := json.NewEncoder(os.Stdout)
	enc.SetIndent("", "  ")
	_ = enc.Encode(s)

	if *out != "" {
		if err := writeJSON(*out, s); err != nil {
			fmt.Fprintf(os.Stderr, "write %s: %v\n", *out, err)
			os.Exit(1)
		}
	}

	if *baseline != "" {
		if failed := compare(*baseline, s, *maxRegress); failed {
			os.Exit(1)
		}
	}

	if errCount > 0 && errCount == total {
		os.Exit(1)
	}
}

func doRequest(c *http.Client, baseURL, key, path string) (int, float64, error) {
	ts := time.Now().Unix()
	sum := md5.Sum([]byte(key + strconv.FormatInt(ts, 10)))
	sep := "?"
	if strings.Contains(path, "?") {
		sep = "&"
	}
	url := baseURL + path + sep + "timestamp=" + strconv.FormatInt(ts, 10) + "&auth_key=" + hex.EncodeToString(sum[:])

	req, err := http.NewRequest(http.MethodPost, url, nil)
	if err != nil {
		return 0, 0, err
	}
	start := time.Now()
	resp, err := c.Do(req)
	if err != nil {
		return 0, float64(time.Since(start).Microseconds()) / 1000, err
	}
	defer resp.Body.Close()
	_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 64<<10))

	return resp.StatusCode, float64(time.Since(start).Microseconds()) / 1000, nil
}

func percentile(sorted []float64, p float64) float64 {
	if len(sorted) == 0 {
		return 0
	}
	idx := int(float64(len(sorted)-1) * p)
	return sorted[idx]
}

func writeJSON(path string, s summary) error {
	b, err := json.MarshalIndent(s, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(path, append(b, '\n'), 0644)
}

func compare(path string, cur summary, maxRegress float64) bool {
	b, err := os.ReadFile(path)
	if err != nil {
		fmt.Fprintf(os.Stderr, "read baseline: %v\n", err)
		return true
	}
	var base summary
	if err := json.Unmarshal(b, &base); err != nil {
		fmt.Fprintf(os.Stderr, "parse baseline: %v\n", err)
		return true
	}

	failed := false
	if base.P95Ms > 0 && cur.P95Ms > base.P95Ms*(1+maxRegress/100) {
		fmt.Fprintf(os.Stderr, "REGRESSION: p95 %.2fms -> %.2fms (>%.0f%%)\n", base.P95Ms, cur.P95Ms, maxRegress)
		failed = true
	}
	if base.RPS > 0 && cur.RPS < base.RPS*(1-maxRegress/100) {
		fmt.Fprintf(os.Stderr, "REGRESSION: rps %.2f -> %.2f (>%.0f%% drop)\n", base.RPS, cur.RPS, maxRegress)
		failed = true
	}
	if !failed {
		fmt.Fprintf(os.Stderr, "baseline ok (p95 %.2fms, rps %.2f)\n", base.P95Ms, base.RPS)
	}

	return failed
}
