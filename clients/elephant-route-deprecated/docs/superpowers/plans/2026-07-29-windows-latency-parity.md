# Windows Latency Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Windows 1.6.6's serialized single-shot Clash latency calls with an asynchronous service-owned job that measures every concrete outbound using the same two-transfer, connection-reuse semantics as macOS.

**Architecture:** The running Go-hosted sing-box `Box` exposes its concrete outbounds to a service-side HTTP probe. A single latency job runs up to four node workers, stores typed snapshots behind an opaque run ID, and is controlled through quick start/get/cancel IPC methods. Dart polls snapshots and publishes results incrementally, while the synchronous Windows C++ bridge never waits for network completion.

**Tech Stack:** Dart/Flutter, Flutter MethodChannel, C++ Windows runner bridge, Go 1.25, sing-box 1.12.25 outbound APIs, Windows named pipes, Flutter/Go/C++ tests, GitHub Actions, Inno Setup.

---

### Task 1: Build the core-native two-transfer probe

**Files:**
- Create: `windows/service_go/latency_probe.go`
- Create: `windows/service_go/latency_probe_test.go`

- [ ] **Step 1: Write the failing connection-reuse tests**

Create `windows/service_go/latency_probe_test.go` with an HTTP test server and a
counting dialer. The first test proves two HTTP transfers use one TCP
connection and select the lower duration. The second proves a valid transfer
survives one HTTP failure.

```go
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
	dials atomic.Int32
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
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
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
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
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
```

- [ ] **Step 2: Run the test and verify the red state**

Run:

```bash
cd windows/service_go
go test ./... -run 'TestConnectionLatencyProbe' -count=1
```

Expected: build failure because `latencyDialer`,
`newConnectionLatencyProbe`, and the result types do not exist.

- [ ] **Step 3: Implement the reusable probe**

Create `windows/service_go/latency_probe.go`. Use one `http.Transport` and
one `http.Client` for both sequential requests. Drain and close each response
body so the second request can reuse the connection.

```go
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
	now     func() time.Time
}

func newConnectionLatencyProbe(resolve latencyDialerResolver) *connectionLatencyProbe {
	return &connectionLatencyProbe{resolve: resolve, now: time.Now}
}

func (probe *connectionLatencyProbe) Probe(
	parent context.Context,
	nodeTag string,
	testURL string,
	timeout time.Duration,
) latencyNodeResult {
	startedAt := probe.now()
	dialer, found := probe.resolve(nodeTag)
	if !found {
		return latencyNodeResult{
			LatencyMS: -1,
			ElapsedMS: int(probe.now().Sub(startedAt).Milliseconds()),
			Attempts: []int{-1, -1},
			FailureKind: latencyFailureService,
		}
	}

	ctx, cancel := context.WithTimeout(parent, timeout)
	defer cancel()
	transport := &http.Transport{
		Proxy: nil,
		DialContext: func(ctx context.Context, network, address string) (net.Conn, error) {
			return dialer.DialContext(ctx, network, M.ParseSocksaddr(address))
		},
		TLSClientConfig: &tls.Config{MinVersion: tls.VersionTLS12},
		DisableKeepAlives: false,
	}
	defer transport.CloseIdleConnections()
	client := &http.Client{Transport: transport}

	attempts := make([]int, 0, 2)
	statuses := make([]int, 0, 2)
	var lastTransportError bool
	for attempt := 0; attempt < 2; attempt++ {
		attemptStarted := probe.now()
		request, err := http.NewRequestWithContext(ctx, http.MethodGet, testURL, nil)
		if err != nil {
			attempts = append(attempts, -1)
			lastTransportError = true
			continue
		}
		response, err := client.Do(request)
		if err != nil {
			attempts = append(attempts, -1)
			lastTransportError = true
			continue
		}
		_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, 64*1024))
		_ = response.Body.Close()
		statuses = append(statuses, response.StatusCode)
		if response.StatusCode == http.StatusOK || response.StatusCode == http.StatusNoContent {
			attempts = append(attempts, max(1, int(probe.now().Sub(attemptStarted).Milliseconds())))
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
		LatencyMS: best,
		ElapsedMS: int(probe.now().Sub(startedAt).Milliseconds()),
		Attempts: attempts,
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
	case lastTransportError:
		result.FailureKind = latencyFailureTransport
	default:
		result.FailureKind = latencyFailureService
	}
	return result
}
```

During implementation, use an elapsed-time injection in tests instead of
asserting exact wall-clock millisecond values if the initial sleep makes the
test flaky. Preserve the production contract: two ordered attempts, one
transport, minimum valid result.

- [ ] **Step 4: Run focused and package tests**

Run:

```bash
gofmt -w latency_probe.go latency_probe_test.go
go test ./... -run 'TestConnectionLatencyProbe' -count=1
go test ./...
```

Expected: both probe tests and the complete Go package pass.

- [ ] **Step 5: Commit the probe**

