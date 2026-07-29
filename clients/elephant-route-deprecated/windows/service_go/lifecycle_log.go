// SPDX-License-Identifier: GPL-3.0-or-later

package main

import (
	"fmt"
	"io"
	"strings"
	"sync"
	"time"
)

type lifecycleLogger struct {
	mu     sync.Mutex
	output io.Writer
}

func newLifecycleLogger(output io.Writer) *lifecycleLogger {
	return &lifecycleLogger{output: output}
}

func (logger *lifecycleLogger) transition(state, errorCode string, elapsed time.Duration) {
	if logger == nil || logger.output == nil {
		return
	}
	logger.mu.Lock()
	defer logger.mu.Unlock()
	fmt.Fprintf(
		logger.output,
		"%s state=%s error_code=%s elapsed_ms=%d\n",
		time.Now().UTC().Format(time.RFC3339),
		state,
		errorCode,
		elapsed.Milliseconds(),
	)
}

func (logger *lifecycleLogger) event(name string, fields ...string) {
	if logger == nil || logger.output == nil {
		return
	}
	logger.mu.Lock()
	defer logger.mu.Unlock()
	fmt.Fprintf(
		logger.output,
		"%s event=%s %s\n",
		time.Now().UTC().Format(time.RFC3339),
		name,
		strings.Join(fields, " "),
	)
}
