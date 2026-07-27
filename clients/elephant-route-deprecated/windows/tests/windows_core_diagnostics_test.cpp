#include <cassert>
#include <iostream>
#include <string>

#include "windows_core_diagnostics.h"

int main() {
  const auto port_conflict = elephant::ClassifyCoreStartFailure(
      "listen tcp 127.0.0.1:9090: bind: Only one usage of each socket address",
      true, 1);
  assert(port_conflict.code == "control_port_in_use");

  const auto invalid_config = elephant::ClassifyCoreStartFailure(
      "FATAL decode config at config.json: unknown field foo", true, 1);
  assert(invalid_config.code == "core_config_invalid");

  constexpr auto deprecation_warnings =
      "WARN legacy DNS servers is deprecated in sing-box 1.12.0\n"
      "WARN legacy special outbounds is deprecated in sing-box 1.11.0\n"
      "WARN missing route.default_domain_resolver is deprecated";

  const auto warning_timeout = elephant::ClassifyCoreStartFailure(
      deprecation_warnings, false, 0);
  assert(warning_timeout.code == "control_api_timeout");

  const auto warning_exit = elephant::ClassifyCoreStartFailure(
      deprecation_warnings, true, 9);
  assert(warning_exit.code == "core_exited");
  assert(warning_exit.exit_code.has_value());
  assert(*warning_exit.exit_code == 9);

  const auto warning_then_tun_failure = elephant::ClassifyCoreStartFailure(
      std::string(deprecation_warnings) +
          "\nFATAL create TUN interface: device is already in use",
      true, 1);
  assert(warning_then_tun_failure.code == "tun_start_failed");

  const auto tun_failure = elephant::ClassifyCoreStartFailure(
      "FATAL create TUN interface: device is already in use", true, 1);
  assert(tun_failure.code == "tun_start_failed");

  const auto blocked =
      elephant::ClassifyCoreStartFailure("\r\n", true, 5);
  assert(blocked.code == "core_blocked_or_crashed");
  assert(blocked.exit_code.has_value());
  assert(*blocked.exit_code == 5);

  const auto timeout =
      elephant::ClassifyCoreStartFailure("", false, 0);
  assert(timeout.code == "control_api_timeout");
  assert(!timeout.exit_code.has_value());

  const auto exited = elephant::ClassifyCoreStartFailure(
      "FATAL unexpected internal error", true, 7);
  assert(exited.code == "core_exited");
  assert(exited.exit_code.has_value());
  assert(*exited.exit_code == 7);

  std::cout << "windows_core_diagnostics_test passed\n";
  return 0;
}
