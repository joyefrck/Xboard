// SPDX-License-Identifier: GPL-3.0-or-later

package main

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
)

const (
	protocolVersion = 1
	maxConfigBytes  = 4 * 1024 * 1024
	maxMessageBytes = 5 * 1024 * 1024
)

var (
	errMessageTooLarge     = errors.New("message exceeds IPC limit")
	errUnsupportedProtocol = errors.New("unsupported IPC protocol")
	errMethodNotAllowed    = errors.New("IPC method is not allowed")
	errInvalidRequest      = errors.New("invalid IPC request")
)

var allowedMethods = map[string]struct{}{
	"getStatus":         {},
	"getNetworkProfile": {},
	"start":             {},
	"prepareSpeedTest":  {},
	"stop":              {},
	"stopSpeedTest":     {},
	"urlTest":           {},
	"selectOutbound":    {},
	"startLatencyTest":  {},
	"getLatencyTest":    {},
	"cancelLatencyTest": {},
}

type requestArguments struct {
	Config       string `json:"config,omitempty"`
	GroupTag     string `json:"group_tag,omitempty"`
	OutboundTag  string `json:"outbound_tag,omitempty"`
	NodeTagsJSON string `json:"node_tags_json,omitempty"`
	TestURL      string `json:"test_url,omitempty"`
	TimeoutMS    int    `json:"timeout_ms,omitempty"`
	Concurrency  int    `json:"concurrency,omitempty"`
	RunID        string `json:"run_id,omitempty"`
}

type requestEnvelope struct {
	Version   int              `json:"version"`
	Method    string           `json:"method"`
	Arguments requestArguments `json:"arguments"`
}

type request struct {
	Version      int
	Method       string
	Config       string
	GroupTag     string
	OutboundTag  string
	NodeTagsJSON string
	TestURL      string
	TimeoutMS    int
	Concurrency  int
	RunID        string
}

type response struct {
	Status             string         `json:"status"`
	Mode               string         `json:"mode,omitempty"`
	UpSpeed            int64          `json:"up_speed,omitempty"`
	DownSpeed          int64          `json:"down_speed,omitempty"`
	TotalUp            int64          `json:"total_up,omitempty"`
	TotalDown          int64          `json:"total_down,omitempty"`
	CoreVersion        string         `json:"core_version,omitempty"`
	CorePID            int            `json:"core_pid,omitempty"`
	ErrorCode          string         `json:"error_code,omitempty"`
	ErrorMessage       string         `json:"error_message,omitempty"`
	DefaultInterface   string         `json:"default_interface,omitempty"`
	TunIPv4Address     string         `json:"tun_ipv4_address,omitempty"`
	StrictRoute        bool           `json:"strict_route,omitempty"`
	Delay              *int           `json:"delay,omitempty"`
	LatencyMap         map[string]int `json:"latency_map,omitempty"`
	RunID              string         `json:"run_id,omitempty"`
	LatencyTestStatus  string         `json:"latency_test_status,omitempty"`
	LatencyCompleted   int            `json:"latency_completed,omitempty"`
	LatencyTotal       int            `json:"latency_total,omitempty"`
	LatencyResultsJSON string         `json:"latency_results_json,omitempty"`
}

func decodeRequest(payload []byte) (request, error) {
	if len(payload) == 0 || len(payload) > maxMessageBytes {
		if len(payload) > maxMessageBytes {
			return request{}, errMessageTooLarge
		}
		return request{}, errInvalidRequest
	}
	decoder := json.NewDecoder(bytes.NewReader(payload))
	decoder.DisallowUnknownFields()
	var envelope requestEnvelope
	if err := decoder.Decode(&envelope); err != nil {
		return request{}, fmt.Errorf("%w: decode", errInvalidRequest)
	}
	if err := ensureJSONEOF(decoder); err != nil {
		return request{}, errInvalidRequest
	}
	if envelope.Version != protocolVersion {
		return request{}, errUnsupportedProtocol
	}
	if _, allowed := allowedMethods[envelope.Method]; !allowed {
		return request{}, errMethodNotAllowed
	}
	if len([]byte(envelope.Arguments.Config)) > maxConfigBytes {
		return request{}, errMessageTooLarge
	}
	return request{
		Version:      envelope.Version,
		Method:       envelope.Method,
		Config:       envelope.Arguments.Config,
		GroupTag:     envelope.Arguments.GroupTag,
		OutboundTag:  envelope.Arguments.OutboundTag,
		NodeTagsJSON: envelope.Arguments.NodeTagsJSON,
		TestURL:      envelope.Arguments.TestURL,
		TimeoutMS:    envelope.Arguments.TimeoutMS,
		Concurrency:  envelope.Arguments.Concurrency,
		RunID:        envelope.Arguments.RunID,
	}, nil
}

func ensureJSONEOF(decoder *json.Decoder) error {
	var trailing any
	err := decoder.Decode(&trailing)
	if errors.Is(err, io.EOF) {
		return nil
	}
	return errInvalidRequest
}

func safeProtocolError(err error) response {
	switch {
	case errors.Is(err, errMessageTooLarge):
		return errorResponse("protocol_error", "Windows service request is too large.")
	case errors.Is(err, errUnsupportedProtocol):
		return errorResponse("protocol_error", "Windows service protocol is unsupported.")
	case errors.Is(err, errMethodNotAllowed):
		return errorResponse("protocol_error", "Windows service method is not allowed.")
	default:
		return errorResponse("protocol_error", "Windows service request is invalid.")
	}
}

func errorResponse(code, message string) response {
	return response{Status: statusError, ErrorCode: code, ErrorMessage: message}
}
