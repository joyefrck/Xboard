// SPDX-License-Identifier: GPL-3.0-or-later

package main

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"sync"
	"time"
	"unicode"
)

type latencyJobStatus string

const (
	latencyJobRunning   latencyJobStatus = "running"
	latencyJobCompleted latencyJobStatus = "completed"
	latencyJobCancelled latencyJobStatus = "cancelled"
	latencyJobError     latencyJobStatus = "error"
)

type latencyJobRequest struct {
	NodeTags    []string
	TestURL     string
	Timeout     time.Duration
	Concurrency int
}

type latencyProbeFunc func(
	context.Context,
	string,
	string,
	time.Duration,
) latencyNodeResult

type latencyJobSnapshot struct {
	RunID     string
	Status    latencyJobStatus
	Completed int
	Total     int
	Results   map[string]latencyNodeResult
	ErrorCode string
}

type latencyRun struct {
	id       string
	cancel   context.CancelFunc
	status   latencyJobStatus
	total    int
	nodeTags []string
	results  map[string]latencyNodeResult
}

type latencyJobManager struct {
	mu       sync.Mutex
	activeID string
	runs     map[string]*latencyRun
	order    []string
	logger   *lifecycleLogger
}

func newLatencyJobManager(logger *lifecycleLogger) *latencyJobManager {
	return &latencyJobManager{
		runs:   make(map[string]*latencyRun),
		logger: logger,
	}
}

func (manager *latencyJobManager) Start(
	parent context.Context,
	request latencyJobRequest,
	probe latencyProbeFunc,
) (latencyJobSnapshot, error) {
	if len(request.NodeTags) == 0 {
		return latencyJobSnapshot{}, errors.New("latency job requires nodes")
	}
	if request.Timeout <= 0 {
		return latencyJobSnapshot{}, errors.New("latency job timeout is invalid")
	}
	if request.Concurrency <= 0 {
		return latencyJobSnapshot{}, errors.New("latency job concurrency is invalid")
	}
	if probe == nil {
		return latencyJobSnapshot{}, errors.New("latency job probe is missing")
	}

	runID, err := newLatencyRunID()
	if err != nil {
		return latencyJobSnapshot{}, err
	}
	ctx, cancel := context.WithCancel(parent)
	run := &latencyRun{
		id:       runID,
		cancel:   cancel,
		status:   latencyJobRunning,
		total:    len(request.NodeTags),
		nodeTags: append([]string(nil), request.NodeTags...),
		results:  make(map[string]latencyNodeResult, len(request.NodeTags)),
	}

	manager.mu.Lock()
	manager.cancelActiveLocked("replaced")
	manager.activeID = runID
	manager.runs[runID] = run
	manager.order = append(manager.order, runID)
	manager.pruneLocked()
	snapshot := manager.snapshotLocked(run)
	manager.mu.Unlock()

	manager.logEvent(
		"latency_start",
		fmt.Sprintf("run_id=%s", latencyRunIDPrefix(runID)),
		fmt.Sprintf("nodes=%d", len(request.NodeTags)),
		fmt.Sprintf("timeout_ms=%d", request.Timeout.Milliseconds()),
		fmt.Sprintf("concurrency=%d", min(4, request.Concurrency)),
	)
	go manager.execute(ctx, run, request, probe)
	return snapshot, nil
}

func (manager *latencyJobManager) Snapshot(runID string) latencyJobSnapshot {
	manager.mu.Lock()
	defer manager.mu.Unlock()
	if runID == "" {
		runID = manager.activeID
	}
	run := manager.runs[runID]
	if run == nil {
		return latencyJobSnapshot{
			RunID:     runID,
			Status:    latencyJobError,
			Results:   map[string]latencyNodeResult{},
			ErrorCode: "latency_run_not_found",
		}
	}
	return manager.snapshotLocked(run)
}

func (manager *latencyJobManager) Cancel(
	runID string,
	reason string,
) latencyJobSnapshot {
	manager.mu.Lock()
	defer manager.mu.Unlock()
	if runID == "" {
		runID = manager.activeID
	}
	run := manager.runs[runID]
	if run == nil {
		return latencyJobSnapshot{
			RunID:     runID,
			Status:    latencyJobError,
			Results:   map[string]latencyNodeResult{},
			ErrorCode: "latency_run_not_found",
		}
	}
	manager.cancelRunLocked(run, reason)
	return manager.snapshotLocked(run)
}

func (manager *latencyJobManager) CancelActive(reason string) {
	manager.mu.Lock()
	defer manager.mu.Unlock()
	manager.cancelActiveLocked(reason)
}

