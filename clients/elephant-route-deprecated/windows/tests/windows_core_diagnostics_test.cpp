#include <cassert>
#include <iostream>

#include "windows_core_diagnostics.h"

int main() {
  const auto port_conflict = elephant::ClassifyCoreStartFailure(
      "listen tcp 127.0.0.1:9090: bind: Only one usage of each socket address",
      true, 1);
  assert(port_conflict.code == "control_port_in_use");

  const auto invalid_config = elephant::ClassifyCoreStartFailure(
      "FATAL decode config at config.json: unknown field foo", true, 1);
  assert(invalid_config.code == "core_config_invalid");

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
