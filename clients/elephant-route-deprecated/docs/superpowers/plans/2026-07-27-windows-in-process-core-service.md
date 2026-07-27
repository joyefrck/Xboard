# Windows In-Process Core Service Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Windows C++ child-process broker with a Windows SCM service that hosts sing-box 1.12.25 in-process and preserves the existing Flutter named-pipe contract.

**Architecture:** A focused Go module builds `ElephantNetworkService.exe`, implements the existing framed JSON pipe protocol, and owns an in-process `box.Box` lifecycle. Platform-neutral protocol, state-machine, route-selection, and core-lifecycle code is unit tested on macOS and Windows; Windows-only files provide SCM, named-pipe, route-table, and service-host adapters. The Flutter runner keeps the same pipe client and the installer keeps the same service identity.

**Tech Stack:** Go 1.25.8, sing-box 1.12.25, `golang.org/x/sys/windows`, `github.com/Microsoft/go-winio`, Windows SCM, Flutter 3.38.7, CMake, Inno Setup 6, GitHub Actions Windows 2022.

---

## File Structure

Create `windows/service_go` as a separately licensed Go program:

- `LICENSE`: GPL-3.0 text covering the statically linked service component.
- `go.mod` / `go.sum`: pinned service dependencies.
- `main.go`: console-versus-SCM entrypoint.
- `protocol.go`: framed JSON request and response types.
- `state.go`: serialized runtime state and public status snapshots.
- `core.go`: injectable in-process core lifecycle.
- `core_singbox.go`: sing-box option decoding, `box.New`, `Start`, and `Close`.
- `runtime_files.go`: bounded config persistence and trusted SRS asset copying.
- `lifecycle_log.go`: credential-safe service lifecycle logging.
- `network_profile.go`: platform-neutral route candidate selection.
- `network_profile_windows.go`: Windows route-table and OS-version adapter.
- `pipe_windows.go`: named-pipe server with the existing ACL and frame limits.
- `service_windows.go`: SCM handler, watchdog, and shutdown.
- `http_client.go`: fixed localhost Clash API forwarding.
- corresponding `_test.go` files for every platform-neutral unit.

Modify build and client integration:

- `windows/service/CMakeLists.txt`: build the Go service instead of C++ `service_main.cpp`.
- `windows/CMakeLists.txt`: install the generated Go executable.
- `windows/runner/windows_service_bridge.cpp`: map the new startup timeout safely.
- `lib/core/singbox/windows_service_protocol.dart`: accept the new error code.
- `test/core/singbox/windows_service_protocol_test.dart`: regression coverage.
- `test/windows_distribution_contract_test.dart`: assert production TUN no longer uses a child process.
- `scripts/build_windows_release.ps1`: run Go tests and build checks.
- `.github/workflows/windows-client.yml`: install pinned Go, cache modules, test the service, and extend installer smoke checks.
- `windows/installer/ElephantNetwork.iss`: stop old processes during upgrade while retaining the stable service identity.
- `docs/windows-release.md`: document the in-process service and component license.

Delete after the replacement passes Windows CI:

- `windows/service/service_main.cpp`
- `windows/common/windows_core_diagnostics.cpp`
- `windows/common/windows_core_diagnostics.h`
- `windows/tests/windows_core_diagnostics_test.cpp`

Keep `sing-box-windows-amd64.exe` in the first migration package because the
latency and local `check` paths must be audited independently.

### Task 1: Establish the Go service protocol and license boundary

**Files:**
- Create: `clients/elephant-route-deprecated/windows/service_go/LICENSE`
- Create: `clients/elephant-route-deprecated/windows/service_go/go.mod`
- Create: `clients/elephant-route-deprecated/windows/service_go/protocol.go`
- Create: `clients/elephant-route-deprecated/windows/service_go/protocol_test.go`

- [ ] **Step 1: Add failing protocol tests**

Test exact protocol compatibility, message limits, and JSON safety:

