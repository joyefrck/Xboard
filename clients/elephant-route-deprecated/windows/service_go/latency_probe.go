// SPDX-License-Identifier: GPL-3.0-or-later

package main

import (
	"context"
	"crypto/tls"
	"io"
	"net"
	"net/http"
	"time"

	M "github.com/sagernet/sing/common/metadata"
)

type latencyFailureKind string

const (
	latencyFailureTimeout   latencyFailureKind = "timeout"
	latencyFailureHTTP      latencyFailureKind = "httpError"
	latencyFailureTransport latencyFailureKind = "transportError"
	latencyFailureService   latencyFailureKind = "serviceError"
	latencyFailureCancelled latencyFailureKind = "cancelled"
)

type latencyNodeResult struct {
	LatencyMS       int                `json:"latency_ms"`
	ElapsedMS       int                `json:"elapsed_ms"`
	Attempts        []int              `json:"attempts"`
	FailureKind     latencyFailureKind `json:"failure_kind,omitempty"`
	HTTPStatusCodes []int              `json:"http_status_codes"`
}

func (result latencyNodeResult) Success() bool {
	return result.LatencyMS > 0 && result.FailureKind == ""
}

type latencyDialer interface {
	DialContext(context.Context, string, M.Socksaddr) (net.Conn, error)
}

type latencyDialerResolver func(string) (latencyDialer, bool)

type connectionLatencyProbe struct {
	resolve latencyDialerResolver
}

func newConnectionLatencyProbe(
	resolve latencyDialerResolver,
) *connectionLatencyProbe {
	return &connectionLatencyProbe{resolve: resolve}
}

func (probe *connectionLatencyProbe) Probe(
	parent context.Context,
	nodeTag string,
	testURL string,
	timeout time.Duration,
) latencyNodeResult {
	startedAt := time.Now()
	dialer, found := probe.resolve(nodeTag)
	if !found {
		return latencyNodeResult{
			LatencyMS:   -1,
			ElapsedMS:   int(time.Since(startedAt).Milliseconds()),
			Attempts:    []int{-1, -1},
			FailureKind: latencyFailureService,
		}
	}

	ctx, cancel := context.WithTimeout(parent, timeout)
	defer cancel()
	transport := &http.Transport{
		Proxy: nil,
		DialContext: func(
			ctx context.Context,
			network string,
			address string,
		) (net.Conn, error) {
			return dialer.DialContext(
				ctx,
				network,
				M.ParseSocksaddr(address),
			)
		},
		TLSClientConfig: &tls.Config{
			MinVersion: tls.VersionTLS12,
		},
		DisableKeepAlives: false,
	}
	defer transport.CloseIdleConnections()
	client := &http.Client{Transport: transport}

	attempts := make([]int, 0, 2)
	statuses := make([]int, 0, 2)
	transportFailed := false
	for attempt := 0; attempt < 2; attempt++ {
		attemptStarted := time.Now()
		request, err := http.NewRequestWithContext(
			ctx,
			http.MethodGet,
			testURL,
			nil,
		)
		if err != nil {
			attempts = append(attempts, -1)
			transportFailed = true
			continue
		}
		response, err := client.Do(request)
		if err != nil {
			attempts = append(attempts, -1)
			transportFailed = true
			continue
		}
		_, _ = io.Copy(
			io.Discard,
			io.LimitReader(response.Body, 64*1024),
		)
		_ = response.Body.Close()
		statuses = append(statuses, response.StatusCode)
		if response.StatusCode == http.StatusOK ||
			response.StatusCode == http.StatusNoContent {
			attempts = append(
				attempts,
				max(1, int(time.Since(attemptStarted).Milliseconds())),
			)
		} else {
			attempts = append(attempts, -1)
		}
	}

	best := -1
	for _, latency := range attempts {
		if latency > 0 && (best < 0 || latency < best) {
			best = latency
		}
	}
	result := latencyNodeResult{
		LatencyMS:       best,
		ElapsedMS:       int(time.Since(startedAt).Milliseconds()),
		Attempts:        attempts,
		HTTPStatusCodes: statuses,
	}
	if best > 0 {
		return result
	}
	switch {
	case parent.Err() != nil:
		result.FailureKind = latencyFailureCancelled
	case ctx.Err() != nil:
		result.FailureKind = latencyFailureTimeout
	case len(statuses) > 0:
		result.FailureKind = latencyFailureHTTP
	case transportFailed:
		result.FailureKind = latencyFailureTransport
	default:
		result.FailureKind = latencyFailureService
	}
	return result
}