```bash
git add windows/service_go/latency_probe.go windows/service_go/latency_probe_test.go
git commit -m "feat: add Windows core-native latency probe"
```

### Task 2: Add the asynchronous service-side latency job

**Files:**
- Create: `windows/service_go/latency_job.go`
- Create: `windows/service_go/latency_job_test.go`
- Modify: `windows/service_go/lifecycle_log.go`
- Modify: `windows/service_go/lifecycle_log_test.go`

- [ ] **Step 1: Write failing job lifecycle tests**

Cover true worker concurrency, incremental snapshots, replacement
cancellation, and stale run IDs:

```go
func TestLatencyJobCapsConcurrencyAndPublishesResults(t *testing.T) {
	var active atomic.Int32
	var maximum atomic.Int32
	probe := func(ctx context.Context, nodeTag, testURL string, timeout time.Duration) latencyNodeResult {
		current := active.Add(1)
		for {
			observed := maximum.Load()
			if current <= observed || maximum.CompareAndSwap(observed, current) {
				break
			}
		}
		defer active.Add(-1)
		time.Sleep(10 * time.Millisecond)
		return latencyNodeResult{LatencyMS: 25, ElapsedMS: 10, Attempts: []int{40, 25}}
	}
	manager := newLatencyJobManager(nil)
	snapshot, err := manager.Start(
		context.Background(),
		latencyJobRequest{
			NodeTags: []string{"a", "b", "c", "d", "e", "f"},
			TestURL: "https://www.gstatic.com/generate_204",
			Timeout: time.Second,
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

func TestLatencyJobReplacementCancelsOldRun(t *testing.T) {
	started := make(chan struct{})
	probe := func(ctx context.Context, nodeTag, testURL string, timeout time.Duration) latencyNodeResult {
		close(started)
		<-ctx.Done()
		return latencyNodeResult{LatencyMS: -1, FailureKind: latencyFailureCancelled}
	}
	manager := newLatencyJobManager(nil)
	first, _ := manager.Start(context.Background(), validLatencyJobRequest("old"), probe)
	<-started
	second, _ := manager.Start(
		context.Background(),
		validLatencyJobRequest("new"),
		func(context.Context, string, string, time.Duration) latencyNodeResult {
			return latencyNodeResult{LatencyMS: 20, Attempts: []int{30, 20}}
		},
	)
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
```

Add test helpers in the same test file. `waitForLatencyJob` must poll for no
more than two seconds and fail with the last snapshot.

- [ ] **Step 2: Run the tests and verify they fail**

```bash
cd windows/service_go
go test ./... -run 'TestLatencyJob' -count=1
```

Expected: build failure because the job manager does not exist.

- [ ] **Step 3: Implement job state, worker pool, and sanitized logging**

Create `latency_job.go` with these public internal contracts:

```go
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
	id      string
	cancel  context.CancelFunc
	status  latencyJobStatus
	total   int
	results map[string]latencyNodeResult
}

type latencyJobManager struct {
	mu       sync.Mutex
	activeID string
	runs     map[string]*latencyRun
	order    []string
	logger   *lifecycleLogger
}
```

Implement:

```go
func (manager *latencyJobManager) Start(
	parent context.Context,
	request latencyJobRequest,
	probe latencyProbeFunc,
) (latencyJobSnapshot, error)

func (manager *latencyJobManager) Snapshot(runID string) latencyJobSnapshot
func (manager *latencyJobManager) Cancel(runID string, reason string) latencyJobSnapshot
func (manager *latencyJobManager) CancelActive(reason string)
```

`Start` must:

```go
workerCount := min(max(1, request.Concurrency), min(4, len(request.NodeTags)))
ctx, cancel := context.WithCancel(parent)
run := &latencyRun{
	id: newLatencyRunID(),
	cancel: cancel,
	status: latencyJobRunning,
	total: len(request.NodeTags),
	results: make(map[string]latencyNodeResult, len(request.NodeTags)),
}
```

Run the queue in a goroutine. Each worker claims one index under a small mutex,
calls the probe outside all locks, records one final result, and continues after
node failures. When cancellation occurs, fill every unfinished tag with a
`cancelled` result before setting the terminal state.

Keep only the active run and the immediately preceding terminal run in `runs`.
This bounded archive lets a stale Dart poll retrieve a deterministic
`cancelled` snapshot without allowing unbounded service memory growth.

Extend `lifecycleLogger` with a generic sanitized event method:

```go
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
```

Call it only with already-sanitized values such as run ID prefix, node count,
attempt integers, status classes, and failure kinds. Do not pass test URL query
strings, configuration JSON, UUIDs, passwords, or tokens.

- [ ] **Step 4: Run race-aware Go verification**

```bash
gofmt -w latency_job.go latency_job_test.go lifecycle_log.go lifecycle_log_test.go
go test ./... -run 'TestLatencyJob|TestLifecycle' -count=1
go test -race ./...
```

Expected: all tests pass and the race detector reports no data race.

- [ ] **Step 5: Commit the job manager**

