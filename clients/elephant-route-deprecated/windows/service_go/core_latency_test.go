// SPDX-License-Identifier: GPL-3.0-or-later

package main

import (
	"context"
	"sync"
	"testing"
	"time"
)

type fakeLatencyCoreFactory struct {
	core *fakeLatencyCore
}

func (factory *fakeLatencyCoreFactory) New(
	context.Context,
	[]byte,
	string,
) (coreInstance, error) {
	return factory.core, nil
}

func (*fakeLatencyCoreFactory) Version() string {
	return "sing-box latency test"
}

type fakeLatencyCore struct {
	probe     latencyProbeFunc
	started   chan struct{}
	closed    chan struct{}
	startOnce sync.Once
	closeOnce sync.Once
	onClose   func()
}

func (core *fakeLatencyCore) Start() error {
	core.startOnce.Do(func() { close(core.started) })
	return nil
}

func (core *fakeLatencyCore) Close() error {
	core.closeOnce.Do(func() {
		if core.onClose != nil {
			core.onClose()
		}
		close(core.closed)
	})
	return nil
}

func (core *fakeLatencyCore) LatencyProbe() latencyProbeFunc {
	return core.probe
}

func TestCoreManagerRunsLatencyOnlyOnConnectedCore(t *testing.T) {
	core := &fakeLatencyCore{
		started: make(chan struct{}),
		closed:  make(chan struct{}),
		probe: func(
			_ context.Context,
			nodeTag string,
			_ string,
			_ time.Duration,
		) latencyNodeResult {
			if nodeTag != "Tokyo" {
				t.Fatalf("unexpected node tag %q", nodeTag)
			}
			return latencyNodeResult{
				LatencyMS: 24,
				Attempts:  []int{40, 24},
			}
		},
	}
	manager := testCoreManager(
		t,
		&fakeLatencyCoreFactory{core: core},
		time.Second,
	)
	if result := manager.start(context.Background(), validTunConfig, false); result.Status != statusConnected {
		t.Fatalf("core did not connect: %#v", result)
	}

	started, failure := manager.startLatencyTest(
		context.Background(),
		validLatencyJobRequest("Tokyo"),
	)
	if failure != nil {
		t.Fatalf("connected latency rejected: %#v", failure)
	}
	final := waitForLatencyJob(t, manager.latency, started.RunID)
	if final.Status != latencyJobCompleted ||
		final.Results["Tokyo"].LatencyMS != 24 {
		t.Fatalf("unexpected latency result: %#v", final)
	}
}

func TestCoreManagerKeepsRunningWhenOneLatencyNodeIsUnavailable(t *testing.T) {
	core := &fakeLatencyCore{
		started: make(chan struct{}),
		closed:  make(chan struct{}),
		probe: func(
			_ context.Context,
			nodeTag string,
			_ string,
			_ time.Duration,
		) latencyNodeResult {
			if nodeTag == "missing" {
				return latencyNodeResult{
					LatencyMS:   -1,
					Attempts:    []int{-1, -1},
					FailureKind: latencyFailureService,
				}
			}
			return latencyNodeResult{
				LatencyMS: 24,
				Attempts:  []int{40, 24},
			}
		},
	}
	manager := testCoreManager(
		t,
		&fakeLatencyCoreFactory{core: core},
		time.Second,
	)
	if result := manager.start(context.Background(), validTunConfig, false); result.Status != statusConnected {
		t.Fatalf("core did not connect: %#v", result)
	}

	request := validLatencyJobRequest("known")
	request.NodeTags = []string{"known", "missing"}
	started, failure := manager.startLatencyTest(context.Background(), request)
	if failure != nil {
		t.Fatalf("latency batch was rejected: %#v", failure)
	}
	final := waitForLatencyJob(t, manager.latency, started.RunID)
	if final.Status != latencyJobCompleted {
		t.Fatalf("latency batch did not complete: %#v", final)
	}
	if result := final.Results["known"]; result.LatencyMS != 24 || !result.Success() {
		t.Fatalf("known node did not succeed: %#v", result)
	}
	if result := final.Results["missing"]; result.FailureKind != latencyFailureService {
		t.Fatalf("missing node did not fail independently: %#v", result)
	}
}

func TestCoreManagerRejectsLatencyWhileDisconnected(t *testing.T) {
	manager := testCoreManager(t, &fakeCoreFactory{}, time.Second)
	_, failure := manager.startLatencyTest(
		context.Background(),
		validLatencyJobRequest("Tokyo"),
	)
	if failure == nil || failure.Code != "latency_unavailable" {
		t.Fatalf("unexpected failure: %#v", failure)
	}
}

func TestCoreManagerCancelsLatencyBeforeClosingCore(t *testing.T) {
	probeStarted := make(chan struct{})
	var probeOnce sync.Once
	var core *fakeLatencyCore
	core = &fakeLatencyCore{
		started: make(chan struct{}),
		closed:  make(chan struct{}),
		probe: func(
			ctx context.Context,
			_ string,
			_ string,
			_ time.Duration,
		) latencyNodeResult {
			probeOnce.Do(func() { close(probeStarted) })
			<-ctx.Done()
			return latencyNodeResult{
				LatencyMS:   -1,
				Attempts:    []int{-1},
				FailureKind: latencyFailureCancelled,
			}
		},
	}
	manager := testCoreManager(
		t,
		&fakeLatencyCoreFactory{core: core},
		time.Second,
	)
	manager.start(context.Background(), validTunConfig, false)
	started, failure := manager.startLatencyTest(
		context.Background(),
		validLatencyJobRequest("Tokyo"),
	)
	if failure != nil {
		t.Fatal(failure)
	}
	var statusAtClose latencyJobStatus
	core.onClose = func() {
		statusAtClose = manager.latency.Snapshot(started.RunID).Status
	}
	<-probeStarted
	manager.stop()

	if snapshot := manager.latency.Snapshot(started.RunID); snapshot.Status != latencyJobCancelled {
		t.Fatalf("latency was not cancelled: %#v", snapshot)
	}
	select {
	case <-core.closed:
	default:
		t.Fatal("core was not closed")
	}
	if statusAtClose != latencyJobCancelled {
		t.Fatalf("core closed while latency status was %s", statusAtClose)
	}
}