func (manager *latencyJobManager) execute(
	ctx context.Context,
	run *latencyRun,
	request latencyJobRequest,
	probe latencyProbeFunc,
) {
	var queueMu sync.Mutex
	nextIndex := 0
	workerCount := min(
		max(1, request.Concurrency),
		min(4, len(request.NodeTags)),
	)

	worker := func() {
		for {
			if ctx.Err() != nil {
				return
			}
			queueMu.Lock()
			index := nextIndex
			nextIndex++
			queueMu.Unlock()
			if index >= len(request.NodeTags) {
				return
			}
			nodeTag := request.NodeTags[index]
			result := probe(
				ctx,
				nodeTag,
				request.TestURL,
				request.Timeout,
			)
			manager.record(run, nodeTag, result)
		}
	}

	var workers sync.WaitGroup
	workers.Add(workerCount)
	for index := 0; index < workerCount; index++ {
		go func() {
			defer workers.Done()
			worker()
		}()
	}
	workers.Wait()

	manager.mu.Lock()
	if run.status == latencyJobRunning {
		if ctx.Err() != nil {
			manager.cancelRunLocked(run, "context_cancelled")
		} else {
			run.status = latencyJobCompleted
			manager.logEvent(
				"latency_complete",
				fmt.Sprintf("run_id=%s", latencyRunIDPrefix(run.id)),
				fmt.Sprintf("completed=%d", len(run.results)),
				fmt.Sprintf("total=%d", run.total),
			)
		}
	}
	manager.mu.Unlock()
}

func (manager *latencyJobManager) record(
	run *latencyRun,
	nodeTag string,
	result latencyNodeResult,
) {
	manager.mu.Lock()
	defer manager.mu.Unlock()
	if run.status != latencyJobRunning {
		return
	}
	if _, exists := run.results[nodeTag]; exists {
		return
	}
	run.results[nodeTag] = cloneLatencyNodeResult(result)
	manager.logEvent(
		"latency_node",
		fmt.Sprintf("run_id=%s", latencyRunIDPrefix(run.id)),
		fmt.Sprintf("node=%s", sanitizeLatencyLogValue(nodeTag)),
		fmt.Sprintf("attempts=%v", result.Attempts),
		fmt.Sprintf("elapsed_ms=%d", result.ElapsedMS),
		fmt.Sprintf("failure=%s", result.FailureKind),
	)
}

func (manager *latencyJobManager) cancelActiveLocked(reason string) {
	if manager.activeID == "" {
		return
	}
	if run := manager.runs[manager.activeID]; run != nil {
		manager.cancelRunLocked(run, reason)
	}
}

func (manager *latencyJobManager) cancelRunLocked(
	run *latencyRun,
	reason string,
) {
	if run.status != latencyJobRunning {
		return
	}
	run.cancel()
	run.status = latencyJobCancelled
	for _, nodeTag := range run.nodeTags {
		if _, exists := run.results[nodeTag]; exists {
			continue
		}
		run.results[nodeTag] = latencyNodeResult{
			LatencyMS:   -1,
			Attempts:    []int{-1},
			FailureKind: latencyFailureCancelled,
		}
	}
	manager.logEvent(
		"latency_cancel",
		fmt.Sprintf("run_id=%s", latencyRunIDPrefix(run.id)),
		fmt.Sprintf("reason=%s", sanitizeLatencyLogValue(reason)),
		fmt.Sprintf("completed=%d", len(run.results)),
		fmt.Sprintf("total=%d", run.total),
	)
}

func (manager *latencyJobManager) snapshotLocked(
	run *latencyRun,
) latencyJobSnapshot {
	results := make(map[string]latencyNodeResult, len(run.results))
	for nodeTag, result := range run.results {
		results[nodeTag] = cloneLatencyNodeResult(result)
	}
	return latencyJobSnapshot{
		RunID:     run.id,
		Status:    run.status,
		Completed: len(results),
		Total:     run.total,
		Results:   results,
	}
}

func (manager *latencyJobManager) pruneLocked() {
	for len(manager.order) > 2 {
		oldest := manager.order[0]
		manager.order = manager.order[1:]
		if oldest != manager.activeID {
			delete(manager.runs, oldest)
		}
	}
}

func (manager *latencyJobManager) logEvent(name string, fields ...string) {
	if manager.logger != nil {
		manager.logger.event(name, fields...)
	}
}

func cloneLatencyNodeResult(result latencyNodeResult) latencyNodeResult {
	result.Attempts = append([]int(nil), result.Attempts...)
	result.HTTPStatusCodes = append([]int(nil), result.HTTPStatusCodes...)
	return result
}

func newLatencyRunID() (string, error) {
	value := make([]byte, 16)
	if _, err := rand.Read(value); err != nil {
		return "", err
	}
	return hex.EncodeToString(value), nil
}

func latencyRunIDPrefix(runID string) string {
	if len(runID) <= 8 {
		return runID
	}
	return runID[:8]
}

func sanitizeLatencyLogValue(value string) string {
	output := make([]rune, 0, min(64, len(value)))
	for _, character := range value {
		if len(output) >= 64 {
			break
		}
		switch {
		case character >= 'a' && character <= 'z',
			character >= 'A' && character <= 'Z',
			character >= '0' && character <= '9',
			unicode.IsLetter(character),
			unicode.IsDigit(character),
			character == '-',
			character == '_',
			character == '.':
			output = append(output, character)
		default:
			output = append(output, '_')
		}
	}
	return string(output)
}
