package main

import (
	"encoding/json"
	"os"
	"testing"
)

// The aggregation stack parses these exact keys; a regression here silently
// breaks label extraction for every dialer log line.
func TestNewLoggerEmitsContractKeys(t *testing.T) {
	r, w, err := os.Pipe()
	if err != nil {
		t.Fatal(err)
	}
	defer r.Close()

	newLogger(w).Info("test message", "session_token", "sess-x", "call_id", "42")
	w.Close()

	var line map[string]any
	if err := json.NewDecoder(r).Decode(&line); err != nil {
		t.Fatal(err)
	}

	for _, key := range []string{"ts", "level", "msg", "service", "log_type", "session_token", "call_id"} {
		if _, ok := line[key]; !ok {
			t.Errorf("missing contract key %q in %v", key, line)
		}
	}
	if line["level"] != "info" {
		t.Errorf("level should be lowercase, got %v", line["level"])
	}
	if line["service"] != "dialer-worker" || line["log_type"] != "call_flow" {
		t.Errorf("wrong fixed dims: %v", line)
	}
}
