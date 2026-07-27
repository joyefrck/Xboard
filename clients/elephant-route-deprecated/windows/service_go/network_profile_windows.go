// SPDX-License-Identifier: GPL-3.0-or-later

//go:build windows

package main

import (
	"errors"
	"net/netip"
	"syscall"
	"unsafe"

	"golang.org/x/sys/windows"
)

const (
	addressFamilyIPv4      = 2
	ifTypeSoftwareLoopback = 24
	ifTypeTunnel           = 131
)

var (
	iphlpapiDLL             = windows.NewLazySystemDLL("iphlpapi.dll")
	procGetIpForwardTable2  = iphlpapiDLL.NewProc("GetIpForwardTable2")
	procFreeMibTable        = iphlpapiDLL.NewProc("FreeMibTable")
	procGetIpInterfaceEntry = iphlpapiDLL.NewProc("GetIpInterfaceEntry")
)

type nativeIPAddressPrefix struct {
	Address      [28]byte
	PrefixLength uint8
	_            [3]byte
}

type nativeIPForwardRow2 struct {
	InterfaceLuid        uint64
	InterfaceIndex       uint32
	_                    uint32
	DestinationPrefix    nativeIPAddressPrefix
	NextHop              [28]byte
	SitePrefixLength     uint8
	_                    [3]byte
	ValidLifetime        uint32
	PreferredLifetime    uint32
	Metric               uint32
	Protocol             uint32
	Loopback             uint8
	AutoconfigureAddress uint8
	Publish              uint8
	Immortal             uint8
	Age                  uint32
	Origin               uint32
}

func detectWindowsNetworkProfile() (networkProfile, error) {
	var table uintptr
	result, _, _ := procGetIpForwardTable2.Call(
		uintptr(addressFamilyIPv4),
		uintptr(unsafe.Pointer(&table)),
	)
	if result != 0 {
		return networkProfile{}, syscall.Errno(result)
	}
	if table == 0 {
		return networkProfile{}, errors.New("empty IPv4 route table")
	}
	defer procFreeMibTable.Call(table)

	count := *(*uint32)(unsafe.Pointer(table))
	rowSize := unsafe.Sizeof(nativeIPForwardRow2{})
	const tableRowsOffset = uintptr(8)
	routes := make([]routeObservation, 0, count)
	interfaceCache := make(map[uint64]routeInterface)
	for index := uint32(0); index < count; index++ {
		row := (*nativeIPForwardRow2)(unsafe.Pointer(table + tableRowsOffset + uintptr(index)*rowSize))
		prefix, ok := nativeIPv4Prefix(row.DestinationPrefix)
		if !ok {
			continue
		}
		observation := routeObservation{Prefix: prefix, Metric: uint64(row.Metric)}
		if prefix.Bits() == 0 {
			info, found := interfaceCache[row.InterfaceLuid]
			if !found {
				info = readRouteInterface(row.InterfaceLuid)
				interfaceCache[row.InterfaceLuid] = info
			}
			observation.Alias = info.alias
			observation.Up = info.up
			observation.Hardware = info.hardware
			observation.Tunnel = info.tunnel
			observation.Loopback = info.loopback
			observation.Metric += uint64(info.metric)
		}
		routes = append(routes, observation)
	}
	profile, ok := selectNetworkProfile(routes, windows.RtlGetVersion().BuildNumber)
	if !ok {
		return networkProfile{}, errors.New("no usable physical IPv4 default interface or TUN subnet")
	}
	return profile, nil
}

type routeInterface struct {
	alias    string
	metric   uint32
	up       bool
	hardware bool
	tunnel   bool
	loopback bool
}

func readRouteInterface(luid uint64) routeInterface {
	row := windows.MibIfRow2{InterfaceLuid: luid}
	if err := windows.GetIfEntry2Ex(windows.MibIfEntryNormalWithoutStatistics, &row); err != nil {
		return routeInterface{}
	}
	ipRow := windows.MibIpInterfaceRow{
		Family:        addressFamilyIPv4,
		InterfaceLuid: luid,
	}
	result, _, _ := procGetIpInterfaceEntry.Call(uintptr(unsafe.Pointer(&ipRow)))
	metric := uint32(0)
	if result == 0 {
		metric = ipRow.Metric
	}
	return routeInterface{
		alias:    windows.UTF16ToString(row.Alias[:]),
		metric:   metric,
		up:       row.OperStatus == windows.IfOperStatusUp,
		hardware: row.InterfaceAndOperStatusFlags&1 != 0,
		tunnel:   row.Type == ifTypeTunnel,
		loopback: row.Type == ifTypeSoftwareLoopback,
	}
}

func nativeIPv4Prefix(prefix nativeIPAddressPrefix) (netip.Prefix, bool) {
	family := *(*uint16)(unsafe.Pointer(&prefix.Address[0]))
	if family != addressFamilyIPv4 || prefix.PrefixLength > 32 {
		return netip.Prefix{}, false
	}
	address := netip.AddrFrom4([4]byte{
		prefix.Address[4],
		prefix.Address[5],
		prefix.Address[6],
		prefix.Address[7],
	})
	return netip.PrefixFrom(address, int(prefix.PrefixLength)).Masked(), true
}
