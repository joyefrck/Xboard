// SPDX-License-Identifier: GPL-3.0-or-later

package main

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"
)

func TestDecodeRequestRejectsWrongVersion(t *testing.T) {
	_, err := decodeRequest([]byte(`{"version":2,"method":"getStatus","arguments":{}}`))
	if !errors.Is(err, errUnsupportedProtocol) {
		t.Fatalf("expected protocol error, got %v", err)
	}
}

func TestDecodeRequestPreservesNestedEscapedConfig(t *testing.T) {
	raw := []byte(`{"version":1,"method":"start","arguments":{"config":"{\"inbounds\":[{\"type\":\"tun\"}]}"}}`)
	got, err := decodeRequest(raw)
	if err != nil {
		t.Fatal(err)
	}
	if got.Config != `{"inbounds":[{"type":"tun"}]}` {
		t.Fatalf("unexpected config %q", got.Config)
	}
}

func TestDecodeRequestRejectsTopLevelArgumentsAndUnknownFields(t *testing.T) {
	for _, raw := range []string{
		`{"version":1,"method":"start","config":"{}"}`,
		`{"version":1,"method":"getStatus","arguments":{},"extra":true}`,
		`{"version":1,"method":"unknown","arguments":{}}`,
	} {
		if _, err := decodeRequest([]byte(raw)); err == nil {
			t.Fatalf("expected %q to fail", raw)
		}
	}
}

func TestDecodeRequestEnforcesConfigLimit(t *testing.T) {
	payload, err := json.Marshal(requestEnvelope{
		Version: protocolVersion,
		Method:  "start",
		Arguments: requestArguments{
			Config: strings.Repeat("x", maxConfigBytes+1),
		},
	})
	if err != nil {
		t.Fatal(err)
	}
	_, err = decodeRequest(payload)
	if !errors.Is(err, errMessageTooLarge) {
		t.Fatalf("expected size error, got %v", err)
	}
}

func TestErrorResponseDoesNotExposeRawError(t *testing.T) {
	got := errorResponse("core_config_invalid", "sing-box rejected the configuration.")
	encoded, err := json.Marshal(got)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(encoded), "uuid") || strings.Contains(string(encoded), "secret") {
		t.Fatalf("response leaked configuration: %s", encoded)
	}
}

func TestDecodeLatencyJobRequest(t *testing.T) {
	raw := []byte(`{
		"version":1,
		"method":"startLatencyTest",
		"arguments":{
			"node_tags_json":"[\"Tokyo\",\"Osaka\"]",
			"test_url":"https://www.gstatic.com/generate_204",
			"timeout_ms":5000,
			"concurrency":4
		}
	}`)
	request, err := decodeRequest(raw)
	if err != nil {
		t.Fatal(err)
	}
	if request.NodeTagsJSON != `["Tokyo","Osaka"]` ||
		request.TestURL != "https://www.gstatic.com/generate_204" ||
		request.TimeoutMS != 5000 ||
		request.Concurrency != 4 {
		t.Fatalf("unexpected request: %#v", request)
	}
}

func TestDecodeLatencySnapshotRequest(t *testing.T) {
	raw := []byte(`{
		"version":1,
		"method":"getLatencyTest",
		"arguments":{"run_id":"12345678abcdef"}
	}`)
	request, err := decodeRequest(raw)
	if err != nil {
		t.Fatal(err)
	}
	if request.RunID != "12345678abcdef" {
		t.Fatalf("unexpected run ID: %#v", request)
	}
}