```bash
git add windows/service_go/latency_job.go \
  windows/service_go/latency_job_test.go \
  windows/service_go/lifecycle_log.go \
  windows/service_go/lifecycle_log_test.go
git commit -m "feat: manage Windows latency jobs in service"
```

### Task 3: Expose the running sing-box outbounds to the job

**Files:**
- Modify: `windows/service_go/core_singbox.go`
- Modify: `windows/service_go/core.go`
- Modify: `windows/service_go/core_test.go`
- Create: `windows/service_go/core_latency_test.go`

- [ ] **Step 1: Write failing active-core tests**

Add a fake core that implements both lifecycle and latency probing. Prove the
manager rejects disconnected tests, delegates connected tests, and cancels a
job before stop:

```go
type fakeLatencyCore struct {
	fakeCoreInstance
	probe latencyProbeFunc
}

func (core *fakeLatencyCore) LatencyProbe() latencyProbeFunc {
	return core.probe
}

func TestCoreManagerRunsLatencyOnlyOnConnectedCore(t *testing.T) {
	manager := testCoreManagerWithInstance(t, &fakeLatencyCore{
		probe: func(
			context.Context,
			string,
			string,
			time.Duration,
		) latencyNodeResult {
			return latencyNodeResult{LatencyMS: 24, Attempts: []int{40, 24}}
		},
	})
	if _, failure := manager.startLatencyTest(context.Background(), validLatencyJobRequest("Tokyo")); failure != nil {
		t.Fatalf("connected latency rejected: %#v", failure)
	}
	manager.stop()
	if snapshot := manager.latency.Snapshot(""); snapshot.Status == latencyJobRunning {
		t.Fatalf("latency still running after stop: %#v", snapshot)
	}
}

func TestCoreManagerRejectsLatencyWhileDisconnected(t *testing.T) {
	manager := testCoreManager(t, &fakeCoreFactory{}, time.Second)
	_, failure := manager.startLatencyTest(context.Background(), validLatencyJobRequest("Tokyo"))
	if failure == nil || failure.Code != "latency_unavailable" {
		t.Fatalf("unexpected failure: %#v", failure)
	}
}
```

- [ ] **Step 2: Run the focused tests and verify they fail**

```bash
cd windows/service_go
go test ./... -run 'TestCoreManager.*Latency' -count=1
```

Expected: build failure because the core does not expose a latency probe.

- [ ] **Step 3: Wrap the real sing-box Box**

In `core_singbox.go`, replace the raw `*box.Box` return with:

```go
type singBoxInstance struct {
	box *box.Box
}

func (instance *singBoxInstance) Start() error { return instance.box.Start() }
func (instance *singBoxInstance) Close() error { return instance.box.Close() }

func (instance *singBoxInstance) LatencyProbe() latencyProbeFunc {
	probe := newConnectionLatencyProbe(func(tag string) (latencyDialer, bool) {
		outbound, found := instance.box.Outbound().Outbound(tag)
		if !found {
			return nil, false
		}
		return outbound, true
	})
	return probe.Probe
}
```

Return `&singBoxInstance{box: instance}` from `singBoxFactory.New`.

In `core.go`, add:

```go
type latencyCapableCore interface {
	coreInstance
	LatencyProbe() latencyProbeFunc
}
```

Give `coreManager` a `latency *latencyJobManager` created in
`newCoreManager`. Implement `startLatencyTest`, `latencySnapshot`, and
`cancelLatencyTest` by checking `statusConnected`, asserting
`latencyCapableCore`, and delegating to the job manager. Call
`CancelActive("core_stop")` before cancelling or closing the core in
`stopLocked`.

- [ ] **Step 4: Run the complete Go service suite**

```bash
gofmt -w core_singbox.go core.go core_test.go core_latency_test.go
go test ./...
go test -race ./...
```

Expected: all existing startup/stop tests and new active-core latency tests
pass.

- [ ] **Step 5: Commit active-core integration**

```bash
git add windows/service_go/core_singbox.go \
  windows/service_go/core.go \
  windows/service_go/core_test.go \
  windows/service_go/core_latency_test.go
git commit -m "feat: probe active Windows sing-box outbounds"
```

### Task 4: Add start/get/cancel latency IPC methods

**Files:**
- Modify: `windows/service_go/protocol.go`
- Modify: `windows/service_go/protocol_test.go`
- Modify: `windows/service_go/http_client.go`
- Modify: `windows/service_go/http_client_test.go`
- Modify: `windows/runner/windows_service_bridge.cpp`
- Modify: `windows/common/windows_protocol.cpp`
- Modify: `windows/common/windows_protocol.h`
- Modify: `windows/tests/windows_protocol_test.cpp`
- Modify: `lib/core/singbox/windows_service_protocol.dart`
- Modify: `test/core/singbox/windows_service_protocol_test.dart`

- [ ] **Step 1: Write failing Go protocol and dispatcher tests**

Add methods to the test request but not production yet:

