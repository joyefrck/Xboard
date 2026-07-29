// SPDX-License-Identifier: GPL-3.0-or-later

package main

import (
	"bytes"
	"strings"
	"testing"
	"time"
)

func TestLifecycleLogContainsOnlySafeFields(t *testing.T) {
	var output bytes.Buffer
	logger := newLifecycleLogger(&output)
	logger.transition("core_starting", "core_config_invalid", 250*time.Millisecond)
	got := output.String()
	if !strings.Contains(got, "state=core_starting") || !strings.Contains(got, "elapsed_ms=250") {
		t.Fatalf("missing lifecycle fields: %q", got)
	}
	for _, forbidden := range []string{"uuid", "password", "configuration"} {
		if strings.Contains(got, forbidden) {
			t.Fatalf("lifecycle log leaked %q: %q", forbidden, got)
		}
	}
}

func TestLifecycleLogWritesSanitizedLatencyFields(t *testing.T) {
	var output bytes.Buffer
	logger := newLifecycleLogger(&output)
	logger.event(
		"latency_node",
		"run_id=12345678",
		"node=Tokyo",
		"attempts=[80_42]",
		"failure=",
	)
	got := output.String()
	for _, expected := range []string{
		"event=latency_node",
		"run_id=12345678",
		"node=Tokyo",
		"attempts=[80_42]",
	} {
		if !strings.Contains(got, expected) {
			t.Fatalf("missing latency field %q: %q", expected, got)
		}
	}
}
