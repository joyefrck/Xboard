// SPDX-License-Identifier: GPL-3.0-or-later

package main

import (
	"context"
	"errors"
	"os"
	"strings"
	"sync"
	"time"
)

type coreInstance interface {
	Start() error
	Close() error
}

type latencyCapableCore interface {
	coreInstance
	LatencyProbe() latencyProbeFunc
}

type coreFactory interface {
	New(context.Context, []byte, string) (coreInstance, error)
	Version() string
}

type coreFailure struct {
	Code    string
	Message string
}

type coreManager struct {
	mu               sync.Mutex
	factory          coreFactory
	instance         coreInstance
	cancel           context.CancelFunc
	state            runtimeState
	startupTimeout   time.Duration
	assetDirectory   string
	runtimeDirectory string
	logger           *lifecycleLogger
	speedTest        bool
	latency          *latencyJobManager
}

func newCoreManager(
	factory coreFactory,
	startupTimeout time.Duration,
	assetDirectory string,
	runtimeDirectory string,
	logger *lifecycleLogger,
) *coreManager {
	return &coreManager{
		factory:          factory,
		state:            initialRuntimeState(factory.Version()),
		startupTimeout:   startupTimeout,
		assetDirectory:   assetDirectory,
		runtimeDirectory: runtimeDirectory,
		logger:           logger,
		latency:          newLatencyJobManager(logger),
	}
}

func (manager *coreManager) snapshot() runtimeState {
	manager.mu.Lock()
	defer manager.mu.Unlock()
	return manager.state
}

func (manager *coreManager) isConnected() bool {
	manager.mu.Lock()
	defer manager.mu.Unlock()
	return manager.instance != nil && manager.state.Status == statusConnected
}

func (manager *coreManager) start(parent context.Context, config []byte, speedTest bool) response {
	startedAt := time.Now()
	manager.mu.Lock()
	defer manager.mu.Unlock()

	manager.stopLocked()
	manager.state = runtimeState{
		Status:      statusCoreStarting,
		CoreVersion: manager.factory.Version(),
	}
	manager.logger.transition(statusCoreStarting, "", time.Since(startedAt))

	if len(config) == 0 || len(config) > maxConfigBytes {
		return manager.failLocked(coreFailure{
			Code:    "config_invalid",
			Message: "Windows TUN configuration is invalid.",
		}, startedAt)
	}
	if err := prepareRuntimeFiles(manager.assetDirectory, manager.runtimeDirectory, config); err != nil {
		return manager.failLocked(coreFailure{
			Code:    "config_invalid",
			Message: "Windows service could not prepare runtime files.",
		}, startedAt)
	}

	coreContext, cancel := context.WithCancel(parent)
	instance, err := manager.factory.New(coreContext, config, manager.runtimeDirectory)
	if err != nil {
		cancel()
		return manager.failLocked(classifyCoreError(err), startedAt)
	}

	startResult := make(chan error, 1)
	go func() {
		startResult <- instance.Start()
	}()
	timer := time.NewTimer(manager.startupTimeout)
	defer timer.Stop()

	select {
	case err = <-startResult:
		if err != nil {
			cancel()
			_ = instance.Close()
			return manager.failLocked(classifyCoreError(err), startedAt)
		}
	case <-timer.C:
		cancel()
		closeCoreBounded(instance, 5*time.Second)
		return manager.failLocked(coreFailure{
			Code:    "core_start_timeout",
			Message: "sing-box core startup timed out.",
		}, startedAt)
	case <-parent.Done():
		cancel()
		closeCoreBounded(instance, 5*time.Second)
		return manager.failLocked(coreFailure{
			Code:    "core_start_failed",
			Message: "sing-box core startup was cancelled.",
		}, startedAt)
	}

	manager.instance = instance
	manager.cancel = cancel
	manager.speedTest = speedTest
	manager.state = runtimeState{
		Status:      statusConnected,
		CoreVersion: manager.factory.Version(),
		CorePID:     os.Getpid(),
	}
	manager.logger.transition(statusConnected, "", time.Since(startedAt))
	return manager.state.response()
}