```go
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

func TestDispatcherStartsSnapshotsAndCancelsLatencyJob(t *testing.T) {
	manager := connectedLatencyCoreManager(t)
	dispatcher := newDispatcher(manager, validProfileProvider(), &recordingClashController{})
	started := dispatcher.handle(context.Background(), request{
		Method: "startLatencyTest",
		NodeTagsJSON: `["Tokyo"]`,
		TestURL: "https://www.gstatic.com/generate_204",
		TimeoutMS: 5000,
		Concurrency: 1,
	})
	if started.RunID == "" || started.LatencyTestStatus == "" {
		t.Fatalf("missing latency job identity: %#v", started)
	}
	snapshot := dispatcher.handle(context.Background(), request{
		Method: "getLatencyTest",
		RunID: started.RunID,
	})
	if snapshot.RunID != started.RunID {
		t.Fatalf("wrong snapshot: %#v", snapshot)
	}
	cancelled := dispatcher.handle(context.Background(), request{
		Method: "cancelLatencyTest",
		RunID: started.RunID,
	})
	if cancelled.LatencyTestStatus != string(latencyJobCancelled) {
		t.Fatalf("job not cancelled: %#v", cancelled)
	}
}
```

- [ ] **Step 2: Add failing Dart protocol tests**

In `windows_service_protocol_test.dart`, assert the supported method set
contains all three new methods and parse a snapshot:

```dart
test('supports bounded service-owned latency jobs', () {
  expect(
    WindowsServiceProtocol.supportedMethods,
    containsAll(const [
      'startLatencyTest',
      'getLatencyTest',
      'cancelLatencyTest',
    ]),
  );
  final snapshot = WindowsServiceProtocol.parseLatencySnapshot({
    'run_id': 'run-1',
    'latency_test_status': 'running',
    'latency_completed': 1,
    'latency_total': 2,
    'latency_results_json':
        '{"Tokyo":{"latency_ms":82,"elapsed_ms":190,"attempts":[168,82],"http_status_codes":[204,204]}}',
  });
  expect(snapshot.runId, 'run-1');
  expect(snapshot.completed, 1);
  expect(snapshot.total, 2);
  expect(snapshot.results['Tokyo']?.latencyMs, 82);
  expect(snapshot.results['Tokyo']?.attempts, [168, 82]);
});
```

- [ ] **Step 3: Run Go and Dart protocol tests in the red state**

```bash
(cd windows/service_go && go test ./... -run 'TestDecodeLatency|TestDispatcherStartsSnapshots' -count=1)
flutter test --no-pub test/core/singbox/windows_service_protocol_test.dart
```

Expected: failures because the protocol fields and methods are absent.

- [ ] **Step 4: Implement the Go request/response contract**

Extend `requestArguments` and `request` with:

```go
NodeTagsJSON string `json:"node_tags_json,omitempty"`
TestURL string `json:"test_url,omitempty"`
TimeoutMS int `json:"timeout_ms,omitempty"`
Concurrency int `json:"concurrency,omitempty"`
RunID string `json:"run_id,omitempty"`
```

Extend `response` with:

```go
RunID string `json:"run_id,omitempty"`
LatencyTestStatus string `json:"latency_test_status,omitempty"`
LatencyCompleted int `json:"latency_completed,omitempty"`
LatencyTotal int `json:"latency_total,omitempty"`
LatencyResultsJSON string `json:"latency_results_json,omitempty"`
```

Add the three methods to `allowedMethods`. Decode `node_tags_json` into at
most 256 unique non-empty strings. Validate URL scheme/host/user-info/length,
timeout `1000..10000`, and concurrency `1..4`. Convert job snapshots to the
response using `json.Marshal(snapshot.Results)`.

Add dispatcher cases:

```go
case "startLatencyTest":
	return dispatcher.startLatencyTest(ctx, request)
case "getLatencyTest":
	return latencySnapshotResponse(dispatcher.manager.latencySnapshot(request.RunID))
case "cancelLatencyTest":
	return latencySnapshotResponse(dispatcher.manager.cancelLatencyTest(
		request.RunID,
		"user_cancelled",
	))
```

- [ ] **Step 5: Extend the C++ scalar bridge**

Add `IntegerArgument` beside `StringArgument`:

```cpp
std::optional<std::int64_t> IntegerArgument(
    const EncodableMap* arguments, const char* key) {
  if (!arguments) return std::nullopt;
  const auto iterator = arguments->find(EncodableValue(key));
  if (iterator == arguments->end()) return std::nullopt;
  if (const auto* value = std::get_if<std::int32_t>(&iterator->second)) {
    return *value;
  }
  if (const auto* value = std::get_if<std::int64_t>(&iterator->second)) {
    return *value;
  }
  return std::nullopt;
}
```

For `startLatencyTest`, serialize `node_tags_json`, `test_url`,
`timeout_ms`, and `concurrency`. For `getLatencyTest` and
`cancelLatencyTest`, serialize `run_id`. Add these response string keys:

