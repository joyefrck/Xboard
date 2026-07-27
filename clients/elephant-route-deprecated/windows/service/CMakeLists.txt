find_program(GO_EXECUTABLE go REQUIRED)

set(ELEPHANT_SERVICE_GO_DIR
  "${CMAKE_CURRENT_SOURCE_DIR}/../service_go")
set(ELEPHANT_SERVICE_EXE
  "${CMAKE_CURRENT_BINARY_DIR}/ElephantNetworkService.exe")
file(GLOB_RECURSE ELEPHANT_SERVICE_GO_SOURCES CONFIGURE_DEPENDS
  "${ELEPHANT_SERVICE_GO_DIR}/*.go"
  "${ELEPHANT_SERVICE_GO_DIR}/go.mod"
  "${ELEPHANT_SERVICE_GO_DIR}/go.sum")

add_custom_command(
  OUTPUT "${ELEPHANT_SERVICE_EXE}"
  COMMAND "${CMAKE_COMMAND}" -E env
    "CGO_ENABLED=0"
    "GOOS=windows"
    "GOARCH=amd64"
    "${GO_EXECUTABLE}" build
    -trimpath
    -tags
    "with_gvisor,with_quic,with_dhcp,with_wireguard,with_utls,with_acme,with_clash_api,with_tailscale"
    -ldflags
    "-s -w -X github.com/sagernet/sing-box/constant.Version=1.12.25"
    -o "${ELEPHANT_SERVICE_EXE}"
    .
  WORKING_DIRECTORY "${ELEPHANT_SERVICE_GO_DIR}"
  DEPENDS ${ELEPHANT_SERVICE_GO_SOURCES}
  COMMENT "Building in-process ElephantNetworkService"
  VERBATIM)

add_custom_target(ElephantNetworkService ALL
  DEPENDS "${ELEPHANT_SERVICE_EXE}")
set(ELEPHANT_SERVICE_EXE "${ELEPHANT_SERVICE_EXE}" PARENT_SCOPE)