```go
func TestDecodeRequestRejectsWrongVersion(t *testing.T) {
    _, err := decodeRequest([]byte(`{"version":2,"method":"getStatus"}`))
    if !errors.Is(err, errUnsupportedProtocol) {
        t.Fatalf("expected protocol error, got %v", err)
    }
}

func TestDecodeRequestPreservesEscapedConfig(t *testing.T) {
    raw := []byte(`{"version":1,"method":"start","config":"{\"inbounds\":[{\"type\":\"tun\"}]}"}`)
    request, err := decodeRequest(raw)
    if err != nil {
        t.Fatal(err)
    }
    if request.Config != `{"inbounds":[{"type":"tun"}]}` {
        t.Fatalf("unexpected config %q", request.Config)
    }
}

func TestStatusJSONDoesNotExposeRawError(t *testing.T) {
    response := errorResponse("core_config_invalid", `bad "uuid":"secret"`)
    encoded, err := json.Marshal(response)
    if err != nil {
        t.Fatal(err)
    }
    if bytes.Contains(encoded, []byte(`uuid`)) {
        t.Fatalf("response leaked raw error: %s", encoded)
    }
}
```

- [ ] **Step 2: Run the tests and verify red state**

Run:

```bash
cd clients/elephant-route-deprecated/windows/service_go
go test ./...
```

Expected: FAIL because the module and protocol functions do not exist.

- [ ] **Step 3: Add the pinned module and protocol implementation**

Use:

```go
module github.com/joyefrck/xboard/elephant-network-service

go 1.25.0

require (
    github.com/Microsoft/go-winio v0.6.2
    github.com/sagernet/sing-box v1.12.25
    github.com/sagernet/sing v0.7.18
    golang.org/x/sys v0.35.0
)
```

Implement strict typed requests:

```go
const (
    protocolVersion = 1
    maxConfigBytes  = 4 * 1024 * 1024
    maxMessageBytes = 5 * 1024 * 1024
)

type request struct {
    Version     int    `json:"version"`
    Method      string `json:"method"`
    Config      string `json:"config,omitempty"`
    GroupTag    string `json:"group_tag,omitempty"`
    OutboundTag string `json:"outbound_tag,omitempty"`
}

var allowedMethods = map[string]struct{}{
    "getStatus": {}, "getNetworkProfile": {}, "start": {},
    "prepareSpeedTest": {}, "stop": {}, "stopSpeedTest": {},
    "urlTest": {}, "selectOutbound": {},
}
```

Reject unknown JSON fields, unsupported versions, disallowed methods, messages
above five MiB, and configs above four MiB. Map internal errors to fixed safe
messages rather than returning raw decoder text.

- [ ] **Step 4: Add the service component GPL-3.0 license**

Copy the GPL-3.0 license text used by sing-box into
`windows/service_go/LICENSE`. Add a header to each Go file:

```go
// SPDX-License-Identifier: GPL-3.0-or-later
```

- [ ] **Step 5: Run tests and commit**

Run:

```bash
go test ./...
git diff --check
```

Expected: PASS.

Commit:

```bash
git add clients/elephant-route-deprecated/windows/service_go
git commit -m "feat: establish Windows core service protocol"
```

### Task 2: Implement the serialized core lifecycle

**Files:**
- Create: `clients/elephant-route-deprecated/windows/service_go/state.go`
- Create: `clients/elephant-route-deprecated/windows/service_go/state_test.go`
- Create: `clients/elephant-route-deprecated/windows/service_go/core.go`
- Create: `clients/elephant-route-deprecated/windows/service_go/core_test.go`
- Create: `clients/elephant-route-deprecated/windows/service_go/runtime_files.go`
- Create: `clients/elephant-route-deprecated/windows/service_go/runtime_files_test.go`
- Create: `clients/elephant-route-deprecated/windows/service_go/lifecycle_log.go`
- Create: `clients/elephant-route-deprecated/windows/service_go/lifecycle_log_test.go`

- [ ] **Step 1: Write failing lifecycle tests**