```cpp
"run_id", "latency_test_status", "latency_results_json"
```

and integer keys:

```cpp
"latency_completed", "latency_total"
```

All three calls must remain short snapshot operations. Do not introduce a C++
worker thread or hold `MethodResult` beyond `HandleMethod`.

- [ ] **Step 6: Implement the Dart snapshot parser**

Add `WindowsLatencySnapshot` and
`WindowsServiceProtocol.parseLatencySnapshot` in
`windows_service_protocol.dart`. Map failure strings exactly:

```dart
static ConnectionLatencyFailureKind? _latencyFailure(String? value) {
  return switch (value) {
    'timeout' => ConnectionLatencyFailureKind.timeout,
    'httpError' => ConnectionLatencyFailureKind.httpError,
    'transportError' => ConnectionLatencyFailureKind.transportError,
    'serviceError' => ConnectionLatencyFailureKind.serviceError,
    'cancelled' => ConnectionLatencyFailureKind.cancelled,
    _ => null,
  };
}
```

Every parsed result must use
`ConnectionLatencySource.connectionProbe`. Malformed result JSON must throw
`FormatException` so the caller reports a service error instead of silently
showing stale values.

- [ ] **Step 7: Run all protocol tests**

```bash
dart format lib/core/singbox/windows_service_protocol.dart \
  test/core/singbox/windows_service_protocol_test.dart
(cd windows/service_go && gofmt -w protocol.go protocol_test.go http_client.go http_client_test.go && go test ./...)
flutter test --no-pub test/core/singbox/windows_service_protocol_test.dart
git diff --check
```

On Windows CI, additionally run:

```powershell
cmake --build build/windows/x64 --config Release --target windows_protocol_test
ctest --test-dir build/windows/x64 -C Release --output-on-failure
```

- [ ] **Step 8: Commit the IPC contract**

```bash
git add windows/service_go/protocol.go \
  windows/service_go/protocol_test.go \
  windows/service_go/http_client.go \
  windows/service_go/http_client_test.go \
  windows/runner/windows_service_bridge.cpp \
  windows/common/windows_protocol.cpp \
  windows/common/windows_protocol.h \
  windows/tests/windows_protocol_test.cpp \
  lib/core/singbox/windows_service_protocol.dart \
  test/core/singbox/windows_service_protocol_test.dart
git commit -m "feat: expose Windows latency job protocol"
```

### Task 5: Poll service snapshots from Dart

**Files:**
- Create: `lib/core/singbox/windows_latency_job_runner.dart`
- Create: `test/core/singbox/windows_latency_job_runner_test.dart`

- [ ] **Step 1: Write failing polling, progress, and cancellation tests**

Use an injected invoker and delay:

```dart
test('publishes each completed service result once', () async {
  final calls = <String>[];
  var polls = 0;
  final runner = WindowsLatencyJobRunner(
    invoke: (method, arguments) async {
      calls.add(method);
      if (method == 'startLatencyTest') {
        return const {
          'run_id': 'run-1',
          'latency_test_status': 'running',
          'latency_completed': 0,
          'latency_total': 2,
          'latency_results_json': '{}',
        };
      }
      polls++;
      return polls == 1
          ? {
              'run_id': 'run-1',
              'latency_test_status': 'running',
              'latency_completed': 1,
              'latency_total': 2,
              'latency_results_json': jsonEncode({
                'Tokyo': latencyResultJson(80),
              }),
            }
          : {
              'run_id': 'run-1',
              'latency_test_status': 'completed',
              'latency_completed': 2,
              'latency_total': 2,
              'latency_results_json': jsonEncode({
                'Tokyo': latencyResultJson(80),
                'Osaka': latencyResultJson(95),
              }),
            };
    },
    delay: (_) async {},
  );
  final callbacks = <String>[];
  final results = await runner.run(
    nodeTags: const ['Tokyo', 'Osaka'],
    testUrl: 'https://www.gstatic.com/generate_204',
    timeoutMs: 5000,
    concurrency: 4,
    isCancelled: () => false,
    onResult: (tag, _) => callbacks.add(tag),
  );
  expect(results.keys, {'Tokyo', 'Osaka'});
  expect(callbacks, ['Tokyo', 'Osaka']);
  expect(calls.first, 'startLatencyTest');
  expect(calls.where((method) => method == 'getLatencyTest'), hasLength(2));
});

test('cancels the matching service run without stale callbacks', () async {
  var cancelled = false;
  final methods = <String>[];
  final runner = WindowsLatencyJobRunner(
    invoke: (method, arguments) async {
      methods.add(method);
      if (method == 'startLatencyTest') {
        cancelled = true;
        return const {
          'run_id': 'run-1',
          'latency_test_status': 'running',
          'latency_completed': 0,
          'latency_total': 1,
          'latency_results_json': '{}',
        };
      }
      return const {
        'run_id': 'run-1',
        'latency_test_status': 'cancelled',
        'latency_completed': 1,
        'latency_total': 1,
        'latency_results_json':
            '{"Tokyo":{"latency_ms":-1,"elapsed_ms":0,"attempts":[-1],"failure_kind":"cancelled","http_status_codes":[]}}',
      };
    },
    delay: (_) async {},
  );
  final callbacks = <String>[];
  await runner.run(
    nodeTags: const ['Tokyo'],
    testUrl: 'https://www.gstatic.com/generate_204',
    timeoutMs: 5000,
    concurrency: 1,
    isCancelled: () => cancelled,
    onResult: (tag, _) => callbacks.add(tag),
  );
  expect(methods, contains('cancelLatencyTest'));
  expect(callbacks, isEmpty);
});
```

