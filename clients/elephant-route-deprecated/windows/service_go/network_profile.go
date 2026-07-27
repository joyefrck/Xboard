// SPDX-License-Identifier: GPL-3.0-or-later

package main

import (
	"errors"
	"net/netip"
	"strings"
)

var errWindowsOnly = errors.New("Windows network profile is available only on Windows")

var tunCandidates = []netip.Prefix{
	netip.MustParsePrefix("172.31.255.0/30"),
	netip.MustParsePrefix("172.30.255.0/30"),
	netip.MustParsePrefix("198.18.0.0/30"),
	netip.MustParsePrefix("10.255.255.0/30"),
}

type routeObservation struct {
	Prefix   netip.Prefix
	Alias    string
	Metric   uint64
	Up       bool
	Hardware bool
	Tunnel   bool
	Loopback bool
}

type networkProfile struct {
	DefaultInterface string
	TunIPv4Address   string
	StrictRoute      bool
}

type profileProvider interface {
	Profile() (networkProfile, error)
}

type windowsProfileProvider struct{}

func (windowsProfileProvider) Profile() (networkProfile, error) {
	return detectWindowsNetworkProfile()
}

func selectNetworkProfile(routes []routeObservation, windowsBuild uint32) (networkProfile, bool) {
	var selected *routeObservation
	for index := range routes {
		candidate := &routes[index]
		if !candidate.Prefix.IsValid() || candidate.Prefix.Bits() != 0 ||
			!candidate.Up || candidate.Tunnel || candidate.Loopback ||
			strings.EqualFold(candidate.Alias, "ElephantNetwork") ||
			strings.TrimSpace(candidate.Alias) == "" {
			continue
		}
		if selected == nil ||
			(candidate.Hardware && !selected.Hardware) ||
			(candidate.Hardware == selected.Hardware && candidate.Metric < selected.Metric) {
			selected = candidate
		}
	}
	if selected == nil {
		return networkProfile{}, false
	}

	tunAddress := ""
	for _, candidate := range tunCandidates {
		overlaps := false
		for _, route := range routes {
			if !route.Prefix.IsValid() || route.Prefix.Bits() == 0 {
				continue
			}
			if route.Prefix.Overlaps(candidate) {
				overlaps = true
				break
			}
		}
		if !overlaps {
			address := candidate.Addr().Next()
			tunAddress = address.String() + "/30"
			break
		}
	}
	if tunAddress == "" {
		return networkProfile{}, false
	}
	return networkProfile{
		DefaultInterface: selected.Alias,
		TunIPv4Address:   tunAddress,
		StrictRoute:      windowsBuild >= 22000,
	}, true
}