Define a fake instance and assert idempotency, serialization, cancellation, and
safe timeout handling:

```go
func TestManagerStartStopAndRepeatedStop(t *testing.T) {
    factory := &fakeFactory{}
    manager := newCoreManager(factory, time.Second)
    result := manager.start(context.Background(), validTunConfig, false)
    if result.Status != statusConnected || factory.starts != 1 {
        t.Fatalf("unexpected start result: %#v starts=%d", result, factory.starts)
    }
    manager.stop()
    manager.stop()
    if factory.closes != 1 || manager.snapshot().Status != statusDisconnected {
        t.Fatalf("stop was not idempotent")
    }
}

func TestManagerCancelsBlockedStart(t *testing.T) {
    factory := &fakeFactory{blockStart: true}
    manager := newCoreManager(factory, 25*time.Millisecond)
    result := manager.start(context.Background(), validTunConfig, false)
    if result.ErrorCode != "core_start_timeout" || !factory.cancelled {
        t.Fatalf("unexpected timeout result: %#v", result)
    }
}

func TestPrepareRuntimeCopiesOnlyTrustedAssets(t *testing.T) {
    source := t.TempDir()
    runtimeDir := t.TempDir()
    writeTestFile(t, filepath.Join(source, "geoip-cn.srs"), "geoip")
    writeTestFile(t, filepath.Join(source, "geosite-cn.srs"), "geosite")
    if err := prepareRuntimeFiles(source, runtimeDir, validTunConfig); err != nil {
        t.Fatal(err)
    }
    assertFileContent(t, filepath.Join(runtimeDir, "config.json"), validTunConfig)
    assertFileContent(t, filepath.Join(runtimeDir, "geoip-cn.srs"), []byte("geoip"))
    assertFileContent(t, filepath.Join(runtimeDir, "geosite-cn.srs"), []byte("geosite"))
}

func TestLifecycleLogRedactsConfiguration(t *testing.T) {
    var output bytes.Buffer
    logger := newLifecycleLogger(&output)
    logger.transition("core_starting", "core_config_invalid", 250*time.Millisecond)
    if strings.Contains(output.String(), "uuid") ||
        strings.Contains(output.String(), "password") {
        t.Fatalf("lifecycle log leaked credentials: %q", output.String())
    }
}
```

- [ ] **Step 2: Run the focused tests and verify failure**

Run:

```bash
go test ./... -run 'TestManager'
```

Expected: FAIL because `coreManager` is undefined.

- [ ] **Step 3: Implement a narrow core interface and state machine**

Use these boundaries:

```go
type coreInstance interface {
    Start() error
    Close() error
}

type coreFactory interface {
    New(context.Context, []byte, string) (coreInstance, error)
    Version() string
}

type coreManager struct {
    mu             sync.Mutex
    factory        coreFactory
    instance       coreInstance
    cancel         context.CancelFunc
    state          runtimeState
    startupTimeout time.Duration
    speedTest      bool
}
```

Hold the mutex across lifecycle transitions so two pipe clients cannot create
two cores. Set `core_pid` to `os.Getpid()` only after startup succeeds. Redact
all factory errors through `classifyCoreError`.

Before constructing the core, write the bounded configuration to
`C:\ProgramData\ElephantNetwork\runtime\config.json` and copy only
`geoip-cn.srs` and `geosite-cn.srs` from the installed Flutter asset directory.
Use create-then-rename for the config file so the service never observes a
partial write. The lifecycle logger records only timestamp, state, safe error
code, and elapsed duration.

- [ ] **Step 4: Run all Go tests and commit**

Run:

```bash
go test ./...
go test -race ./...
```

Expected: PASS on the host-platform files.

Commit:

```bash
git add clients/elephant-route-deprecated/windows/service_go
git commit -m "feat: add in-process core lifecycle"
```

### Task 3: Bind sing-box 1.12.25 in-process

**Files:**
- Create: `clients/elephant-route-deprecated/windows/service_go/core_singbox.go`
- Create: `clients/elephant-route-deprecated/windows/service_go/core_singbox_test.go`