- [ ] **Step 2: Run the focused test and verify it fails**

```bash
flutter test --no-pub test/core/singbox/windows_latency_job_runner_test.dart
```

Expected: failure because `WindowsLatencyJobRunner` does not exist.

- [ ] **Step 3: Implement the runner**

Create `windows_latency_job_runner.dart`:

```dart
typedef WindowsLatencyJobInvoke = Future<Map<String, dynamic>> Function(
  String method,
  Map<String, dynamic> arguments,
);
typedef WindowsLatencyJobDelay = Future<void> Function(Duration duration);

final class WindowsLatencyJobRunner {
  WindowsLatencyJobRunner({
    required WindowsLatencyJobInvoke invoke,
    WindowsLatencyJobDelay? delay,
    this.pollInterval = const Duration(milliseconds: 250),
  })  : _invoke = invoke,
        _delay = delay ?? Future<void>.delayed;

  final WindowsLatencyJobInvoke _invoke;
  final WindowsLatencyJobDelay _delay;
  final Duration pollInterval;
  String? _activeRunId;

  Future<void> cancel() async {
    final runId = _activeRunId;
    if (runId == null) return;
    await _invoke('cancelLatencyTest', {'run_id': runId});
  }

  Future<Map<String, ConnectionLatencyResult>> run({
    required List<String> nodeTags,
    required String testUrl,
    required int timeoutMs,
    required int concurrency,
    required bool Function() isCancelled,
    ConnectionLatencyResultCallback? onResult,
  }) async {
    final start = WindowsServiceProtocol.parseLatencySnapshot(
      await _invoke('startLatencyTest', {
        'node_tags_json': jsonEncode(nodeTags),
        'test_url': testUrl,
        'timeout_ms': timeoutMs,
        'concurrency': concurrency,
      }),
    );
    final runId = start.runId;
    if (runId.isEmpty) {
      throw const ConnectionLatencyUnavailableException(
        'Windows 测速服务未返回任务编号',
      );
    }
    _activeRunId = runId;
    final published = <String>{};
    var snapshot = start;
    try {
      while (true) {
        if (isCancelled()) {
          await cancel();
          return Map<String, ConnectionLatencyResult>.unmodifiable(
            snapshot.results,
          );
        }
        for (final entry in snapshot.results.entries) {
          if (published.add(entry.key)) {
            onResult?.call(entry.key, entry.value);
          }
        }
        if (snapshot.isTerminal) {
          return Map<String, ConnectionLatencyResult>.unmodifiable(
            snapshot.results,
          );
        }
        await _delay(pollInterval);
        snapshot = WindowsServiceProtocol.parseLatencySnapshot(
          await _invoke('getLatencyTest', {'run_id': runId}),
        );
        if (snapshot.runId != runId) {
          throw const ConnectionLatencyUnavailableException(
            'Windows 测速任务已被替换',
          );
        }
      }
    } finally {
      if (_activeRunId == runId) {
        _activeRunId = null;
      }
    }
  }
}
```

The final implementation must publish successful and failed node results once,
throw on a terminal `error` snapshot, and synthesize `cancelled` results for
any missing tags when a cancelled job terminates.

- [ ] **Step 4: Run focused tests and formatter**

```bash
dart format lib/core/singbox/windows_latency_job_runner.dart \
  test/core/singbox/windows_latency_job_runner_test.dart
flutter test --no-pub test/core/singbox/windows_latency_job_runner_test.dart
```

Expected: all polling, progress, malformed snapshot, replacement, error, and
cancellation tests pass.

- [ ] **Step 5: Commit the Dart runner**

```bash
git add lib/core/singbox/windows_latency_job_runner.dart \
  test/core/singbox/windows_latency_job_runner_test.dart
git commit -m "feat: poll Windows service latency jobs"
```

### Task 6: Replace the 1.6.6 per-node Windows path

**Files:**
- Modify: `lib/core/singbox/windows_vpn_service.dart`
- Delete: `lib/core/singbox/windows_service_latency_runner.dart`
- Modify: `test/core/singbox/windows_vpn_service_test.dart`
- Delete: `test/core/singbox/windows_service_latency_runner_test.dart`
- Modify: `test/windows_distribution_contract_test.dart`

