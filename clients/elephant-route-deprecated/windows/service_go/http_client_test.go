// SPDX-License-Identifier: GPL-3.0-or-later

package main

import (
	"context"
	"net/url"
	"testing"
	"time"
)

type staticProfileProvider struct {
	profile networkProfile
	err     error
}

func (provider staticProfileProvider) Profile() (networkProfile, error) {
	return provider.profile, provider.err
}

type recordingClashController struct {
	group    string
	outbound string
	delay    int
}

func (controller *recordingClashController) URLTest(_ context.Context, group string) (int, error) {
	controller.group = group
	return controller.delay, nil
}

func (controller *recordingClashController) SelectOutbound(
	_ context.Context,
	group string,
	outbound string,
) error {
	controller.group = group
	controller.outbound = outbound
	return nil
}

func TestDispatcherPreservesTagsAsData(t *testing.T) {
	factory := &fakeCoreFactory{}
	manager := testCoreManager(t, factory, time.Second)
	clash := &recordingClashController{delay: 42}
	dispatcher := newDispatcher(
		manager,
		staticProfileProvider{profile: networkProfile{
			DefaultInterface: "Ethernet",
			TunIPv4Address:   "172.31.255.1/30",
		}},
		clash,
	)
	group := `select/../../secret?query=yes`
	result := dispatcher.handle(context.Background(), request{
		Version:  protocolVersion,
		Method:   "urlTest",
		GroupTag: group,
	})
	if result.Status != statusConnected || result.Delay == nil || *result.Delay != 42 {
		t.Fatalf("unexpected result: %#v", result)
	}
	if clash.group != group {
		t.Fatalf("group was altered: %q", clash.group)
	}
	escaped := url.PathEscape(group)
	if escaped == group {
		t.Fatalf("test group was not escapable: %q", group)
	}
}

func TestDispatcherStartsCoreOnlyAfterNetworkProfile(t *testing.T) {
	factory := &fakeCoreFactory{}
	manager := testCoreManager(t, factory, time.Second)
	dispatcher := newDispatcher(
		manager,
		staticProfileProvider{err: errWindowsOnly},
		&recordingClashController{},
	)
	result := dispatcher.handle(context.Background(), request{
		Version: protocolVersion,
		Method:  "start",
		Config:  string(validTunConfig),
	})
	if result.ErrorCode != "default_interface_missing" || factory.starts.Load() != 0 {
		t.Fatalf("unexpected result: %#v starts=%d", result, factory.starts.Load())
	}
}

func TestDispatcherStartsSnapshotsAndCancelsLatencyJob(t *testing.T) {
	core := &fakeLatencyCore{
		started: make(chan struct{}),
		closed:  make(chan struct{}),
		probe: func(
			context.Context,
			string,
			string,
			time.Duration,
		) latencyNodeResult {
			return latencyNodeResult{
				LatencyMS: 42,
				Attempts:  []int{95, 42},
			}
		},
	}
	manager := testCoreManager(
		t,
		&fakeLatencyCoreFactory{core: core},
		time.Second,
	)
	manager.start(context.Background(), validTunConfig, false)
	dispatcher := newDispatcher(
		manager,
		staticProfileProvider{profile: networkProfile{
			DefaultInterface: "Ethernet",
			TunIPv4Address:   "172.31.255.1/30",
		}},
		&recordingClashController{},
	)

	started := dispatcher.handle(context.Background(), request{
		Version:      protocolVersion,
		Method:       "startLatencyTest",
		NodeTagsJSON: `["Tokyo"]`,
		TestURL:      "https://www.gstatic.com/generate_204",
		TimeoutMS:    5000,
		Concurrency:  1,
	})
	if started.RunID == "" || started.LatencyTestStatus == "" {
		t.Fatalf("missing latency job identity: %#v", started)
	}
	snapshot := dispatcher.handle(context.Background(), request{
		Version: protocolVersion,
		Method:  "getLatencyTest",
		RunID:   started.RunID,
	})
	if snapshot.RunID != started.RunID {
		t.Fatalf("wrong snapshot: %#v", snapshot)
	}
	cancelled := dispatcher.handle(context.Background(), request{
		Version: protocolVersion,
		Method:  "cancelLatencyTest",
		RunID:   started.RunID,
	})
	if cancelled.LatencyTestStatus != string(latencyJobCancelled) &&
		cancelled.LatencyTestStatus != string(latencyJobCompleted) {
		t.Fatalf("job did not reach a terminal state: %#v", cancelled)
	}
}

func TestParseLatencyJobRequestRejectsUnsafeOrUnboundedValues(t *testing.T) {
	valid := request{
		NodeTagsJSON: `["Tokyo"]`,
		TestURL:      "https://www.gstatic.com/generate_204",
		TimeoutMS:    5000,
		Concurrency:  4,
	}
	cases := map[string]request{
		"duplicate node": {
			NodeTagsJSON: `["Tokyo","Tokyo"]`,
			TestURL:      valid.TestURL,
			TimeoutMS:    valid.TimeoutMS,
			Concurrency:  valid.Concurrency,
		},
		"credential URL": {
			NodeTagsJSON: valid.NodeTagsJSON,
			TestURL:      "https://user:password@example.com/probe",
			TimeoutMS:    valid.TimeoutMS,
			Concurrency:  valid.Concurrency,
		},
		"unsupported scheme": {
			NodeTagsJSON: valid.NodeTagsJSON,
			TestURL:      "file:///C:/Windows/win.ini",
			TimeoutMS:    valid.TimeoutMS,
			Concurrency:  valid.Concurrency,
		},
		"short timeout": {
			NodeTagsJSON: valid.NodeTagsJSON,
			TestURL:      valid.TestURL,
			TimeoutMS:    999,
			Concurrency:  valid.Concurrency,
		},
		"excess concurrency": {
			NodeTagsJSON: valid.NodeTagsJSON,
			TestURL:      valid.TestURL,
			TimeoutMS:    valid.TimeoutMS,
			Concurrency:  5,
		},
	}
	for name, candidate := range cases {
		t.Run(name, func(t *testing.T) {
			if _, err := parseLatencyJobRequest(candidate); err == nil {
				t.Fatalf("expected rejection: %#v", candidate)
			}
		})
	}
	if _, err := parseLatencyJobRequest(valid); err != nil {
		t.Fatalf("valid request rejected: %v", err)
	}
}