- [ ] **Step 1: Add failing decode and classification tests**

Cover a minimal non-TUN config for constructor testing, explicit unknown fields,
and warning-only compatibility input:

```go
func TestSingBoxFactoryRejectsUnknownFieldSafely(t *testing.T) {
    factory := singBoxFactory{}
    _, err := factory.New(context.Background(),
        []byte(`{"inbounds":[],"unknown_field":true}`), t.TempDir())
    failure := classifyCoreError(err)
    if failure.Code != "core_config_invalid" {
        t.Fatalf("unexpected failure: %#v", failure)
    }
    if strings.Contains(failure.Message, "unknown_field") {
        t.Fatalf("unsafe decoder detail leaked: %q", failure.Message)
    }
}
```

- [ ] **Step 2: Verify the new test fails**

Run:

```bash
go test ./... -run 'TestSingBoxFactory'
```

Expected: FAIL because `singBoxFactory` is undefined.

- [ ] **Step 3: Implement the sing-box factory**

Initialize the same registries as the official CLI:

```go
func singBoxContext(parent context.Context) context.Context {
    manager := deprecated.NewStderrManager(log.StdLogger())
    return include.Context(service.ContextWith(parent, manager))
}

func (singBoxFactory) New(
    parent context.Context,
    config []byte,
    runtimeDir string,
) (coreInstance, error) {
    _ = os.Setenv("ENABLE_DEPRECATED_SPECIAL_OUTBOUNDS", "true")
    _ = os.Setenv("ENABLE_DEPRECATED_LEGACY_DNS_SERVERS", "true")
    _ = os.Setenv("ENABLE_DEPRECATED_TUN_ADDRESS_X", "true")

    ctx := singBoxContext(parent)
    options, err := json.UnmarshalExtendedContext[option.Options](ctx, config)
    if err != nil {
        return nil, fmt.Errorf("decode config: %w", err)
    }
    if options.Log == nil {
        options.Log = &option.LogOptions{}
    }
    options.Log.Output = filepath.Join(runtimeDir, "sing-box.log")
    options.Log.DisableColor = true
    instance, err := box.New(box.Options{Context: ctx, Options: options})
    if err != nil {
        return nil, fmt.Errorf("create service: %w", err)
    }
    return instance, nil
}
```

Build with:

```text
with_gvisor,with_quic,with_dhcp,with_wireguard,with_ech,with_utls,
with_reality_server,with_acme,with_clash_api,with_tailscale
```

- [ ] **Step 4: Verify tests and binary metadata**

Run:

```bash
go test -tags "with_gvisor with_quic with_dhcp with_wireguard with_ech with_utls with_reality_server with_acme with_clash_api with_tailscale" ./...
GOOS=windows GOARCH=amd64 CGO_ENABLED=0 go build \
  -tags "with_gvisor with_quic with_dhcp with_wireguard with_ech with_utls with_reality_server with_acme with_clash_api with_tailscale" \
  -o /tmp/ElephantNetworkService.exe .
go version -m /tmp/ElephantNetworkService.exe
```

Expected: tests PASS; metadata contains `github.com/sagernet/sing-box v1.12.25`.

- [ ] **Step 5: Commit**

```bash
git add clients/elephant-route-deprecated/windows/service_go
git commit -m "feat: host sing-box in Windows service process"
```

### Task 4: Port network-profile selection with fixture tests

**Files:**
- Create: `clients/elephant-route-deprecated/windows/service_go/network_profile.go`
- Create: `clients/elephant-route-deprecated/windows/service_go/network_profile_test.go`
- Create: `clients/elephant-route-deprecated/windows/service_go/network_profile_windows.go`
- Create: `clients/elephant-route-deprecated/windows/service_go/network_profile_stub.go`

- [ ] **Step 1: Add failing route-selection tests**

Represent route observations without Windows structs:

