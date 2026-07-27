// SPDX-License-Identifier: GPL-3.0-or-later

package main

import (
	"fmt"
	"os"
)

func main() {
	if err := runService(); err != nil {
		fmt.Fprintln(os.Stderr, "ElephantNetworkService failed:", err)
		os.Exit(1)
	}
}
