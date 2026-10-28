package ext

import "testing"

func TestNormalizeDomains(t *testing.T) {
	got, err := normalizeDomains([]string{" Example.COM ", "example.com", "a.example.com"})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(got) != 2 || got[0] != "example.com" || got[1] != "a.example.com" {
		t.Fatalf("unexpected domains: %#v", got)
	}
}

func TestNormalizeDomainsRejectsWildcard(t *testing.T) {
	if _, err := normalizeDomains([]string{"*.example.com"}); err == nil {
		t.Fatal("expected wildcard to be rejected")
	}
}

func TestNormalizeDomainsRejectsInvalid(t *testing.T) {
	cases := [][]string{
		{},
		{"http://example.com"},
		{"example.com/path"},
		{"-example.com"},
		{"example"},
		{"a..b"},
	}
	for _, c := range cases {
		if _, err := normalizeDomains(c); err == nil {
			t.Fatalf("expected invalid domains to be rejected: %#v", c)
		}
	}
}

func TestNormalizeDomainsLimit(t *testing.T) {
	input := make([]string, 0, 11)
	for i := 0; i < 11; i++ {
		input = append(input, "a"+string(rune('a'+i))+".example.com")
	}
	if _, err := normalizeDomains(input); err == nil {
		t.Fatal("expected >10 domains to be rejected")
	}
}