```go
func TestSelectNetworkProfilePrefersHardwareAndAvoidsOverlap(t *testing.T) {
    routes := []routeObservation{
        {Prefix: "0.0.0.0/0", Alias: "VPN", Metric: 1, Up: true, Tunnel: true},
        {Prefix: "0.0.0.0/0", Alias: "Ethernet", Metric: 25, Up: true, Hardware: true},
        {Prefix: "172.31.255.0/30", Alias: "Existing", Up: true},
    }
    profile, ok := selectNetworkProfile(routes, 19045)
    if !ok {
        t.Fatal("profile not selected")
    }
    if profile.DefaultInterface != "Ethernet" ||
        profile.TunIPv4Address != "172.30.255.1/30" ||
        profile.StrictRoute {
        t.Fatalf("unexpected profile: %#v", profile)
    }
}
```

- [ ] **Step 2: Verify red state**

Run:

```bash
go test ./... -run 'TestSelectNetworkProfile'
```

Expected: FAIL because selection types do not exist.

- [ ] **Step 3: Implement pure selection**

Preserve the candidate order:

```go
var tunCandidates = []string{
    "172.31.255.1/30",
    "172.30.255.1/30",
    "198.18.0.1/30",
    "10.255.255.1/30",
}
```

Ignore loopback, tunnel, down, and `ElephantNetwork` interfaces; prefer hardware
default routes and then the lowest combined metric. Enable strict routing only
when the Windows build is at least 22000.

- [ ] **Step 4: Implement the Windows adapter**

Use `iphlpapi.dll` procedures `GetIpForwardTable2`, `GetIfEntry2`,
`GetIpInterfaceEntry`, and `FreeMibTable`, with struct layouts matching
`netioapi.h`. Convert each native row into `routeObservation` immediately and
free the table before selection. Use `RtlGetVersion` for the real build number.

The non-Windows stub returns `errWindowsOnly` so host tests compile.

- [ ] **Step 5: Run host tests, cross-build, and commit**

Run:

```bash
go test ./...
GOOS=windows GOARCH=amd64 CGO_ENABLED=0 go test -c -o /tmp/service-go.test.exe .
```

Expected: PASS and successful Windows test-binary compilation.

Commit:

```bash
git add clients/elephant-route-deprecated/windows/service_go
git commit -m "feat: port Windows network profile selection"
```

### Task 5: Implement named-pipe IPC and Windows SCM hosting

**Files:**
- Create: `clients/elephant-route-deprecated/windows/service_go/http_client.go`
- Create: `clients/elephant-route-deprecated/windows/service_go/http_client_test.go`
- Create: `clients/elephant-route-deprecated/windows/service_go/pipe_windows.go`
- Create: `clients/elephant-route-deprecated/windows/service_go/service_windows.go`
- Create: `clients/elephant-route-deprecated/windows/service_go/main.go`

- [ ] **Step 1: Add failing request-dispatch tests**

Inject the core manager, profile provider, and HTTP client:

```go
func TestDispatcherAllowsOnlyFixedClashPaths(t *testing.T) {
    fakeHTTP := &recordingHTTPClient{}
    dispatcher := newDispatcher(newCoreManager(&fakeFactory{}, time.Second),
        staticProfileProvider{}, fakeHTTP)
    response := dispatcher.handle(request{
        Version:  protocolVersion,
        Method:   "urlTest",
        GroupTag: `select/../../secret`,
    })
    if response.Status == statusError || fakeHTTP.lastHost != "127.0.0.1:9090" {
        t.Fatalf("unsafe or failed request: %#v %#v", response, fakeHTTP)
    }
}
```

- [ ] **Step 2: Verify the dispatcher test fails**

Run:

```bash
go test ./... -run 'TestDispatcher'
```

Expected: FAIL because dispatcher and HTTP interfaces are undefined.

- [ ] **Step 3: Implement dispatch and fixed localhost HTTP**

Use an `http.Client` with no proxy and bounded timeouts:

```go
transport := http.DefaultTransport.(*http.Transport).Clone()
transport.Proxy = nil
client := &http.Client{Transport: transport, Timeout: 3 * time.Second}
```

