add_executable(windows_protocol_test
  "windows_protocol_test.cpp"
  "../common/windows_protocol.cpp"
)
apply_standard_settings(windows_protocol_test)
target_compile_options(windows_protocol_test PRIVATE /utf-8)
target_include_directories(windows_protocol_test PRIVATE "../common")
target_compile_definitions(windows_protocol_test PRIVATE UNICODE _UNICODE NOMINMAX)
add_test(NAME windows_protocol_test COMMAND windows_protocol_test)

add_executable(windows_core_diagnostics_test
  "windows_core_diagnostics_test.cpp"
  "../common/windows_core_diagnostics.cpp"
)
apply_standard_settings(windows_core_diagnostics_test)
target_compile_options(windows_core_diagnostics_test PRIVATE /utf-8)
target_include_directories(windows_core_diagnostics_test PRIVATE "../common")
add_test(NAME windows_core_diagnostics_test COMMAND windows_core_diagnostics_test)