- [ ] **Step 1: Replace the existing superficial integration test**

Update the mocked channel so `startLatencyTest` returns a run ID and
`getLatencyTest` returns a completed two-node snapshot. Assert one batch start,
the unmodified URL/timeout/concurrency, and no per-node `urlTest` calls:

```dart
test('tests concrete nodes through one service-owned latency job', () async {
  messenger.setMockMethodCallHandler(methodChannel, (call) async {
    calls.add(call);
    if (call.method == 'getNetworkProfile') {
      return readyNetworkProfile;
    }
    if (call.method == 'startLatencyTest') {
      return {
        'run_id': 'run-1',
        'latency_test_status': 'running',
        'latency_completed': 0,
        'latency_total': 2,
        'latency_results_json': '{}',
      };
    }
    if (call.method == 'getLatencyTest') {
      return {
        'run_id': 'run-1',
        'latency_test_status': 'completed',
        'latency_completed': 2,
        'latency_total': 2,
        'latency_results_json': jsonEncode({
          'Tokyo': latencyResultJson(42, attempts: const [95, 42]),
          'Osaka': latencyResultJson(57, attempts: const [110, 57]),
        }),
      };
    }
    return {'status': call.method == 'stop' ? 'disconnected' : 'connected'};
  });

  final service = WindowsVpnService(latencyPollDelay: (_) async {});
  await service.start(validConfig);
  final results = await service.testConnectionLatencies(
    nodeTags: const ['Tokyo', 'Osaka'],
    testUrl: 'https://www.gstatic.com/generate_204',
    timeoutMs: 5000,
    concurrency: 4,
  );

  final start = calls.singleWhere((call) => call.method == 'startLatencyTest');
  final arguments = Map<String, dynamic>.from(start.arguments as Map);
  expect(jsonDecode(arguments['node_tags_json'] as String), ['Tokyo', 'Osaka']);
  expect(arguments['test_url'], 'https://www.gstatic.com/generate_204');
  expect(arguments['timeout_ms'], 5000);
  expect(arguments['concurrency'], 4);
  expect(calls.where((call) => call.method == 'urlTest'), isEmpty);
  expect(results['Tokyo']?.attempts, [95, 42]);
  expect(results['Osaka']?.latencyMs, 57);
  service.dispose();
});
```

Add a second test proving `stopConnectionLatencyTest` sends
`cancelLatencyTest` for the active run and suppresses late callbacks.

- [ ] **Step 2: Run focused tests and verify the old implementation fails**

```bash
flutter test --no-pub \
  test/core/singbox/windows_vpn_service_test.dart \
  test/windows_distribution_contract_test.dart
```

Expected: failures because the old service still calls `urlTest` per node.

- [ ] **Step 3: Integrate `WindowsLatencyJobRunner`**

In `WindowsVpnService`:

- add an optional test-only `latencyPollDelay` constructor argument;
- retain the active `WindowsLatencyJobRunner` while its run is in flight;
- create the runner with an invoker that calls `_invokeMap`;
- keep generation cancellation as a second stale-update guard; and
- make `stopConnectionLatencyTest` increment the generation and call
  `_latencyJobRunner?.cancel()`.

Replace:

```dart
final runner = WindowsServiceLatencyRunner(probe: urlTest);
```

with:

```dart
final runner = WindowsLatencyJobRunner(
  invoke: (method, arguments) => _invokeMap(method, arguments),
  delay: _latencyPollDelay,
);
```

Delete the obsolete service latency runner and its tests. Keep the legacy
`urlTest` method only for existing group-level APIs; connected node testing
must not call it.

Update the distribution contract:

```dart
expect(vpnService, contains('WindowsLatencyJobRunner'));
expect(vpnService, isNot(contains('WindowsServiceLatencyRunner')));
expect(vpnService, isNot(contains('WindowsLatencySession')));
expect(jobRunner, contains("'startLatencyTest'"));
expect(jobRunner, contains("'getLatencyTest'"));
expect(jobRunner, contains("'cancelLatencyTest'"));
expect(serviceProbe, contains('DisableKeepAlives: false'));
expect(serviceProbe, isNot(contains('curl.exe')));
```

- [ ] **Step 4: Run focused Windows Flutter regressions**

```bash
dart format lib/core/singbox/windows_vpn_service.dart \
  test/core/singbox/windows_vpn_service_test.dart \
  test/windows_distribution_contract_test.dart
flutter test --no-pub \
  test/core/singbox/windows_latency_job_runner_test.dart \
  test/core/singbox/windows_service_protocol_test.dart \
  test/core/singbox/windows_vpn_service_test.dart \
  test/core/singbox/windows_connection_probe_test.dart \
  test/core/singbox/macos_curl_connection_probe_test.dart \
  test/core/singbox/latency_test_policy_test.dart \
  test/providers/node_provider_latency_test.dart \
  test/windows_distribution_contract_test.dart
```

Expected: all tests pass; Windows and macOS fixtures both select the lower of
two valid transfer times.

