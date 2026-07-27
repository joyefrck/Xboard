// SPDX-License-Identifier: GPL-3.0-or-later

package main

import (
	"context"
	"strings"
	"testing"
)

func TestSingBoxFactoryRejectsUnknownFieldSafely(t *testing.T) {
	factory := singBoxFactory{}
	_, err := factory.New(
		context.Background(),
		[]byte(`{"inbounds":[],"unknown_field":true}`),
		t.TempDir(),
	)
	if err == nil {
		t.Fatal("expected unknown field error")
	}
	failure := classifyCoreError(err)
	if failure.Code != "core_config_invalid" {
		t.Fatalf("unexpected failure: %#v", failure)
	}
	if strings.Contains(failure.Message, "unknown_field") {
		t.Fatalf("unsafe decoder detail leaked: %q", failure.Message)
	}
}

func TestSingBoxFactoryVersionIsPinned(t *testing.T) {
	if got := (singBoxFactory{}).Version(); got != bundledSingBoxVersion {
		t.Fatalf("version = %q", got)
	}
}

func TestSingBoxFactoryConstructsMinimalCore(t *testing.T) {
	factory := singBoxFactory{}
	instance, err := factory.New(
		context.Background(),
		[]byte(`{"log":{"disabled":true},"inbounds":[],"outbounds":[{"type":"direct","tag":"direct"}],"route":{"final":"direct"}}`),
		t.TempDir(),
	)
	if err != nil {
		t.Fatal(err)
	}
	if err = instance.Close(); err != nil {
		t.Fatal(err)
	}
}
