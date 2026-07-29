// SPDX-License-Identifier: GPL-3.0-or-later

package main

import (
	"context"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

func TestLatencyJobCapsConcurrencyAndPublishesResults(t *testing.T) {
	var active atomic.Int32
	var maximum atomic.Int32
	probe := func(
		_ context.Context,
		_ string,
		_ string,
		_ time.Duration,
	) latencyNodeResult {
		current := active.Add(1)
		for {
			observed := maximum.Load()
			if current <= observed || maximum.CompareAndSwap(observed, current) {
				break
			}
		}
		defer active.Add(-1)
		time.Sleep(10 * time.Millisecond)
		return latencyNodeResult{
			LatencyMS: 25,
			ElapsedMS: 10,
			Attempts:  []int{40, 25},
		}
	}
	manager := newLatencyJobManager(nil)
	snapshot, err := manager.Start(
		context.Background(),
		latencyJobRequest{
			NodeTags:    []string{"a", "b", "c", "d", "e", "f"},
			TestURL:     "https://www.gstatic.com/generate_204",
			Timeout:     time.Second,
			Concurrency: 4,
		},
		probe,
	)
	if err != nil {
		t.Fatal(err)
	}
	final := waitForLatencyJob(t, manager, snapshot.RunID)
	if final.Status != latencyJobCompleted || len(final.Results) != 6 {
		t.Fatalf("unexpected final snapshot: %#v", final)
	}
	if maximum.Load() != 4 {
		t.Fatalf("expected four workers, got %d", maximum.Load())
	}
}

func TestLatencyJobPublishesIncrementalSnapshot(t *testing.T) {
	releaseSecond := make(chan struct{})
	var completed atomic.Int32
	probe := func(
		_ context.Context,
		nodeTag string,
		_ string,
		_ time.Duration,
	) latencyNodeResult {
		if nodeTag == "second" {
			<-releaseSecond
		}
		completed.Add(1)
		return latencyNodeResult{
			LatencyMS: 20,
			Attempts:  []int{30, 20},
		}
	}
	manager := newLatencyJobManager(nil)
	started, err := manager.Start(
		context.Background(),
		latencyJobRequest{
			NodeTags:    []string{"first", "second"},
			TestURL:     "https://www.gstatic.com/generate_204",
			Timeout:     time.Second,
			Concurrency: 2,
		},
		probe,
	)
	if err != nil {
		t.Fatal(err)
	}

	deadline := time.Now().Add(time.Second)
	var partial latencyJobSnapshot
	for time.Now().Before(deadline) {
		partial = manager.Snapshot(started.RunID)
		if partial.Completed == 1 {
			break
		}
		time.Sleep(time.Millisecond)
	}
	if partial.Status != latencyJobRunning || partial.Completed != 1 {
		t.Fatalf("missing partial snapshot: %#v completed=%d", partial, completed.Load())
	}
	close(releaseSecond)
	final := waitForLatencyJob(t, manager, started.RunID)
	if final.Status != latencyJobCompleted || final.Completed != 2 {
		t.Fatalf("unexpected final snapshot: %#v", final)
	}
}

func TestLatencyJobReplacementCancelsOldRun(t *testing.T) {
	started := make(chan struct{})
	var startedOnce sync.Once
	probe := func(
		ctx context.Context,
		_ string,
		_ string,
		_ time.Duration,
	) latencyNodeResult {
		startedOnce.Do(func() { close(started) })
		<-ctx.Done()
		return latencyNodeResult{
			LatencyMS:   -1,
			Attempts:    []int{-1},
			FailureKind: latencyFailureCancelled,
		}
	}
	manager := newLatencyJobManager(nil)
	first, err := manager.Start(
		context.Background(),
		validLatencyJobRequest("old"),
		probe,
	)
	if err != nil {
		t.Fatal(err)
	}
	<-started
	second, err := manager.Start(
		context.Background(),
		validLatencyJobRequest("new"),
		func(
			context.Context,
			string,
			string,
			time.Duration,
		) latencyNodeResult {
			return latencyNodeResult{
				LatencyMS: 20,
				Attempts:  []int{30, 20},
			}
		},
	)
	if err != nil {
		t.Fatal(err)
	}
	if first.RunID == second.RunID {
		t.Fatal("run IDs must differ")
	}
	if snapshot := manager.Snapshot(first.RunID); snapshot.Status != latencyJobCancelled {
		t.Fatalf("old run was not cancelled: %#v", snapshot)
	}
	if snapshot := waitForLatencyJob(t, manager, second.RunID); snapshot.Status != latencyJobCompleted {
		t.Fatalf("new run did not complete: %#v", snapshot)
	}
}

func TestLatencyJobArchiveIsBounded(t *testing.T) {
	manager := newLatencyJobManager(nil)
	for _, tag := range []string{"one", "two", "three"} {
		started, err := manager.Start(
			context.Background(),
			validLatencyJobRequest(tag),
			func(
				context.Context,
				string,
				string,
				time.Duration,
			) latencyNodeResult {
				return latencyNodeResult{
					LatencyMS: 10,
					Attempts:  []int{20, 10},
				}
			},
		)
		if err != nil {
			t.Fatal(err)
		}
		waitForLatencyJob(t, manager, started.RunID)
	}

	manager.mu.Lock()
	runCount := len(manager.runs)
	manager.mu.Unlock()
	if runCount > 2 {
		t.Fatalf("latency run archive grew to %d", runCount)
	}
}

func waitForLatencyJob(
	t *testing.T,
	manager *latencyJobManager,
	runID string,
) latencyJobSnapshot {
	t.Helper()
	deadline := time.Now().Add(2 * time.Second)
	var snapshot latencyJobSnapshot
	for time.Now().Before(deadline) {
		snapshot = manager.Snapshot(runID)
		if snapshot.Status != latencyJobRunning {
			return snapshot
		}
		time.Sleep(time.Millisecond)
	}
	t.Fatalf("latency job did not finish: %#v", snapshot)
	return latencyJobSnapshot{}
}

func validLatencyJobRequest(nodeTags ...string) latencyJobRequest {
	return latencyJobRequest{
		NodeTags:    nodeTags,
		TestURL:     "https://www.gstatic.com/generate_204",
		Timeout:     time.Second,
		Concurrency: 1,
	}
}
