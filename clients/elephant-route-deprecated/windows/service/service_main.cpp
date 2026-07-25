#include <winsock2.h>
#include <ws2ipdef.h>
#include <windows.h>
#include <iphlpapi.h>
#include <sddl.h>
#include <shlobj.h>
#include <winhttp.h>

#include <atomic>
#include <algorithm>
#include <chrono>
#include <cwchar>
#include <filesystem>
#include <fstream>
#include <limits>
#include <mutex>
#include <optional>
#include <string>
#include <thread>
#include <vector>

#include "windows_core_diagnostics.h"
#include "windows_protocol.h"

namespace {

SERVICE_STATUS_HANDLE g_status_handle = nullptr;
SERVICE_STATUS g_status{};
HANDLE g_stop_event = nullptr;
HANDLE g_core_process = nullptr;
HANDLE g_core_job = nullptr;
DWORD g_core_pid = 0;
std::mutex g_state_mutex;
std::string g_runtime_status = "disconnected";
std::string g_error_code;
std::string g_error_message;
std::optional<DWORD> g_core_exit_code;
std::atomic<ULONGLONG> g_last_client_tick{0};
std::atomic<bool> g_speed_test{false};

struct NetworkProfile {
  std::string default_interface;
  std::string tun_ipv4_address;
  bool strict_route = false;
};

bool IsWindows11OrGreater() {
  using RtlGetVersionFunction = LONG(WINAPI*)(OSVERSIONINFOW*);
  const auto ntdll = GetModuleHandleW(L"ntdll.dll");
  const auto rtl_get_version = ntdll
                                   ? reinterpret_cast<RtlGetVersionFunction>(
                                         GetProcAddress(ntdll, "RtlGetVersion"))
                                   : nullptr;
  if (!rtl_get_version) return false;
  OSVERSIONINFOW version{};
  version.dwOSVersionInfoSize = sizeof(version);
  return rtl_get_version(&version) == 0 && version.dwBuildNumber >= 22000;
}

void ApplySingBoxCompatibilityEnvironment() {
  // sing-box 1.12 keeps legacy subscription formats behind compatibility
  // switches. The API can still return these fields for AnyTLS and older DNS
  // or TUN configurations, so every child core must receive the same switches
  // as the macOS runtime.
  SetEnvironmentVariableW(L"ENABLE_DEPRECATED_SPECIAL_OUTBOUNDS", L"true");
  SetEnvironmentVariableW(L"ENABLE_DEPRECATED_LEGACY_DNS_SERVERS", L"true");
  SetEnvironmentVariableW(L"ENABLE_DEPRECATED_TUN_ADDRESS_X", L"true");
}

std::string CoreVersion() {
  static const std::string version = [] {
    const auto path = std::filesystem::path(elephant::ExecutableDirectory()) /
                      L"sing-box-windows-amd64.exe";
    DWORD ignored = 0;
    const DWORD size = GetFileVersionInfoSizeW(path.c_str(), &ignored);
    if (size == 0) return std::string("bundled");
    std::vector<BYTE> data(size);
    if (!GetFileVersionInfoW(path.c_str(), 0, size, data.data())) {
      return std::string("bundled");
    }
    struct Translation { WORD language; WORD code_page; };
    Translation* translation = nullptr;
    UINT translation_size = 0;
    if (!VerQueryValueW(data.data(), L"\\VarFileInfo\\Translation",
                        reinterpret_cast<void**>(&translation),
                        &translation_size) ||
        translation_size < sizeof(Translation)) {
      return std::string("bundled");
    }
    wchar_t query[64]{};
    swprintf_s(query, L"\\StringFileInfo\\%04x%04x\\ProductVersion",
               translation->language, translation->code_page);
    wchar_t* value = nullptr;
    UINT value_size = 0;
    if (!VerQueryValueW(data.data(), query,
                        reinterpret_cast<void**>(&value), &value_size) ||
        !value || value_size == 0) {
      return std::string("bundled");
    }
    return elephant::WideToUtf8(value);
  }();
  return version;
}

void SetServiceState(DWORD state, DWORD error = NO_ERROR) {
  g_status.dwServiceType = SERVICE_WIN32_OWN_PROCESS;
  g_status.dwCurrentState = state;
  g_status.dwWin32ExitCode = error;
  g_status.dwControlsAccepted =
      state == SERVICE_START_PENDING ? 0 : SERVICE_ACCEPT_STOP | SERVICE_ACCEPT_SHUTDOWN;
  if (g_status_handle) SetServiceStatus(g_status_handle, &g_status);
}

void SetRuntimeState(std::string status, std::string code = {},
                     std::string message = {},
                     std::optional<DWORD> core_exit_code = std::nullopt) {
  std::lock_guard<std::mutex> lock(g_state_mutex);
  g_runtime_status = std::move(status);
  g_error_code = std::move(code);
  g_error_message = std::move(message);
  g_core_exit_code = core_exit_code;
}

std::filesystem::path RuntimeDirectory() {
  PWSTR program_data = nullptr;
  if (FAILED(SHGetKnownFolderPath(FOLDERID_ProgramData, 0, nullptr,
                                  &program_data))) {
    return L"C:\\ProgramData\\ElephantNetwork\\runtime";
  }
  std::filesystem::path result(program_data);
  CoTaskMemFree(program_data);
  return result / L"ElephantNetwork" / L"runtime";
}

std::optional<DWORD> ListeningProcessId(USHORT host_port) {
  DWORD size = 0;
  const auto initial = GetExtendedTcpTable(
      nullptr, &size, FALSE, AF_INET, TCP_TABLE_OWNER_PID_LISTENER, 0);
  if (initial != ERROR_INSUFFICIENT_BUFFER || size == 0) {
    return std::nullopt;
  }
  std::vector<BYTE> buffer(size);
  auto* table = reinterpret_cast<PMIB_TCPTABLE_OWNER_PID>(buffer.data());
  if (GetExtendedTcpTable(table, &size, FALSE, AF_INET,
                          TCP_TABLE_OWNER_PID_LISTENER, 0) != NO_ERROR) {
    return std::nullopt;
  }
  for (DWORD index = 0; index < table->dwNumEntries; ++index) {
    const auto& row = table->table[index];
    if (ntohs(static_cast<u_short>(row.dwLocalPort)) == host_port) {
      return row.dwOwningPid;
    }
  }
  return std::nullopt;
}

bool RouteOverlapsCandidate(const MIB_IPFORWARD_ROW2& route,
                            ULONG candidate_network) {
  if (route.DestinationPrefix.Prefix.si_family != AF_INET ||
      route.DestinationPrefix.PrefixLength == 0) {
    return false;
  }
  const auto common_prefix =
      std::min<UINT8>(route.DestinationPrefix.PrefixLength, 30);
  const ULONG mask = common_prefix == 0
                         ? 0
                         : 0xffffffffUL << (32 - common_prefix);
  const ULONG route_network =
      ntohl(route.DestinationPrefix.Prefix.Ipv4.sin_addr.S_un.S_addr);
  return (route_network & mask) == (candidate_network & mask);
}

std::optional<NetworkProfile> DetectNetworkProfile() {
  PMIB_IPFORWARD_TABLE2 table = nullptr;
  if (GetIpForwardTable2(AF_INET, &table) != NO_ERROR || !table) {
    return std::nullopt;
  }

  const MIB_IF_ROW2* selected = nullptr;
  MIB_IF_ROW2 selected_storage{};
  ULONG best_metric = std::numeric_limits<ULONG>::max();
  bool selected_hardware = false;
  for (ULONG index = 0; index < table->NumEntries; ++index) {
    const auto& route = table->Table[index];
    if (route.DestinationPrefix.PrefixLength != 0) continue;
    MIB_IF_ROW2 interface_row{};
    interface_row.InterfaceLuid = route.InterfaceLuid;
    if (GetIfEntry2(&interface_row) != NO_ERROR ||
        interface_row.OperStatus != IfOperStatusUp ||
        interface_row.Type == IF_TYPE_SOFTWARE_LOOPBACK ||
        interface_row.Type == IF_TYPE_TUNNEL ||
        _wcsicmp(interface_row.Alias, L"ElephantNetwork") == 0) {
      continue;
    }
    MIB_IPINTERFACE_ROW ip_interface{};
    InitializeIpInterfaceEntry(&ip_interface);
    ip_interface.Family = AF_INET;
    ip_interface.InterfaceLuid = route.InterfaceLuid;
    const ULONG interface_metric =
        GetIpInterfaceEntry(&ip_interface) == NO_ERROR ? ip_interface.Metric : 0;
    const bool hardware =
        interface_row.InterfaceAndOperStatusFlags.HardwareInterface != FALSE;
    const ULONG metric = route.Metric + interface_metric;
    if (!selected || (hardware && !selected_hardware) ||
        (hardware == selected_hardware && metric < best_metric)) {
      selected_storage = interface_row;
      selected = &selected_storage;
      selected_hardware = hardware;
      best_metric = metric;
    }
  }

  if (!selected) {
    FreeMibTable(table);
    return std::nullopt;
  }

  struct Candidate {
    ULONG network;
    const char* address;
  };
  constexpr Candidate candidates[] = {
      {0xac1fff00UL, "172.31.255.1/30"},
      {0xac1eff00UL, "172.30.255.1/30"},
      {0xc6120000UL, "198.18.0.1/30"},
      {0x0affff00UL, "10.255.255.1/30"},
  };
  const char* tun_address = nullptr;
  for (const auto& candidate : candidates) {
    bool overlaps = false;
    for (ULONG index = 0; index < table->NumEntries; ++index) {
      if (RouteOverlapsCandidate(table->Table[index], candidate.network)) {
        overlaps = true;
        break;
      }
    }
    if (!overlaps) {
      tun_address = candidate.address;
      break;
    }
  }

  NetworkProfile result;
  result.default_interface = elephant::WideToUtf8(selected->Alias);
  if (tun_address) result.tun_ipv4_address = tun_address;
  result.strict_route = IsWindows11OrGreater();
  FreeMibTable(table);
  if (result.default_interface.empty() || result.tun_ipv4_address.empty()) {
    return std::nullopt;
  }
  return result;
}

std::string NetworkProfileJson() {
  const auto profile = DetectNetworkProfile();
  if (!profile) {
    return elephant::BuildError(
        "default_interface_missing",
        "No usable physical IPv4 default interface or TUN subnet was found");
  }
  return "{\"status\":\"ready\",\"default_interface\":\"" +
         elephant::JsonEscape(profile->default_interface) +
         "\",\"tun_ipv4_address\":\"" +
         elephant::JsonEscape(profile->tun_ipv4_address) +
         "\",\"strict_route\":" +
         (profile->strict_route ? "true" : "false") + "}";
}

std::string CurrentStateJson() {
  std::lock_guard<std::mutex> lock(g_state_mutex);
  std::string result = "{\"status\":\"" + elephant::JsonEscape(g_runtime_status) +
                       "\",\"mode\":\"tun\",\"up_speed\":0,"
                       "\"down_speed\":0,\"total_up\":0,\"total_down\":0,"
                       "\"core_version\":\"" + elephant::JsonEscape(CoreVersion()) +
                       "\",\"core_pid\":" + std::to_string(g_core_pid);
  if (!g_error_code.empty()) {
    result += ",\"error_code\":\"" + elephant::JsonEscape(g_error_code) + "\"";
  }
  if (!g_error_message.empty()) {
    result += ",\"error_message\":\"" + elephant::JsonEscape(g_error_message) + "\"";
  }
  if (g_core_exit_code) {
    result += ",\"core_exit_code\":" + std::to_string(*g_core_exit_code);
  }
  result += "}";
  return result;
}

void StopCore() {
  HANDLE process = nullptr;
  HANDLE job = nullptr;
  {
    std::lock_guard<std::mutex> lock(g_state_mutex);
    process = g_core_process;
    job = g_core_job;
    g_core_process = nullptr;
    g_core_job = nullptr;
    g_core_pid = 0;
    g_runtime_status = "disconnecting";
  }

  if (job) TerminateJobObject(job, 0);
  if (process) {
    WaitForSingleObject(process, 5000);
    CloseHandle(process);
  }
  if (job) CloseHandle(job);
  g_speed_test = false;
  SetRuntimeState("disconnected");
}

bool CopyRuntimeAssets(const std::filesystem::path& runtime_dir) {
  const auto assets = std::filesystem::path(elephant::ExecutableDirectory()) /
                      L"data" / L"flutter_assets" / L"assets" / L"srs";
  std::error_code error;
  for (const auto* name : {L"geoip-cn.srs", L"geosite-cn.srs"}) {
    const auto source = assets / name;
    const auto destination = runtime_dir / name;
    if (!std::filesystem::exists(source)) return false;
    std::filesystem::copy_file(source, destination,
                               std::filesystem::copy_options::overwrite_existing,
                               error);
    if (error) return false;
  }
  return true;
}

bool WriteConfig(const std::filesystem::path& path, const std::string& config) {
  std::ofstream output(path, std::ios::binary | std::ios::trunc);
  output.write(config.data(), static_cast<std::streamsize>(config.size()));
  output.flush();
  return output.good();
}

void WatchCore(HANDLE process, DWORD process_id) {
  const DWORD exit_code = WaitForSingleObject(process, INFINITE);
  if (exit_code == WAIT_OBJECT_0) {
    HANDLE owned_process = nullptr;
    HANDLE owned_job = nullptr;
    {
      std::lock_guard<std::mutex> lock(g_state_mutex);
      if (g_core_pid == process_id) {
        owned_process = g_core_process;
        owned_job = g_core_job;
        g_core_process = nullptr;
        g_core_job = nullptr;
        g_core_pid = 0;
        g_runtime_status = "error";
        g_error_code = "core_start_failed";
        g_error_message = "sing-box exited unexpectedly";
        DWORD core_exit_code = 0;
        if (GetExitCodeProcess(process, &core_exit_code)) {
          g_core_exit_code = core_exit_code;
        }
      }
    }
    if (owned_process) CloseHandle(owned_process);
    if (owned_job) CloseHandle(owned_job);
  }
  CloseHandle(process);
}

std::string ClashRequest(const std::wstring& verb, const std::wstring& path,
                         const std::string& body = {});

std::string ReadLogSince(const std::filesystem::path& path,
                         std::uintmax_t offset) {
  constexpr std::uintmax_t kMaxDiagnosticLogBytes = 64 * 1024;
  std::ifstream input(path, std::ios::binary);
  if (!input) return {};
  input.seekg(0, std::ios::end);
  const auto end = input.tellg();
  if (end <= 0) return {};
  const auto size = static_cast<std::uintmax_t>(end);
  const auto start = std::max(offset, size > kMaxDiagnosticLogBytes
                                         ? size - kMaxDiagnosticLogBytes
                                         : std::uintmax_t{0});
  if (start >= size) return {};
  input.seekg(static_cast<std::streamoff>(start));
  return std::string((std::istreambuf_iterator<char>(input)),
                     std::istreambuf_iterator<char>());
}

enum class CoreReadyState {
  ready,
  process_exited,
  default_interface_missing,
  timeout,
};

struct CoreReadyResult {
  CoreReadyState state = CoreReadyState::timeout;
  DWORD exit_code = 0;
};

CoreReadyResult WaitForCoreReady(HANDLE process,
                                 const std::filesystem::path& log_path,
                                 std::uintmax_t log_offset) {
  const auto deadline = std::chrono::steady_clock::now() +
                        std::chrono::seconds(8);
  while (std::chrono::steady_clock::now() < deadline) {
    if (WaitForSingleObject(process, 0) == WAIT_OBJECT_0) {
      DWORD exit_code = 0;
      GetExitCodeProcess(process, &exit_code);
      return {CoreReadyState::process_exited, exit_code};
    }
    if (ReadLogSince(log_path, log_offset).find(
            "network: missing default interface") != std::string::npos) {
      return {CoreReadyState::default_interface_missing, 0};
    }
    if (!ClashRequest(L"GET", L"/version").empty()) {
      return {CoreReadyState::ready, 0};
    }
    std::this_thread::sleep_for(std::chrono::milliseconds(200));
  }
  return {CoreReadyState::timeout, 0};
}

std::string StartCore(const std::string& config, bool speed_test) {
  if (config.empty() || config.size() > elephant::kMaxConfigBytes ||
      config.front() != '{' || config.find("\"type\":\"tun\"") == std::string::npos) {
    return elephant::BuildError("config_invalid", "TUN configuration is invalid");
  }

  if (!DetectNetworkProfile()) {
    SetRuntimeState("error", "default_interface_missing",
                    "No usable physical IPv4 default interface was found");
    return CurrentStateJson();
  }

  StopCore();
  if (const auto owner = ListeningProcessId(9090)) {
    SetRuntimeState(
        "error", "control_port_in_use",
        "本机 9090 控制端口已被进程 " + std::to_string(*owner) +
            " 占用，请退出其他代理或 VPN 后重试。");
    return CurrentStateJson();
  }
  SetRuntimeState("core_starting");
  const auto runtime_dir = RuntimeDirectory();
  std::error_code filesystem_error;
  std::filesystem::create_directories(runtime_dir, filesystem_error);
  if (filesystem_error || !CopyRuntimeAssets(runtime_dir)) {
    SetRuntimeState("error", "config_invalid", "Cannot prepare runtime assets");
    return CurrentStateJson();
  }

  const auto config_path = runtime_dir / L"config.json";
  if (!WriteConfig(config_path, config)) {
    SetRuntimeState("error", "config_invalid", "Cannot write service configuration");
    return CurrentStateJson();
  }

  const auto binary_path = std::filesystem::path(elephant::ExecutableDirectory()) /
                           L"sing-box-windows-amd64.exe";
  if (!std::filesystem::exists(binary_path)) {
    SetRuntimeState("error", "binary_missing", "Bundled sing-box executable is missing");
    return CurrentStateJson();
  }

  const auto log_path = runtime_dir / L"sing-box.log";
  std::error_code log_size_error;
  const auto log_offset = std::filesystem::exists(log_path)
                              ? std::filesystem::file_size(log_path,
                                                           log_size_error)
                              : 0;
  SECURITY_ATTRIBUTES log_security{};
  log_security.nLength = sizeof(log_security);
  log_security.bInheritHandle = TRUE;
  HANDLE log = CreateFileW(log_path.c_str(), FILE_APPEND_DATA,
                           FILE_SHARE_READ | FILE_SHARE_WRITE, &log_security,
                           OPEN_ALWAYS, FILE_ATTRIBUTE_NORMAL, nullptr);
  STARTUPINFOW startup{};
  startup.cb = sizeof(startup);
  if (log != INVALID_HANDLE_VALUE) {
    startup.dwFlags = STARTF_USESTDHANDLES;
    startup.hStdOutput = log;
    startup.hStdError = log;
    startup.hStdInput = nullptr;
  }
  PROCESS_INFORMATION process{};
  std::wstring command = L"\"" + binary_path.wstring() + L"\" run -c \"" +
                         config_path.wstring() + L"\"";
  ApplySingBoxCompatibilityEnvironment();
  const BOOL created = CreateProcessW(
      binary_path.c_str(), command.data(), nullptr, nullptr, TRUE,
      CREATE_NO_WINDOW | CREATE_SUSPENDED, nullptr, runtime_dir.c_str(),
      &startup, &process);
  if (log != INVALID_HANDLE_VALUE) CloseHandle(log);
  if (!created) {
    SetRuntimeState("error", "core_start_failed", "Cannot start bundled sing-box");
    return CurrentStateJson();
  }

  HANDLE job = CreateJobObjectW(nullptr, nullptr);
  JOBOBJECT_EXTENDED_LIMIT_INFORMATION limits{};
  limits.BasicLimitInformation.LimitFlags = JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE;
  if (!job || !SetInformationJobObject(job, JobObjectExtendedLimitInformation,
                                        &limits, sizeof(limits)) ||
      !AssignProcessToJobObject(job, process.hProcess)) {
    TerminateProcess(process.hProcess, 1);
    CloseHandle(process.hThread);
    CloseHandle(process.hProcess);
    if (job) CloseHandle(job);
    SetRuntimeState("error", "core_start_failed", "Cannot supervise sing-box process");
    return CurrentStateJson();
  }

  {
    std::lock_guard<std::mutex> lock(g_state_mutex);
    g_core_process = process.hProcess;
    g_core_job = job;
    g_core_pid = process.dwProcessId;
  }
  ResumeThread(process.hThread);
  CloseHandle(process.hThread);
  const auto ready = WaitForCoreReady(
      process.hProcess, log_path, log_size_error ? 0 : log_offset);
  if (ready.state != CoreReadyState::ready) {
    const auto log_tail = ReadLogSince(
        log_path, log_size_error ? 0 : log_offset);
    StopCore();
    const auto failure = elephant::ClassifyCoreStartFailure(
        log_tail, ready.state == CoreReadyState::process_exited,
        ready.exit_code);
    SetRuntimeState("error", failure.code, failure.message,
                    failure.exit_code
                        ? std::optional<DWORD>(*failure.exit_code)
                        : std::nullopt);
    return CurrentStateJson();
  }

  g_speed_test = speed_test;
  g_last_client_tick = GetTickCount64();
  SetRuntimeState("connected");
  HANDLE watcher_process = nullptr;
  if (!DuplicateHandle(GetCurrentProcess(), process.hProcess,
                       GetCurrentProcess(), &watcher_process, SYNCHRONIZE,
                       FALSE, 0)) {
    StopCore();
    SetRuntimeState("error", "core_start_failed", "Cannot monitor sing-box process");
    return CurrentStateJson();
  }
  std::thread(WatchCore, watcher_process, process.dwProcessId).detach();
  return CurrentStateJson();
}

std::wstring PercentEncode(const std::string& value) {
  constexpr wchar_t kHex[] = L"0123456789ABCDEF";
  std::wstring result;
  for (const unsigned char character : value) {
    if ((character >= 'a' && character <= 'z') ||
        (character >= 'A' && character <= 'Z') ||
        (character >= '0' && character <= '9') || character == '-' ||
        character == '_' || character == '.' || character == '~') {
      result.push_back(static_cast<wchar_t>(character));
    } else {
      result.push_back(L'%');
      result.push_back(kHex[(character >> 4) & 0xf]);
      result.push_back(kHex[character & 0xf]);
    }
  }
  return result;
}

std::string ClashRequest(const std::wstring& verb, const std::wstring& path,
                         const std::string& body) {
  HINTERNET session = WinHttpOpen(L"ElephantNetworkService/1.0",
                                  WINHTTP_ACCESS_TYPE_NO_PROXY,
                                  WINHTTP_NO_PROXY_NAME,
                                  WINHTTP_NO_PROXY_BYPASS, 0);
  if (!session) return {};
  WinHttpSetTimeouts(session, 2000, 2000, 3000, 3000);
  HINTERNET connection = WinHttpConnect(session, L"127.0.0.1", 9090, 0);
  HINTERNET request = connection
      ? WinHttpOpenRequest(connection, verb.c_str(), path.c_str(), nullptr,
                           WINHTTP_NO_REFERER, WINHTTP_DEFAULT_ACCEPT_TYPES, 0)
      : nullptr;
  BOOL sent = FALSE;
  if (request) {
    sent = WinHttpSendRequest(
        request, body.empty() ? WINHTTP_NO_ADDITIONAL_HEADERS
                              : L"Content-Type: application/json\r\n",
        body.empty() ? 0 : static_cast<DWORD>(-1),
        body.empty() ? WINHTTP_NO_REQUEST_DATA
                     : const_cast<char*>(body.data()),
        static_cast<DWORD>(body.size()), static_cast<DWORD>(body.size()), 0);
  }
  BOOL received = sent && WinHttpReceiveResponse(request, nullptr);
  std::string response;
  while (received) {
    DWORD available = 0;
    if (!WinHttpQueryDataAvailable(request, &available) || available == 0) break;
    const auto offset = response.size();
    response.resize(offset + available);
    DWORD read = 0;
    if (!WinHttpReadData(request, response.data() + offset, available, &read)) break;
    response.resize(offset + read);
  }
  if (request) WinHttpCloseHandle(request);
  if (connection) WinHttpCloseHandle(connection);
  WinHttpCloseHandle(session);
  return response;
}

std::string HandleRequest(const std::string& request) {
  const auto version = elephant::JsonInteger(request, "version");
  const auto method = elephant::JsonString(request, "method");
  if (!version || *version != elephant::kProtocolVersion || !method) {
    return elephant::BuildError("protocol_error", "Unsupported IPC request");
  }
  g_last_client_tick = GetTickCount64();

  if (*method == "getStatus") return CurrentStateJson();
  if (*method == "getNetworkProfile") return NetworkProfileJson();
  if (*method == "stop") {
    StopCore();
    return CurrentStateJson();
  }
  if (*method == "start" || *method == "prepareSpeedTest") {
    const auto config = elephant::JsonString(request, "config");
    if (!config) return elephant::BuildError("config_invalid", "Missing configuration");
    return StartCore(*config, *method == "prepareSpeedTest");
  }
  if (*method == "stopSpeedTest") {
    if (g_speed_test) StopCore();
    return CurrentStateJson();
  }
  if (*method == "urlTest") {
    const auto group = elephant::JsonString(request, "group_tag");
    if (!group) return elephant::BuildError("config_invalid", "Missing group tag");
    const auto response = ClashRequest(
        L"GET", L"/proxies/" + PercentEncode(*group) +
                    L"/delay?url=https%3A%2F%2Fwww.gstatic.com%2Fgenerate_204&timeout=3000");
    const auto delay = elephant::JsonInteger(response, "delay").value_or(-1);
    return "{\"status\":\"connected\",\"delay\":" + std::to_string(delay) + "}";
  }
  if (*method == "selectOutbound") {
    const auto group = elephant::JsonString(request, "group_tag");
    const auto outbound = elephant::JsonString(request, "outbound_tag");
    if (!group || !outbound) {
      return elephant::BuildError("config_invalid", "Missing outbound selection");
    }
    const auto response = ClashRequest(
        L"PUT", L"/proxies/" + PercentEncode(*group),
        "{\"name\":\"" + elephant::JsonEscape(*outbound) + "\"}");
    if (!response.empty() && response.find("error") != std::string::npos) {
      return elephant::BuildError("core_start_failed", "sing-box rejected outbound selection");
    }
    return CurrentStateJson();
  }
  return elephant::BuildError("protocol_error", "Method is not allowed");
}

SECURITY_ATTRIBUTES PipeSecurity(PSECURITY_DESCRIPTOR* descriptor) {
  *descriptor = nullptr;
  ConvertStringSecurityDescriptorToSecurityDescriptorW(
      L"D:P(A;;GA;;;SY)(A;;GA;;;BA)(A;;GRGW;;;IU)", SDDL_REVISION_1,
      descriptor, nullptr);
  SECURITY_ATTRIBUTES attributes{};
  attributes.nLength = sizeof(attributes);
  attributes.lpSecurityDescriptor = *descriptor;
  return attributes;
}

void PipeLoop() {
  while (WaitForSingleObject(g_stop_event, 0) != WAIT_OBJECT_0) {
    PSECURITY_DESCRIPTOR descriptor = nullptr;
    auto security = PipeSecurity(&descriptor);
    HANDLE pipe = CreateNamedPipeW(
        elephant::kPipeName, PIPE_ACCESS_DUPLEX,
        PIPE_TYPE_BYTE | PIPE_READMODE_BYTE | PIPE_WAIT | PIPE_REJECT_REMOTE_CLIENTS,
        PIPE_UNLIMITED_INSTANCES, 64 * 1024, 64 * 1024, 1000, &security);
    if (descriptor) LocalFree(descriptor);
    if (pipe == INVALID_HANDLE_VALUE) {
      Sleep(500);
      continue;
    }
    const BOOL connected = ConnectNamedPipe(pipe, nullptr)
                               ? TRUE
                               : GetLastError() == ERROR_PIPE_CONNECTED;
    if (connected) {
      std::string request;
      if (elephant::ReadFramedMessage(pipe, &request)) {
        elephant::WriteFramedMessage(pipe, HandleRequest(request));
      }
      FlushFileBuffers(pipe);
      DisconnectNamedPipe(pipe);
    }
    CloseHandle(pipe);
  }
}

void WatchdogLoop() {
  while (WaitForSingleObject(g_stop_event, 2000) == WAIT_TIMEOUT) {
    bool connected = false;
    {
      std::lock_guard<std::mutex> lock(g_state_mutex);
      connected = g_core_process != nullptr;
    }
    const ULONGLONG last_tick = g_last_client_tick.load();
    if (connected && last_tick > 0 && GetTickCount64() - last_tick > 15000) {
      SetRuntimeState("disconnecting", "client_gone", "App heartbeat expired");
      StopCore();
    }
  }
}

DWORD WINAPI ServiceControlHandler(DWORD control, DWORD, void*, void*) {
  if (control == SERVICE_CONTROL_STOP || control == SERVICE_CONTROL_SHUTDOWN) {
    SetServiceState(SERVICE_STOP_PENDING);
    SetEvent(g_stop_event);
  }
  return NO_ERROR;
}

void WINAPI ServiceMain(DWORD, wchar_t**) {
  g_status_handle = RegisterServiceCtrlHandlerExW(
      elephant::kServiceName, ServiceControlHandler, nullptr);
  if (!g_status_handle) return;
  SetServiceState(SERVICE_START_PENDING);
  g_stop_event = CreateEventW(nullptr, TRUE, FALSE, nullptr);
  if (!g_stop_event) {
    SetServiceState(SERVICE_STOPPED, GetLastError());
    return;
  }

  SetServiceState(SERVICE_RUNNING);
  std::thread pipe_thread(PipeLoop);
  std::thread watchdog_thread(WatchdogLoop);
  WaitForSingleObject(g_stop_event, INFINITE);
  StopCore();

  // Wake a blocking ConnectNamedPipe so the server thread can observe stop.
  HANDLE wake = CreateFileW(elephant::kPipeName, GENERIC_READ | GENERIC_WRITE,
                            0, nullptr, OPEN_EXISTING, 0, nullptr);
  if (wake != INVALID_HANDLE_VALUE) CloseHandle(wake);
  pipe_thread.join();
  watchdog_thread.join();
  CloseHandle(g_stop_event);
  g_stop_event = nullptr;
  SetServiceState(SERVICE_STOPPED);
}

}  // namespace

int wmain(int argc, wchar_t** argv) {
  if (argc > 1 && std::wstring(argv[1]) == L"--console") {
    g_stop_event = CreateEventW(nullptr, TRUE, FALSE, nullptr);
    PipeLoop();
    return 0;
  }
  SERVICE_TABLE_ENTRYW table[] = {
      {const_cast<LPWSTR>(elephant::kServiceName), ServiceMain},
      {nullptr, nullptr},
  };
  return StartServiceCtrlDispatcherW(table) ? 0 : static_cast<int>(GetLastError());
}
