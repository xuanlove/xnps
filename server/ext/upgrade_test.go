package ext

import (
	"crypto/sha256"
	"encoding/hex"
	"io/ioutil"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
)

func TestValidateBinaryAcceptsCurrentExecutable(t *testing.T) {
	exe, err := os.Executable()
	if err != nil {
		t.Fatal(err)
	}
	if err := validateBinary(exe); err != nil {
		t.Fatalf("current test binary should be valid: %v", err)
	}
}

func TestValidateBinaryRejectsNonExecutable(t *testing.T) {
	path := filepath.Join(t.TempDir(), "not-a-binary")
	if err := ioutil.WriteFile(path, []byte("this is not an executable payload"), 0644); err != nil {
		t.Fatal(err)
	}
	if err := validateBinary(path); err == nil {
		t.Fatal("expected non-executable content to be rejected")
	}
}

func TestDownloadToFileStreamsAndHashes(t *testing.T) {
	content := []byte("fake-binary-content-for-download-test")
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write(content)
	}))
	defer server.Close()

	path := filepath.Join(t.TempDir(), "downloaded")
	digest, err := downloadToFileWithClient(server.Client(), server.URL, path)
	if err != nil {
		t.Fatal(err)
	}

	sum := sha256.Sum256(content)
	if digest != hex.EncodeToString(sum[:]) {
		t.Fatalf("digest mismatch: %s", digest)
	}
	got, err := ioutil.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if string(got) != string(content) {
		t.Fatal("downloaded content mismatch")
	}
}

func TestDownloadToFileRejectsHTTPError(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, "nope", http.StatusForbidden)
	}))
	defer server.Close()

	path := filepath.Join(t.TempDir(), "downloaded")
	if _, err := downloadToFileWithClient(server.Client(), server.URL, path); err == nil {
		t.Fatal("expected HTTP error to be rejected")
	}
}

func TestDownloadToFileRejectsPlainHTTP(t *testing.T) {
	path := filepath.Join(t.TempDir(), "downloaded")
	if _, err := downloadToFile("http://example.com/nps", path); err == nil {
		t.Fatal("expected plain http url to be rejected")
	}
}

func TestIsBlockedIP(t *testing.T) {
	blocked := []string{"127.0.0.1", "10.0.0.1", "192.168.1.1", "169.254.169.254", "::1", "fc00::1"}
	for _, raw := range blocked {
		if !isBlockedIP(net.ParseIP(raw)) {
			t.Fatalf("expected %s to be blocked", raw)
		}
	}
	if isBlockedIP(net.ParseIP("1.1.1.1")) {
		t.Fatal("public ip should not be blocked")
	}
}
