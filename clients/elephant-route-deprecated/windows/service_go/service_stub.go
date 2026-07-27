// SPDX-License-Identifier: GPL-3.0-or-later

//go:build !windows

package main

func runService() error {
	return errWindowsOnly
}