Construct paths with `url.PathEscape`, never accept a host, scheme, or raw path
from IPC, and support only the current URL-test GET and outbound-selection PUT.

- [ ] **Step 4: Implement the named-pipe server**

Listen on `\\.\pipe\ElephantNetworkService.v1` using
`winio.ListenPipe` with an SDDL equivalent to:

```text
D:P(A;;GA;;;SY)(A;;GA;;;BA)(A;;GRGW;;;IU)
```

Read and write the existing four-byte little-endian length prefix. Apply the
five-MiB frame limit before allocation. Handle one request per connection and
reject remote clients.

- [ ] **Step 5: Implement SCM lifecycle and heartbeat watchdog**

Use `svc.Run("ElephantNetworkService", handler)`. Report
`svc.StartPending`, then `svc.Running`; accept Stop and Shutdown. On control
events, close the pipe listener, stop the core normally, and report
`svc.Stopped`.

Update the heartbeat on every valid pipe request. If a connected core has no
client request for 15 seconds, set `client_gone` and stop it through the same
normal close path, matching the existing service behavior.

When `svc.IsWindowsService()` is false, `main` runs the same handler through
`svc/debug.Run` for CI smoke testing rather than returning SCM error 1063.

- [ ] **Step 6: Run tests, cross-build, and commit**

Run:

```bash
go test -race ./...
GOOS=windows GOARCH=amd64 CGO_ENABLED=0 go build \
  -tags "with_gvisor with_quic with_dhcp with_wireguard with_ech with_utls with_reality_server with_acme with_clash_api with_tailscale" \
  -o /tmp/ElephantNetworkService.exe .
```

Expected: PASS and a PE32+ x64 service executable.

Commit:

```bash
git add clients/elephant-route-deprecated/windows/service_go
git commit -m "feat: add Windows SCM and named-pipe host"
```

### Task 6: Replace the C++ service build and lock the distribution contract

**Files:**
- Modify: `clients/elephant-route-deprecated/windows/service/CMakeLists.txt`
- Modify: `clients/elephant-route-deprecated/windows/CMakeLists.txt`
- Delete: `clients/elephant-route-deprecated/windows/service/service_main.cpp`
- Delete: `clients/elephant-route-deprecated/windows/common/windows_core_diagnostics.cpp`
- Delete: `clients/elephant-route-deprecated/windows/common/windows_core_diagnostics.h`
- Delete: `clients/elephant-route-deprecated/windows/tests/windows_core_diagnostics_test.cpp`
- Modify: `clients/elephant-route-deprecated/windows/tests/CMakeLists.txt`
- Modify: `clients/elephant-route-deprecated/test/windows_distribution_contract_test.dart`

- [ ] **Step 1: Add a failing distribution test**

Require the Go service source and prohibit the old production launch path:

```dart
test('Windows TUN core is hosted by the service process', () {
  final goModule = File('windows/service_go/go.mod').readAsStringSync();
  final legacyService = File('windows/service/service_main.cpp');
  expect(goModule, contains('github.com/sagernet/sing-box v1.12.25'));
  expect(legacyService.existsSync(), isFalse);
});
```

- [ ] **Step 2: Run the focused test and verify failure**

Run:

```bash
flutter test test/windows_distribution_contract_test.dart
```

Expected: FAIL while the C++ service source still exists.

- [ ] **Step 3: Replace the CMake target**

Create a custom command that builds the service once per configuration:

```cmake
set(ELEPHANT_SERVICE_EXE
  "${CMAKE_CURRENT_BINARY_DIR}/ElephantNetworkService.exe")
add_custom_command(
  OUTPUT "${ELEPHANT_SERVICE_EXE}"
  COMMAND "${CMAKE_COMMAND}" -E env
    "CGO_ENABLED=0" "GOOS=windows" "GOARCH=amd64"
    go build
    -trimpath
    -tags "with_gvisor,with_quic,with_dhcp,with_wireguard,with_ech,with_utls,with_reality_server,with_acme,with_clash_api,with_tailscale"
    -o "${ELEPHANT_SERVICE_EXE}"
    .
  WORKING_DIRECTORY "${CMAKE_CURRENT_SOURCE_DIR}/../service_go"
  DEPENDS ${ELEPHANT_SERVICE_GO_SOURCES}
  VERBATIM)
add_custom_target(ElephantNetworkService ALL
  DEPENDS "${ELEPHANT_SERVICE_EXE}")
```