- [ ] **Step 5: Commit the Windows integration**

```bash
git add lib/core/singbox/windows_vpn_service.dart \
  lib/core/singbox/windows_latency_job_runner.dart \
  test/core/singbox/windows_vpn_service_test.dart \
  test/windows_distribution_contract_test.dart
git add -u lib/core/singbox/windows_service_latency_runner.dart \
  test/core/singbox/windows_service_latency_runner_test.dart
git commit -m "fix: align Windows latency with macOS"
```

### Task 7: Version, documentation, full verification, and Windows release

**Files:**
- Modify: `pubspec.yaml`
- Modify: `windows/installer/ElephantNetwork.iss`
- Modify: `.github/workflows/windows-client.yml`
- Modify: `docs/windows-release.md`
- Modify: `test/windows_distribution_contract_test.dart`
- Generated by CI: `windows/installer/output/ElephantNetwork-Setup-x64-v1.6.7.exe`

- [ ] **Step 1: Update the failing release contract**

Change contract expectations first:

```dart
expect(installer, contains('#define AppVersion "1.6.7"'));
expect(installer, contains('#define AppBuild "10607"'));
expect(pubspec, contains('version: 1.6.7+10607'));
expect(workflow, contains('default: 1.6.7'));
expect(workflow, contains("default: '10607'"));
expect(guide, contains('two HTTP transfers over one reusable connection'));
expect(guide, contains('startLatencyTest'));
expect(guide, contains(r'C:\\ProgramData\\ElephantNetwork\\runtime'));
```

Run:

```bash
flutter test --no-pub test/windows_distribution_contract_test.dart
```

Expected: failure while files still declare 1.6.6.

- [ ] **Step 2: Update release metadata and support documentation**

Set:

```yaml
version: 1.6.7+10607
```

Update installer defaults and workflow inputs/environment to `1.6.7` and
`10607`. Document:

- the service-owned two-transfer/minimum measurement;
- the start/get/cancel diagnostic boundary;
- the exact runtime log paths;
- commands to collect `service.log` and `sing-box.log`;
- that `config.json` must never be requested from users; and
- that the installer remains unsigned.

- [ ] **Step 3: Run fresh complete local verification**

From `clients/elephant-route-deprecated`:

```bash
dart format --output=none --set-exit-if-changed lib test
flutter analyze
flutter test --no-pub
(cd windows/service_go && go test ./...)
(cd windows/service_go && go test -race ./...)
git diff --check
```

Expected:

- formatter exit 0;
- analyzer reports no issues;
- all Flutter tests pass, with only documented platform skips;
- Go normal and race suites pass;
- diff check reports no whitespace errors.

- [ ] **Step 4: Commit the release update**

```bash
git add pubspec.yaml \
  windows/installer/ElephantNetwork.iss \
  ../../.github/workflows/windows-client.yml \
  docs/windows-release.md \
  test/windows_distribution_contract_test.dart
git commit -m "chore: release Windows latency fix 1.6.7"
```

Run `git status --short` and confirm no task file is left uncommitted.

- [ ] **Step 5: Push the current master and trigger Windows CI**

From the repository root:

```bash
git push origin master
gh workflow run windows-client.yml \
  --ref master \
  -f version=1.6.7 \
  -f build_number=10607
```

Record the resulting run ID. Wait for the run and require success for:

- Go service tests;
- Flutter analyzer and full tests;
- Windows Release compilation;
- native C++ protocol tests;
- Inno Setup build;
- silent installation;
- LocalSystem `ElephantNetworkService` registration;
- absence of a legacy child sing-box process; and
- uninstall plus service/process cleanup.

- [ ] **Step 6: Download and verify the installer**

```bash
mkdir -p build/releases/windows/1.6.7
release_run_id="$(gh run list \
  --workflow windows-client.yml \
  --branch master \
  --limit 1 \
  --json databaseId \
  --jq '.[0].databaseId')"
test -n "$release_run_id"
gh run download "$release_run_id" \
  --name ElephantNetwork-Windows-x64-1.6.7 \
  --dir build/releases/windows/1.6.7
file build/releases/windows/1.6.7/ElephantNetwork-Setup-x64-v1.6.7.exe
shasum -a 256 build/releases/windows/1.6.7/ElephantNetwork-Setup-x64-v1.6.7.exe
```

Expected: a Windows PE installer with a non-zero size. Record its exact byte
size and SHA-256.

- [ ] **Step 7: Verify repository and release identity**

```bash
git status --short --branch
git rev-parse HEAD
git rev-parse origin/master
```

Expected: clean worktree and matching local/remote `master` commit IDs.

- [ ] **Step 8: Deliver evidence**

Report:

- root cause and the removed 1.6.6 path;
- Windows/macOS measurement parity contract;
- focused and full local test counts;
- commit IDs;
- Windows CI URL and every native release gate;
- clickable installer path;
- byte size and SHA-256; and
- the unsigned/SmartScreen warning.
