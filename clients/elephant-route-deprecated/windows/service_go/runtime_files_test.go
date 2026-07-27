// SPDX-License-Identifier: GPL-3.0-or-later

package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestPrepareRuntimeCopiesOnlyTrustedAssets(t *testing.T) {
	source := t.TempDir()
	runtimeDirectory := t.TempDir()
	writeTestFile(t, filepath.Join(source, "geoip-cn.srs"), "geoip")
	writeTestFile(t, filepath.Join(source, "geosite-cn.srs"), "geosite")
	writeTestFile(t, filepath.Join(source, "not-trusted.txt"), "secret")
	config := []byte(`{"inbounds":[{"type":"tun"}]}`)
	if err := prepareRuntimeFiles(source, runtimeDirectory, config); err != nil {
		t.Fatal(err)
	}
	assertTestFile(t, filepath.Join(runtimeDirectory, "config.json"), string(config))
	assertTestFile(t, filepath.Join(runtimeDirectory, "geoip-cn.srs"), "geoip")
	if _, err := os.Stat(filepath.Join(runtimeDirectory, "not-trusted.txt")); !os.IsNotExist(err) {
		t.Fatal("untrusted file was copied")
	}
}

func TestPrepareRuntimeRejectsOversizedConfig(t *testing.T) {
	err := prepareRuntimeFiles(t.TempDir(), t.TempDir(), []byte(strings.Repeat("x", maxConfigBytes+1)))
	if err == nil {
		t.Fatal("expected size error")
	}
}

func writeTestFile(t *testing.T, path, content string) {
	t.Helper()
	if err := os.WriteFile(path, []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}
}

func assertTestFile(t *testing.T, path, expected string) {
	t.Helper()
	content, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if string(content) != expected {
		t.Fatalf("%s = %q, want %q", path, content, expected)
	}
}