Install the generated file with `install(PROGRAMS ...)`. Remove the C++ service
and classifier target while retaining `windows_protocol.cpp` for the runner
pipe client.

- [ ] **Step 4: Run focused contracts and commit**

Run:

```bash
flutter test test/windows_distribution_contract_test.dart
git diff --check
```

Expected: PASS.

Commit:

```bash
git add clients/elephant-route-deprecated/windows \
  clients/elephant-route-deprecated/test/windows_distribution_contract_test.dart
git commit -m "build: replace Windows child-process service"
```

### Task 7: Preserve Flutter errors and installer upgrade behavior

**Files:**
- Modify: `clients/elephant-route-deprecated/lib/core/singbox/windows_service_protocol.dart`
- Modify: `clients/elephant-route-deprecated/test/core/singbox/windows_service_protocol_test.dart`
- Modify: `clients/elephant-route-deprecated/windows/runner/windows_service_bridge.cpp`
- Modify: `clients/elephant-route-deprecated/windows/installer/ElephantNetwork.iss`

- [ ] **Step 1: Add a failing Flutter protocol test**

```dart
test('maps in-process startup timeout without configuration blame', () {
  final failure = WindowsServiceProtocol.failureFromResponse({
    'status': 'error',
    'error_code': 'core_start_timeout',
    'error_message': 'sing-box core startup timed out.',
  });
  expect(failure.category, WindowsServiceFailureCategory.coreStartup);
  expect(failure.message, contains('启动'));
  expect(failure.message, isNot(contains('配置')));
});
```

- [ ] **Step 2: Verify the focused test fails**

Run:

```bash
flutter test test/core/singbox/windows_service_protocol_test.dart
```

Expected: FAIL until the error code is mapped.

- [ ] **Step 3: Add the stable mapping and upgrade cleanup**

Map `core_start_timeout` to a safe core-start category. Remove references to a
child exit code from new in-process failures while still decoding
`core_exit_code` from older installed services during upgrade.

Before overwriting the old service, the installer must stop
`ElephantNetworkService`, terminate only the legacy
`sing-box-windows-amd64.exe` child if present, update the service binary path,
and start the new service. Do not kill unrelated proxy executables.

- [ ] **Step 4: Run tests and commit**

Run:

```bash
flutter test test/core/singbox/windows_service_protocol_test.dart
flutter test test/windows_distribution_contract_test.dart
```

Expected: PASS.

Commit:

```bash
git add clients/elephant-route-deprecated/lib/core/singbox/windows_service_protocol.dart \
  clients/elephant-route-deprecated/test/core/singbox/windows_service_protocol_test.dart \
  clients/elephant-route-deprecated/windows/runner/windows_service_bridge.cpp \
  clients/elephant-route-deprecated/windows/installer/ElephantNetwork.iss
git commit -m "fix: preserve Windows service upgrade contract"
```

### Task 8: Integrate Go verification into release automation

**Files:**
- Modify: `clients/elephant-route-deprecated/scripts/build_windows_release.ps1`
- Modify: `.github/workflows/windows-client.yml`
- Modify: `clients/elephant-route-deprecated/docs/windows-release.md`

- [ ] **Step 1: Add Go tests to the release script**

Before Flutter build:

```powershell
$ServiceDir = Join-Path $ClientRoot 'windows\service_go'
Push-Location $ServiceDir
try {
  go version
  Assert-NativeSuccess 'go version'
  go test ./...
  Assert-NativeSuccess 'Go service tests'
} finally {
  Pop-Location
}
```

