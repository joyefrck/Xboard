#include <cassert>
#include <iostream>
#include <string>

#include "windows_protocol.h"

int main() {
  const std::string nested = R"({"inbounds":[{"type":"tun"}],"tag":"东京"})";
  const auto request = elephant::BuildRequest(
      "start", "{\"config\":\"" + elephant::JsonEscape(nested) + "\"}");
  assert(elephant::JsonInteger(request, "version") == 1);
  assert(elephant::JsonString(request, "method") == "start");
  assert(elephant::JsonString(request, "config") == nested);

  const auto error = elephant::BuildError("config_invalid", "bad \"value\"");
  assert(elephant::JsonString(error, "status") == "error");
  assert(elephant::JsonString(error, "error_code") == "config_invalid");
  assert(elephant::JsonString(error, "error_message") == "bad \"value\"");

  const std::string profile =
      R"({"default_interface":"Ethernet","strict_route":false})";
  assert(elephant::JsonBoolean(profile, "strict_route") == false);
  assert(!elephant::JsonBoolean(profile, "missing").has_value());

  const auto latency = elephant::BuildRequest(
      "startLatencyTest",
      R"({"node_tags_json":"[\"东京\",\"Osaka\"]","test_url":"https://www.gstatic.com/generate_204","timeout_ms":5000,"concurrency":4})");
  assert(elephant::JsonString(latency, "method") == "startLatencyTest");
  assert(elephant::JsonString(latency, "node_tags_json") ==
         R"(["东京","Osaka"])");
  assert(elephant::JsonInteger(latency, "timeout_ms") == 5000);
  assert(elephant::JsonInteger(latency, "concurrency") == 4);

  const std::string latency_snapshot =
      R"({"run_id":"12345678","latency_test_status":"completed","latency_completed":2,"latency_total":2,"latency_results_json":"{\"东京\":{\"latency_ms\":42}}"})";
  assert(elephant::JsonString(latency_snapshot, "run_id") == "12345678");
  assert(elephant::JsonString(latency_snapshot, "latency_test_status") ==
         "completed");
  assert(elephant::JsonInteger(latency_snapshot, "latency_completed") == 2);
  assert(elephant::JsonString(latency_snapshot, "latency_results_json") ==
         R"({"东京":{"latency_ms":42}})");

  const auto wide = elephant::Utf8ToWide("大象网络");
  assert(!wide.empty());
  assert(elephant::WideToUtf8(wide) == "大象网络");
  std::cout << "windows_protocol_test passed\n";
  return 0;
}
