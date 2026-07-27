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
	iphlpapiDLL            = windows.NewLazySystemDLL("iphlpapi.dll")
	procGetIpForwardTable2 = iphlpapiDLL.NewProc("GetIpForwardTable2")
	procFreeMibTable       = iphlpapiDLL.NewProc("FreeMibTable")
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
	routes, _ := readWindowsRoutePrefixes()
	defaults, err := readWindowsAdapterDefaults()
	if err != nil {
		return networkProfile{}, err
	}
	routes = append(routes, defaults...)
	profile, ok := selectNetworkProfile(routes, windows.RtlGetVersion().BuildNumber)
	if !ok {
		return networkProfile{}, errors.New("no usable physical IPv4 default interface or TUN subnet")
	}
	return profile, nil
}

func readWindowsRoutePrefixes() ([]routeObservation, error) {
	var table uintptr
	result, _, _ := procGetIpForwardTable2.Call(
		uintptr(addressFamilyIPv4),
		uintptr(unsafe.Pointer(&table)),
	)
	if result != 0 {
		return nil, syscall.Errno(result)
	}
	if table == 0 {
		return nil, errors.New("empty IPv4 route table")
	}
	defer procFreeMibTable.Call(table)

	count := *(*uint32)(unsafe.Pointer(table))
	rowSize := unsafe.Sizeof(nativeIPForwardRow2{})
	const tableRowsOffset = uintptr(8)
	routes := make([]routeObservation, 0, count)
	for index := uint32(0); index < count; index++ {
		row := (*nativeIPForwardRow2)(unsafe.Pointer(table + tableRowsOffset + uintptr(index)*rowSize))
		prefix, ok := nativeIPv4Prefix(row.DestinationPrefix)
		if !ok {
			continue
		}
		routes = append(routes, routeObservation{Prefix: prefix})
	}
	return routes, nil
}

func readWindowsAdapterDefaults() ([]routeObservation, error) {
	size := uint32(15 * 1024)
	flags := uint32(
		windows.GAA_FLAG_INCLUDE_GATEWAYS |
			windows.GAA_FLAG_SKIP_ANYCAST |
			windows.GAA_FLAG_SKIP_MULTICAST |
			windows.GAA_FLAG_SKIP_DNS_SERVER,
	)
	for attempt := 0; attempt < 3; attempt++ {
		buffer := make([]byte, size)
		first := (*windows.IpAdapterAddresses)(unsafe.Pointer(&buffer[0]))
		err := windows.GetAdaptersAddresses(
			addressFamilyIPv4,
			flags,
			0,
			first,
			&size,
		)
		if errors.Is(err, windows.ERROR_BUFFER_OVERFLOW) {
			continue
		}
		if err != nil {
			return nil, err
		}
		var routes []routeObservation
		for adapter := first; adapter != nil; adapter = adapter.Next {
			if !hasIPv4Gateway(adapter.FirstGatewayAddress) {
				continue
			}
			routes = append(routes, routeObservation{
				Prefix:   netip.MustParsePrefix("0.0.0.0/0"),
				Alias:    windows.UTF16PtrToString(adapter.FriendlyName),
				Metric:   uint64(adapter.Ipv4Metric),
				Up:       adapter.OperStatus == windows.IfOperStatusUp,
				Hardware: isHardwareInterface(adapter.IfType),
				Tunnel:   adapter.IfType == ifTypeTunnel,
				Loopback: adapter.IfType == ifTypeSoftwareLoopback,
			})
		}
		return routes, nil
	}
	return nil, windows.ERROR_BUFFER_OVERFLOW
}

func hasIPv4Gateway(gateway *windows.IpAdapterGatewayAddress) bool {
	for current := gateway; current != nil; current = current.Next {
		ip := current.Address.IP()
		if ip != nil && ip.To4() != nil && !ip.IsUnspecified() {
			return true
		}
	}
	return false
}

func isHardwareInterface(ifType uint32) bool {
	switch ifType {
	case windows.IF_TYPE_ETHERNET_CSMACD,
		windows.IF_TYPE_ISO88025_TOKENRING,
		windows.IF_TYPE_ATM,
		windows.IF_TYPE_IEEE80211,
		windows.IF_TYPE_IEEE1394:
		return true
	default:
		return false
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
