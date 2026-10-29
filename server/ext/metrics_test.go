package ext

import "testing"

func TestTrackGoroutinePeakIsMonotonic(t *testing.T) {
	// 重置包级峰值，避免其他用例污染
	goroutinePeak = 0

	if got := trackGoroutinePeak(10); got != 10 {
		t.Fatalf("peak=%d, want 10", got)
	}
	if got := trackGoroutinePeak(5); got != 10 {
		t.Fatalf("peak=%d, want 10 (decrease must not lower peak)", got)
	}
	if got := trackGoroutinePeak(42); got != 42 {
		t.Fatalf("peak=%d, want 42", got)
	}
}

func TestOpenFDCountNonNegative(t *testing.T) {
	if got := openFDCount(); got < 0 {
		t.Fatalf("open fd count=%d, want >= 0", got)
	}
}
