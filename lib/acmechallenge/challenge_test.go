package acmechallenge

import (
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestServeChallenge(t *testing.T) {
	Put("token-abc", "token-abc.key-auth")
	defer Delete("token-abc")

	req := httptest.NewRequest(http.MethodGet, "/.well-known/acme-challenge/token-abc", nil)
	rec := httptest.NewRecorder()
	if !Serve(rec, req) {
		t.Fatal("expected challenge path to be handled")
	}
	if rec.Code != http.StatusOK || rec.Body.String() != "token-abc.key-auth" {
		t.Fatalf("unexpected response: %d %q", rec.Code, rec.Body.String())
	}
}

func TestServeUnknownToken(t *testing.T) {
	req := httptest.NewRequest(http.MethodGet, "/.well-known/acme-challenge/missing", nil)
	rec := httptest.NewRecorder()
	if !Serve(rec, req) {
		t.Fatal("expected challenge path to be handled")
	}
	if rec.Code != http.StatusNotFound {
		t.Fatalf("expected 404, got %d", rec.Code)
	}
}

func TestServeIgnoresOtherPaths(t *testing.T) {
	req := httptest.NewRequest(http.MethodGet, "/other", nil)
	rec := httptest.NewRecorder()
	if Serve(rec, req) {
		t.Fatal("expected non-challenge path to be ignored")
	}
}