func (manager *coreManager) stop() response {
	manager.mu.Lock()
	defer manager.mu.Unlock()
	manager.stopLocked()
	return manager.state.response()
}

func (manager *coreManager) stopSpeedTest() response {
	manager.mu.Lock()
	defer manager.mu.Unlock()
	if manager.speedTest {
		manager.stopLocked()
	}
	return manager.state.response()
}

func (manager *coreManager) stopLocked() {
	manager.latency.CancelActive("core_stop")
	if manager.instance == nil {
		manager.speedTest = false
		manager.state = initialRuntimeState(manager.factory.Version())
		return
	}
	startedAt := time.Now()
	manager.state.Status = statusDisconnecting
	if manager.cancel != nil {
		manager.cancel()
	}
	closeCoreBounded(manager.instance, 10*time.Second)
	manager.instance = nil
	manager.cancel = nil
	manager.speedTest = false
	manager.state = initialRuntimeState(manager.factory.Version())
	manager.logger.transition(statusDisconnected, "", time.Since(startedAt))
}

func (manager *coreManager) startLatencyTest(
	parent context.Context,
	request latencyJobRequest,
) (latencyJobSnapshot, *coreFailure) {
	manager.mu.Lock()
	defer manager.mu.Unlock()
	if manager.instance == nil || manager.state.Status != statusConnected {
		return latencyJobSnapshot{}, &coreFailure{
			Code:    "latency_unavailable",
			Message: "Windows latency service requires a connected core.",
		}
	}
	instance, supported := manager.instance.(latencyCapableCore)
	if !supported {
		return latencyJobSnapshot{}, &coreFailure{
			Code:    "latency_unavailable",
			Message: "Windows latency service is unavailable.",
		}
	}
	snapshot, err := manager.latency.Start(
		parent,
		request,
		instance.LatencyProbe(),
	)
	if err != nil {
		return latencyJobSnapshot{}, &coreFailure{
			Code:    "latency_start_failed",
			Message: "Windows latency service could not start.",
		}
	}
	return snapshot, nil
}

func (manager *coreManager) latencySnapshot(
	runID string,
) latencyJobSnapshot {
	return manager.latency.Snapshot(runID)
}

func (manager *coreManager) cancelLatencyTest(
	runID string,
	reason string,
) latencyJobSnapshot {
	return manager.latency.Cancel(runID, reason)
}

func (manager *coreManager) failLocked(failure coreFailure, startedAt time.Time) response {
	manager.instance = nil
	manager.cancel = nil
	manager.speedTest = false
	manager.state = runtimeState{
		Status:       statusError,
		CoreVersion:  manager.factory.Version(),
		ErrorCode:    failure.Code,
		ErrorMessage: failure.Message,
	}
	manager.logger.transition(statusError, failure.Code, time.Since(startedAt))
	return manager.state.response()
}

func closeCoreBounded(instance coreInstance, timeout time.Duration) {
	done := make(chan struct{}, 1)
	go func() {
		_ = instance.Close()
		done <- struct{}{}
	}()
	timer := time.NewTimer(timeout)
	defer timer.Stop()
	select {
	case <-done:
	case <-timer.C:
	}
}

func classifyCoreError(err error) coreFailure {
	if err == nil {
		return coreFailure{}
	}
	message := strings.ToLower(err.Error())
	switch {
	case strings.Contains(message, "tun") &&
		(strings.Contains(message, "address") || strings.Contains(message, "route")):
		return coreFailure{
			Code:    "tun_start_failed",
			Message: "Windows TUN interface or route could not be created.",
		}
	case strings.Contains(message, "decode") ||
		strings.Contains(message, "unmarshal") ||
		strings.Contains(message, "unknown field") ||
		strings.Contains(message, "invalid"):
		return coreFailure{
			Code:    "core_config_invalid",
			Message: "sing-box rejected the configuration.",
		}
	case errors.Is(err, context.Canceled):
		return coreFailure{
			Code:    "core_start_failed",
			Message: "sing-box core startup was cancelled.",
		}
	default:
		return coreFailure{
			Code:    "core_start_failed",
			Message: "sing-box core could not start.",
		}
	}
}
