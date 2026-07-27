// SPDX-License-Identifier: GPL-3.0-or-later

//go:build windows

package main

import (
	"testing"
	"unsafe"
)

func TestNativeIPForwardRow2Layout(t *testing.T) {
	if size := unsafe.Sizeof(nativeIPForwardRow2{}); size != 112 {
		t.Fatalf("MIB_IPFORWARD_ROW2 size = %d, want 112", size)
	}
	if offset := unsafe.Offsetof(nativeIPForwardRow2{}.DestinationPrefix); offset != 16 {
		t.Fatalf("DestinationPrefix offset = %d, want 16", offset)
	}
}

func TestDetectWindowsNetworkProfileOnRunner(t *testing.T) {
	profile, err := detectWindowsNetworkProfile()
	if err != nil {
		t.Fatal(err)
	}
	if profile.DefaultInterface == "" || profile.TunIPv4Address == "" {
		t.Fatalf("incomplete profile: %#v", profile)
	}
}
