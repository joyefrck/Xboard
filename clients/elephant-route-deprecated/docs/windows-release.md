# Windows x64 release

The Windows client is built and packaged only on Windows 10/11 x64 with Visual
Studio 2022 Desktop development with C++, Go 1.25.8, Flutter, and Inno Setup 6.

## Production capabilities

The production package installs the desktop application, the bundled sing-box
tools, and `ElephantNetworkService`. The service links sing-box 1.12.25 and
hosts the production TUN core in the service process. It does not launch a
separate sing-box child for a normal connection. This removes the
LocalSystem-to-child startup boundary that could leave the core alive without
its local controller becoming ready on Windows 10. The service provides local
named-pipe IPC so a standard user can connect without approving a second UAC
prompt. The app also supports system proxy mode, tray controls, optional launch
at startup, and in-app update checks.

The in-process service is a separately licensed GPL-3.0-or-later component.
Its complete source and dependency lock files are in `windows/service_go`.
The Flutter application and its C++ pipe client remain separate processes
communicating through the stable `ElephantNetworkService.v1` IPC protocol.
Connected node latency tests call the in-process core through the named-pipe
service and do not launch a second sing-box or `curl.exe`. This keeps latency
testing compatible with the Windows 11 strict-route WFP rules. Version 1.6.7
starts one asynchronous service-owned job for the complete node list, with up
to four concurrent nodes. Each concrete outbound performs two sequential HTTP
requests through one reusable transport and reports the lower successful
latency, matching the macOS two-sample policy. The Flutter process only polls
short job snapshots, so slow Windows machines cannot serialize long named-pipe
calls until every per-node deadline expires. The standalone
`sing-box-windows-amd64.exe` remains packaged only for isolated configuration
checks.

The installer also deploys the Microsoft Visual C++ 2015-2022 x64 runtime.
Before starting TUN, the service selects an active physical IPv4 default
interface and a non-conflicting TUN subnet. Windows 10 uses explicit
interface binding with the strict-route WFP kill switch disabled; the client
reports an error instead of a false connected state when the interface or the
local sing-box control API is unavailable.

The installer supports fresh installation, in-place upgrades, and uninstall.
Uninstall stops the app, service, and core processes and restores only legacy
proxy settings owned by Elephant Network. Users can choose whether application
data is retained.

## Build

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\scripts\build_windows_release.ps1 -Version 1.6.7 -BuildNumber 10607
```

The application binaries, Windows service, and installer are intentionally
distributed without an Authenticode code signature. The build does not create,
import, or require a certificate. Windows may display an unknown-publisher or
SmartScreen warning during installation.

The downloaded Microsoft WebView2 bootstrapper is still required to have a
valid Microsoft signature. This uses Microsoft's certificate and does not
require an Elephant Network certificate.

The script prints the installer SHA-256 hash. Publish the resulting artifact
with `app_key=elephant-route-desktop`, `platform=windows`, `arch=x64`, and the
exact SHA-256 value. The update client rejects a downloaded artifact when its
hash does not match the release metadata.

## Publish checklist

1. Build the installer with the production version and build number.
2. Record the SHA-256 value printed by the build script.
3. Upload the `.exe` to the application distribution service.
4. Publish matching Windows x64 release metadata, including the exact hash.
5. Download and install through the same public URL used by the update client.
6. Complete the manual verification scenarios below before announcing the release.

## Manual verification

```powershell
Get-FileHash .\windows\installer\output\ElephantNetwork-Setup-x64-v1.6.7.exe -Algorithm SHA256
sc.exe query ElephantNetworkService
```

Verify fresh install, standard-user TUN connection without a second UAC prompt,
upgrade while disconnected, upgrade while connected, data-retaining uninstall,
default data-deleting uninstall, and absence of the app/service/core processes
after uninstall.

## Win10 startup diagnostics

`sing-box core startup timed out` means in-process core initialization did not
finish within 60 seconds. It is not a remote proxy-node connectivity result.
Current builds report separate error codes for rejected configuration, TUN
startup failure, and an in-process startup timeout. The service lifecycle log
contains only safe state transitions and error codes; it never contains the
subscription configuration.

For latency incidents, `service.log` records `latency_start`, `latency_node`,
`latency_complete`, and `latency_cancel`. The fields include only a shortened
run ID, node tag, two attempt values, elapsed milliseconds, failure category,
progress, timeout, and concurrency. Use these events to distinguish an HTTP
response failure, transport failure, timeout, cancellation, or unavailable
outbound without collecting the subscription configuration.

After reproducing the failure, run these commands in an elevated PowerShell:

```powershell
Get-Content "$env:ProgramData\ElephantNetwork\runtime\sing-box.log" -Tail 200
Get-Content "$env:ProgramData\ElephantNetwork\runtime\service.log" -Tail 100

$listener = Get-NetTCPConnection -State Listen -LocalPort 9090 -ErrorAction SilentlyContinue
$listener
if ($listener) {
  Get-Process -Id $listener.OwningProcess |
    Select-Object Id, ProcessName, Path
}

Get-Service ElephantNetworkService |
  Select-Object Name, Status, StartType
```

If the log is inconclusive, validate the generated configuration without
starting a connection:

```powershell
$env:ENABLE_DEPRECATED_SPECIAL_OUTBOUNDS = "true"
$env:ENABLE_DEPRECATED_LEGACY_DNS_SERVERS = "true"
$env:ENABLE_DEPRECATED_TUN_ADDRESS_X = "true"

& "C:\Program Files\ElephantNetwork\sing-box-windows-amd64.exe" check `
  -c "$env:ProgramData\ElephantNetwork\runtime\config.json"
```

Support requests may include the command output and `sing-box.log`. Never ask
users to upload `config.json`; it can contain proxy credentials.
