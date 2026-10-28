// Package acmechallenge 保存 ACME HTTP-01 挑战令牌，供 nps HTTP 监听器
// 在 /.well-known/acme-challenge/<token> 上应答（内置 ACME 签发用）。
package acmechallenge

import (
	"io"
	"net/http"
	"strings"
	"sync"
)

const prefix = "/.well-known/acme-challenge/"

var store sync.Map // token -> keyAuth

// Put 登记一个待校验令牌
func Put(token, keyAuth string) {
	store.Store(token, keyAuth)
}

// Delete 清理令牌
func Delete(token string) {
	store.Delete(token)
}

// Serve 尝试应答 ACME 挑战；返回 true 表示已处理（无论命中与否）
func Serve(w http.ResponseWriter, r *http.Request) bool {
	if !strings.HasPrefix(r.URL.Path, prefix) {
		return false
	}
	token := strings.TrimPrefix(r.URL.Path, prefix)
	if token == "" || strings.Contains(token, "/") {
		http.NotFound(w, r)
		return true
	}
	v, ok := store.Load(token)
	if !ok {
		http.NotFound(w, r)
		return true
	}
	w.Header().Set("Content-Type", "text/plain")
	w.WriteHeader(http.StatusOK)
	_, _ = io.WriteString(w, v.(string))
	return true
}