- [ ] **Step 2: Pin Go and cache modules in GitHub Actions**

Add:

```yaml
- uses: actions/setup-go@v5
  with:
    go-version: '1.25.8'
    cache-dependency-path: clients/elephant-route-deprecated/windows/service_go/go.sum

- name: Go service tests
  shell: pwsh
  run: go test ./...
  working-directory: clients/elephant-route-deprecated/windows/service_go
```

- [ ] **Step 3: Extend installer smoke assertions**

After installation, verify:

```powershell
$service = Get-CimInstance Win32_Service -Filter "Name='ElephantNetworkService'"
if ($service.StartName -ne 'LocalSystem') { throw 'Unexpected service account' }
if ($service.PathName -notmatch 'ElephantNetworkService.exe') {
  throw 'Unexpected service binary'
}
$legacy = Get-Process -Name 'sing-box-windows-amd64' -ErrorAction SilentlyContinue
if ($legacy) { throw 'Legacy child core is running after service installation' }
```

Retain existing uninstall verification.

- [ ] **Step 4: Update release documentation**

Document that the service component statically links GPL-3.0 sing-box, its
source resides in `windows/service_go`, normal TUN startup is in-process, the
standalone binary is retained only for isolated tools in the first migration
release, and runtime acceptance on Windows 10 remains distinct from CI.

- [ ] **Step 5: Run local verification and commit**

Run:

```bash
cd clients/elephant-route-deprecated/windows/service_go
go test -race ./...
cd ../..
flutter analyze
flutter test --no-pub
git diff --check
```

Expected: Go tests, analyzer, and Flutter tests PASS.

Commit:

```bash
git add .github/workflows/windows-client.yml \
  clients/elephant-route-deprecated/scripts/build_windows_release.ps1 \
  clients/elephant-route-deprecated/docs/windows-release.md
git commit -m "ci: verify in-process Windows core service"
```

### Task 9: Build, smoke-test, and package the Windows release

**Files:**
- Modify only if verification exposes a defect in files already listed above.
- Record: `clients/elephant-route-deprecated/docs/superpowers/plans/2026-07-27-windows-in-process-core-service.md`

- [ ] **Step 1: Push the scoped commits to the current branch**

Run:

```bash
git status --short
git push origin master
```

Expected: clean worktree and successful push.

- [ ] **Step 2: Dispatch the Windows workflow**

Run:

```bash
gh workflow run windows-client.yml \
  --ref master \
  -f version=1.6.4 \
  -f build_number=10604
```

- [ ] **Step 3: Inspect every Windows job and log**

Run:

```bash
gh run list --workflow windows-client.yml --limit 1
gh run watch <run-id> --exit-status
gh run view <run-id> --log
```

Expected:

- Go tests PASS;
- Flutter analyzer and tests PASS;
- Windows build PASS;
- native protocol tests PASS;
- installer creation PASS;
- install/service/uninstall smoke PASS.

- [ ] **Step 4: Download and hash the installer**

Run:

```bash
gh run download <run-id> \
  --name ElephantNetwork-Windows-x64-1.6.4 \
  --dir clients/elephant-route-deprecated/build/releases/windows/1.6.4
shasum -a 256 \
  clients/elephant-route-deprecated/build/releases/windows/1.6.4/ElephantNetwork-Setup-x64-v1.6.4.exe
```

- [ ] **Step 5: Record verified evidence and commit**

Mark completed checkboxes only for commands whose output was observed. Append
the workflow URL, job results, artifact path, and SHA-256 to this plan.

Commit:

```bash
git add clients/elephant-route-deprecated/docs/superpowers/plans/2026-07-27-windows-in-process-core-service.md
git commit -m "docs: record Windows core service verification"
git push origin master
```

- [ ] **Step 6: Final handoff**

Report the absolute installer path, SHA-256, unsigned/SmartScreen status,
workflow URL, and the architectural change. State that automated verification
passed without claiming Win10 runtime acceptance until the user installs the
final package.
