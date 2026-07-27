// SPDX-License-Identifier: GPL-3.0-or-later

package main

const (
	statusDisconnected  = "disconnected"
	statusDisconnecting = "disconnecting"
	statusCoreStarting  = "core_starting"
	statusConnected     = "connected"
	statusError         = "error"
)

type runtimeState struct {
	Status       string
	CoreVersion  string
	CorePID      int
	ErrorCode    string
	ErrorMessage string
}

func initialRuntimeState(version string) runtimeState {
	return runtimeState{Status: statusDisconnected, CoreVersion: version}
}

func (state runtimeState) response() response {
	return response{
		Status:       state.Status,
		Mode:         "tun",
		CoreVersion:  state.CoreVersion,
		CorePID:      state.CorePID,
		ErrorCode:    state.ErrorCode,
		ErrorMessage: state.ErrorMessage,
	}
}
