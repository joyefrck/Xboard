// SPDX-License-Identifier: GPL-3.0-or-later

package main

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"sync/atomic"
	"testing"
	"time"
)

type fakeCoreFactory struct {
	instance *fakeCore
	newError error
	starts   atomic.Int32
	closes   atomic.Int32
}

func (factory *fakeCoreFactory) New(context.Context, []byte, string) (coreInstance, error) {
	if factory.newError != nil {
		return nil, factory.newError
	}
	if factory.instance == nil {
		factory.instance = &fakeCore{factory: factory}
	}
	return factory.instance, nil
}

func (*fakeCoreFactory) Version() string {
	return "sing-box test"
}

type fakeCore struct {
	factory    *fakeCoreFactory
	startError error
	blockStart bool
	closed     chan struct{}
}

func (core *fakeCore) Start() error {
	core.factory.starts.Add(1)
	if core.blockStart {
		if core.closed == nil {
			core.closed = make(chan struct{})
		}
		<-core.closed
	}
	return core.startError
}

func (core *fakeCore) Close() error {
	if core.factory.closes.Add(1) == 1 && core.closed != nil {
		close(core.closed)
	}
	return nil
}

func TestManagerStartStopAndRepeatedStop(t *testing.T) {
	factory := &fakeCoreFactory{}
	manager := testCoreManager(t, factory, time.Second)
	result := manager.start(context.Background(), validTunConfig, false)
	if result.Status != statusConnected || factory.starts.Load() != 1 {
		t.Fatalf("unexpected start result: %#v starts=%d", result, factory.starts.Load())
	}
	if result.CorePID != os.Getpid() {
		t.Fatalf("core PID = %d, want service PID %d", result.CorePID, os.Getpid())
	}
	manager.stop()
	manager.stop()
	if factory.closes.Load() != 1 || manager.snapshot().Status != statusDisconnected {
		t.Fatalf("stop was not idempotent: closes=%d state=%#v", factory.closes.Load(), manager.snapshot())
	}
}

func TestManagerCancelsBlockedStart(t *testing.T) {
	factory := &fakeCoreFactory{}
	factory.instance = &fakeCore{factory: factory, blockStart: true, closed: make(chan struct{})}
	manager := testCoreManager(t, factory, 25*time.Millisecond)
	result := manager.start(context.Background(), validTunConfig, false)
	if result.ErrorCode != "core_start_timeout" || factory.closes.Load() != 1 {
		t.Fatalf("unexpected timeout result: %#v closes=%d", result, factory.closes.Load())
	}
}

func TestManagerRedactsFactoryErrors(t *testing.T) {
	factory := &fakeCoreFactory{newError: errors.New(`decode outbound password="secret"`)}
	manager := testCoreManager(t, factory, time.Second)
	result := manager.start(context.Background(), validTunConfig, false)
	if result.ErrorCode != "core_config_invalid" || result.ErrorMessage != "sing-box rejected the configuration." {
		t.Fatalf("unexpected safe error: %#v", result)
	}
	stopped := manager.stop()
	if stopped.Status != statusDisconnected || stopped.ErrorCode != "" {
		t.Fatalf("stop did not clear error state: %#v", stopped)
	}
}

func testCoreManager(t *testing.T, factory coreFactory, timeout time.Duration) *coreManager {
	t.Helper()
	assets := t.TempDir()
	writeTestFile(t, filepath.Join(assets, "geoip-cn.srs"), "geoip")
	writeTestFile(t, filepath.Join(assets, "geosite-cn.srs"), "geosite")
	return newCoreManager(factory, timeout, assets, t.TempDir(), nil)
}

var validTunConfig = []byte(`{"inbounds":[{"type":"tun","tag":"tun-in","address":["172.31.255.1/30"]}],"outbounds":[{"type":"direct","tag":"direct"}],"route":{"final":"direct"}}`)
