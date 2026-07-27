// SPDX-License-Identifier: GPL-3.0-or-later

package main

import (
	"net/netip"
	"testing"
)

func TestSelectNetworkProfilePrefersHardwareAndAvoidsOverlap(t *testing.T) {
	routes := []routeObservation{
		{Prefix: netip.MustParsePrefix("0.0.0.0/0"), Alias: "VPN", Metric: 1, Up: true, Tunnel: true},
		{Prefix: netip.MustParsePrefix("0.0.0.0/0"), Alias: "Ethernet", Metric: 25, Up: true, Hardware: true},
		{Prefix: netip.MustParsePrefix("172.31.255.0/30"), Alias: "Existing", Up: true},
	}
	profile, ok := selectNetworkProfile(routes, 19045)
	if !ok {
		t.Fatal("profile not selected")
	}
	if profile.DefaultInterface != "Ethernet" ||
		profile.TunIPv4Address != "172.30.255.1/30" ||
		profile.StrictRoute {
		t.Fatalf("unexpected profile: %#v", profile)
	}
}

func TestSelectNetworkProfileUsesLowestMetricAndWindows11StrictRoute(t *testing.T) {
	routes := []routeObservation{
		{Prefix: netip.MustParsePrefix("0.0.0.0/0"), Alias: "Wi-Fi", Metric: 40, Up: true, Hardware: true},
		{Prefix: netip.MustParsePrefix("0.0.0.0/0"), Alias: "Ethernet", Metric: 10, Up: true, Hardware: true},
	}
	profile, ok := selectNetworkProfile(routes, 22631)
	if !ok {
		t.Fatal("profile not selected")
	}
	if profile.DefaultInterface != "Ethernet" || !profile.StrictRoute {
		t.Fatalf("unexpected profile: %#v", profile)
	}
}

func TestSelectNetworkProfileRejectsOnlyVirtualDefaults(t *testing.T) {
	routes := []routeObservation{
		{Prefix: netip.MustParsePrefix("0.0.0.0/0"), Alias: "ElephantNetwork", Up: true, Hardware: true},
		{Prefix: netip.MustParsePrefix("0.0.0.0/0"), Alias: "Loopback", Up: true, Loopback: true},
	}
	if _, ok := selectNetworkProfile(routes, 19045); ok {
		t.Fatal("unexpected profile")
	}
}
