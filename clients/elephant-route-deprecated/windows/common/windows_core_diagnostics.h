#ifndef ELEPHANT_NETWORK_WINDOWS_COMMON_WINDOWS_CORE_DIAGNOSTICS_H_
#define ELEPHANT_NETWORK_WINDOWS_COMMON_WINDOWS_CORE_DIAGNOSTICS_H_

#include <cstdint>
#include <optional>
#include <string>

namespace elephant {

struct CoreStartFailure {
  std::string code;
  std::string message;
  std::optional<std::uint32_t> exit_code;
};

CoreStartFailure ClassifyCoreStartFailure(const std::string& log_tail,
                                          bool process_exited,
                                          std::uint32_t exit_code);

}  // namespace elephant

#endif  // ELEPHANT_NETWORK_WINDOWS_COMMON_WINDOWS_CORE_DIAGNOSTICS_H_
