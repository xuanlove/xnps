package ext

import (
	"path/filepath"
	"testing"

	"ehang.io/nps/lib/common"
)

func TestSafeConfPath(t *testing.T) {
	okPath := filepath.Join(common.GetRunPath(), "conf", "web_cert.pem")
	if _, err := safeConfPath(okPath, "web_cert_file"); err != nil {
		t.Fatalf("conf path should be allowed: %v", err)
	}

	for _, p := range []string{"/etc/passwd", "../../etc/passwd", filepath.Join(common.GetRunPath(), "web", "x.pem")} {
		if _, err := safeConfPath(p, "web_cert_file"); err == nil {
			t.Fatalf("path %s should be rejected", p)
		}
	}
}

func TestIsSensitiveKey(t *testing.T) {
	cases := map[string]bool{
		"web_password":           true,
		"auth_key":               true,
		"auth_crypt_key":         true,
		"public_vkey":            true,
		"certd_token":            true,
		"web_key_file":           false,
		"https_default_key_file": false,
		"log_level":              false,
	}
	for key, want := range cases {
		if got := isSensitiveKey(key); got != want {
			t.Fatalf("isSensitiveKey(%q)=%v, want %v", key, got, want)
		}
	}
}
