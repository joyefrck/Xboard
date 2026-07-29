// SPDX-License-Identifier: GPL-3.0-or-later

package main

import (
	"context"
	"net"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"

	M "github.com/sagernet/sing/common/metadata"
)

type countingLatencyDialer struct {
	dials  atomic.Int32
	dialer net.Dialer
}

func (dialer *countingLatencyDialer) DialContext(
	ctx context.Context,
	network string,
	destination M.Socksaddr,
) (net.Conn, error) {
	dialer.dials.Add(1)
	return dialer.dialer.DialContext(ctx, network, destination.String())
}

func TestConnectionLatencyProbeReusesConnectionAndSelectsMinimum(t *testing.T) {
	var requests atomic.Int32
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, _ *http.Request) {
		if requests.Add(1) == 1 {
			time.Sleep(80 * time.Millisecond)
		}
		writer.WriteHeader(http.StatusNoContent)
	}))
	defer server.Close()

	dialer := &countingLatencyDialer{}
	probe := newConnectionLatencyProbe(func(tag string) (latencyDialer, bool) {
		return dialer, tag == "Tokyo"
	})
	result := probe.Probe(context.Background(), "Tokyo", server.URL, time.Second)

	if !result.Success() || len(result.Attempts) != 2 {
		t.Fatalf("unexpected result: %#v", result)
	}
	if result.Attempts[1] >= result.Attempts[0] {
		t.Fatalf("expected warm transfer to be lower: %#v", result.Attempts)
	}
	if result.LatencyMS != result.Attempts[1] {
		t.Fatalf("expected minimum latency: %#v", result)
	}
	if dialer.dials.Load() != 1 {
		t.Fatalf("expected one reused TCP connection, got %d", dialer.dials.Load())
	}
}

func TestConnectionLatencyProbeKeepsOneValidAttempt(t *testing.T) {
	var requests atomic.Int32
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, _ *http.Request) {
		if requests.Add(1) == 1 {
			writer.WriteHeader(http.StatusServiceUnavailable)
			return
		}
		writer.WriteHeader(http.StatusNoContent)
	}))
	defer server.Close()

	probe := newConnectionLatencyProbe(func(string) (latencyDialer, bool) {
		return &countingLatencyDialer{}, true
	})
	result := probe.Probe(context.Background(), "Tokyo", server.URL, time.Second)

	if !result.Success() || result.Attempts[0] != -1 || result.Attempts[1] <= 0 {
		t.Fatalf("unexpected partial success: %#v", result)
	}
	if len(result.HTTPStatusCodes) != 2 ||
		result.HTTPStatusCodes[0] != http.StatusServiceUnavailable ||
		result.HTTPStatusCodes[1] != http.StatusNoContent {
		t.Fatalf("unexpected statuses: %#v", result.HTTPStatusCodes)
	}
}

func TestConnectionLatencyProbeClassifiesTimeout(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, _ *http.Request) {
		time.Sleep(100 * time.Millisecond)
		writer.WriteHeader(http.StatusNoContent)
	}))
	defer server.Close()

	probe := newConnectionLatencyProbe(func(string) (latencyDialer, bool) {
		return &countingLatencyDialer{}, true
	})
	result := probe.Probe(
		context.Background(),
		"Tokyo",
		server.URL,
		10*time.Millisecond,
	)

	if result.Success() || result.FailureKind != latencyFailureTimeout {
		t.Fatalf("unexpected timeout result: %#v", result)
	}
}

func TestConnectionLatencyProbeRejectsMissingOutbound(t *testing.T) {
	probe := newConnectionLatencyProbe(func(string) (latencyDialer, bool) {
		return nil, false
	})
	result := probe.Probe(
		context.Background(),
		"missing",
		"https://www.gstatic.com/generate_204",
		time.Second,
	)

	if result.Success() || result.FailureKind != latencyFailureService {
		t.Fatalf("unexpected missing-outbound result: %#v", result)
	}
}
