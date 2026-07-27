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
