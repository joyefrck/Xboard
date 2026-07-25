#include "windows_core_diagnostics.h"

#include <algorithm>
#include <cctype>
#include <initializer_list>
#include <utility>

namespace elephant {
namespace {

std::string Lowercase(std::string value) {
  std::transform(value.begin(), value.end(), value.begin(),
                 [](unsigned char character) {
                   return static_cast<char>(std::tolower(character));
                 });
  return value;
}

bool ContainsAny(const std::string& value,
                 std::initializer_list<const char*> needles) {
  for (const auto* needle : needles) {
    if (value.find(needle) != std::string::npos) return true;
  }
  return false;
}

CoreStartFailure Failure(std::string code, std::string message,
                         bool process_exited, std::uint32_t exit_code) {
  CoreStartFailure result{std::move(code), std::move(message), std::nullopt};
  if (process_exited) result.exit_code = exit_code;
  return result;
}

}  // namespace

CoreStartFailure ClassifyCoreStartFailure(const std::string& log_tail,
                                          bool process_exited,
                                          std::uint32_t exit_code) {
  const auto log = Lowercase(log_tail);
  if (ContainsAny(log, {"address already in use",
                        "only one usage of each socket address",
                        "bind 127.0.0.1:9090"})) {
    return Failure(
        "control_port_in_use",
        "本机 9090 控制端口已被占用，请退出其他代理或 VPN 后重试。",
        process_exited, exit_code);
  }
  if (log.find("network: missing default interface") != std::string::npos) {
    return Failure(
        "default_interface_missing",
        "sing-box 无法绑定当前物理网络接口，请检查网卡状态。",
        process_exited, exit_code);
  }
  if (ContainsAny(log, {"decode config", "parse config", "invalid config",
                        "unknown field", "deprecated"})) {
    return Failure(
        "core_config_invalid",
        "sing-box 拒绝了当前配置，请查看 sing-box.log 中的校验错误。",
        process_exited, exit_code);
  }
  if (ContainsAny(log, {"create tun", "configure tun", "setup tun",
                        "tun interface", "wintun"})) {
    return Failure(
        "tun_start_failed",
        "sing-box 无法创建 Windows TUN 网卡，请退出其他 VPN 后重试。",
        process_exited, exit_code);
  }
  const bool log_is_empty =
      log.find_first_not_of(" \t\r\n") == std::string::npos;
  if (process_exited && log_is_empty) {
    return Failure(
        "core_blocked_or_crashed",
        "sing-box 未产生日志便退出，请检查 Windows 安全中心或杀毒软件隔离记录。",
        true, exit_code);
  }
  if (process_exited) {
    return Failure(
        "core_exited",
        "sing-box 在控制接口就绪前退出，请查看 sing-box.log。",
        true, exit_code);
  }
  return Failure(
      "control_api_timeout",
      "sing-box 仍在运行，但本机控制接口在 8 秒内未就绪。",
      false, 0);
}

}  // namespace elephant
