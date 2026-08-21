import Darwin
import Foundation

@objc(ElephantTunHelperProtocol)
protocol ElephantTunHelperProtocol {
  func getStatus(withReply reply: @escaping (NSDictionary) -> Void)
  func startTun(configPath: String, withReply reply: @escaping (NSDictionary) -> Void)
  func stopTun(withReply reply: @escaping (NSDictionary) -> Void)
  func exportDiagnostics(withReply reply: @escaping (NSString?) -> Void)
}

private let helperLabel = "com.elphantroute.elephantNetwork.tunhelper"
private let singBoxCompatibilityEnvironment = [
  "ENABLE_DEPRECATED_SPECIAL_OUTBOUNDS": "true",
  "ENABLE_DEPRECATED_LEGACY_DNS_SERVERS": "true",
  "ENABLE_DEPRECATED_TUN_ADDRESS_X": "true"
]

final class TunHelper: NSObject, ElephantTunHelperProtocol {
  private var coreProcess: Process?
  private var coreOutputPipe: Pipe?
  private let coreOutputPipeLock = NSLock()
  private let fileManager = FileManager.default
  private let clientUID: uid_t
  private let clientHomeDirectory: URL
  private let runtimeDirectory: URL
  private let logDirectory = URL(fileURLWithPath: "/Library/Logs/ElephantRoute", isDirectory: true)
  private lazy var logURL = logDirectory.appendingPathComponent("tun-helper.log")
  private let logLock = NSLock()
  private let maxLogFileSize: UInt64 = 10 * 1024 * 1024
  private let retainedLogArchiveCount = 2
  private let coreOutputLock = NSLock()
  private var latestCoreOutput = ""

  init(clientUID: uid_t, clientHomeDirectory: URL) {
    self.clientUID = clientUID
    self.clientHomeDirectory = clientHomeDirectory
    runtimeDirectory = clientHomeDirectory
      .appendingPathComponent("Library/Application Support", isDirectory: true)
      .appendingPathComponent("com.elphantroute.elephantNetwork", isDirectory: true)
      .appendingPathComponent("sing-box", isDirectory: true)
    super.init()
    ensureLogDirectory()
    log("Helper started for uid=\(clientUID)")
  }

  func getStatus(withReply reply: @escaping (NSDictionary) -> Void) {
    reply([
      "ok": true,
      "label": helperLabel,
      "clientUID": Int(clientUID),
      "coreRunning": isCoreRunning(),
      "logPath": logURL.path
    ])
  }

  func startTun(configPath: String, withReply reply: @escaping (NSDictionary) -> Void) {
    let result = startTunInternal(configPath: configPath)
    reply(result as NSDictionary)
  }

  func stopTun(withReply reply: @escaping (NSDictionary) -> Void) {
    let result = stopTunInternal()
    reply(result as NSDictionary)
  }

  func exportDiagnostics(withReply reply: @escaping (NSString?) -> Void) {
    let exportRoot = URL(fileURLWithPath: NSTemporaryDirectory(), isDirectory: true)
      .appendingPathComponent("elephant-tun-helper-\(Int(Date().timeIntervalSince1970))", isDirectory: true)
    do {
      try fileManager.createDirectory(at: exportRoot, withIntermediateDirectories: true)
      if fileManager.fileExists(atPath: logURL.path) {
        try? fileManager.copyItem(at: logURL, to: exportRoot.appendingPathComponent("tun-helper.log"))
      }
      writeJSON(getStatusDictionary(), to: exportRoot.appendingPathComponent("helper-status.json"))
      reply(exportRoot.path as NSString)
    } catch {
      log("Diagnostics export failed: \(error)")
      reply(nil)
    }
  }

  private func startTunInternal(configPath: String) -> [String: Any] {
    log("startTun requested config=\(configPath)")
    guard let paths = validatedRuntimePaths(configPath: configPath) else {
      return ["ok": false, "code": "INVALID_PATH", "error": "Invalid TUN runtime path"]
    }

    _ = stopTunInternal()

    if let conflict = activeTunnelConflictDescription() {
      log("TUN conflict: \(conflict)")
      return ["ok": false, "code": "TUN_CONFLICT", "error": conflict]
    }

    cleanupRoutes()
    replaceLatestCoreOutput(with: "")

    let process = Process()
    process.executableURL = URL(fileURLWithPath: paths.binaryPath)
    process.arguments = ["run", "-c", paths.configPath]
    process.currentDirectoryURL = URL(fileURLWithPath: paths.workDirectory)
    process.environment = ProcessInfo.processInfo.environment.merging(
      singBoxCompatibilityEnvironment
    ) { _, compatibilityValue in compatibilityValue }

    let output = Pipe()
    process.standardOutput = output
    process.standardError = output
    output.fileHandleForReading.readabilityHandler = { [weak self, weak output] handle in
      let data = handle.availableData
      guard !data.isEmpty else {
        handle.readabilityHandler = nil
        if let output {
          self?.cleanupCoreOutputPipe(output)
        }
        return
      }
      guard let text = String(data: data, encoding: .utf8) else { return }
      let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
      self?.appendLatestCoreOutput(trimmed)
      self?.log("[sing-box] \(trimmed)")
    }
    process.terminationHandler = { [weak self, weak output] _ in
      if let output {
        self?.cleanupCoreOutputPipe(output)
      }
    }
    storeCoreOutputPipe(output)

    do {
      try process.run()
      coreProcess = process
    } catch {
      cleanupCoreOutputPipe(output)
      log("Failed to launch sing-box: \(error)")
      return ["ok": false, "code": "CORE_START_FAILED", "error": "Failed to launch TUN core: \(error.localizedDescription)"]
    }

    guard waitForHealthCheck() else {
      let coreExited = coreProcess?.isRunning != true
      let failureMessage = recentTunFailureMessage()
      _ = stopTunInternal()
      let error = failureMessage ?? "Health check failed after TUN launch"
      let code = error.contains("其他 TUN/VPN 会话") || error.contains("冲突路由")
        ? "TUN_ROUTE_CONFLICT"
        : (coreExited ? "CORE_EXITED" : "HEALTH_CHECK_FAILED")
      return ["ok": false, "code": code, "error": error]
    }

    log("TUN core connected")
    return ["ok": true, "mode": "tun", "coreRunning": true, "logPath": logURL.path]
  }

  private func stopTunInternal() -> [String: Any] {
    log("stopTun requested")
    if let process = coreProcess, process.isRunning {
      process.terminate()
      waitForCoreExit(timeout: 1.5)
      if process.isRunning {
        log("TUN core ignored SIGTERM; sending SIGKILL pid=\(process.processIdentifier)")
        _ = Darwin.kill(process.processIdentifier, SIGKILL)
        waitForCoreExit(timeout: 1.0)
      }
    }
    coreProcess = nil

    if let pattern = currentCoreProcessPattern() {
      _ = runCommand("/usr/bin/pkill", args: ["-f", pattern])
    }
    waitForCoreExit(timeout: 2.0)

    if isCoreRunning(), let pattern = currentCoreProcessPattern() {
      log("TUN core still present after graceful cleanup; forcing pattern=\(pattern)")
      _ = runCommand("/usr/bin/pkill", args: ["-KILL", "-f", pattern])
      waitForCoreExit(timeout: 1.0)
    }

    let coreStillRunning = isCoreRunning()
    cleanupCoreOutputPipe()

    var result: [String: Any] = [
      "ok": !coreStillRunning,
      "stopped": !coreStillRunning,
      "coreRunning": coreStillRunning,
      "code": coreStillRunning ? "CORE_STOP_FAILED" : "OK"
    ]
    if coreStillRunning {
      result["error"] = "后台网络核心未能停止"
      log("TUN core stop failed after SIGKILL fallback")
    }
    return result
  }

  private func storeCoreOutputPipe(_ pipe: Pipe) {
    coreOutputPipeLock.lock()
    let previousPipe = coreOutputPipe
    coreOutputPipe = pipe
    coreOutputPipeLock.unlock()

    if let previousPipe, previousPipe !== pipe {
      closeCoreOutputPipe(previousPipe)
    }
  }

  private func cleanupCoreOutputPipe(_ expectedPipe: Pipe? = nil) {
    coreOutputPipeLock.lock()
    let pipeToClose: Pipe?
    if let expectedPipe {
      if coreOutputPipe === expectedPipe {
        coreOutputPipe = nil
        pipeToClose = expectedPipe
      } else {
        pipeToClose = nil
      }
    } else {
      pipeToClose = coreOutputPipe
      coreOutputPipe = nil
    }
    coreOutputPipeLock.unlock()

    if let pipeToClose {
      closeCoreOutputPipe(pipeToClose)
    }
  }

  private func closeCoreOutputPipe(_ pipe: Pipe) {
    pipe.fileHandleForReading.readabilityHandler = nil
    try? pipe.fileHandleForReading.close()
    try? pipe.fileHandleForWriting.close()
  }

  private func validatedRuntimePaths(configPath: String) -> (configPath: String, binaryPath: String, workDirectory: String)? {
    guard !configPath.contains("..") else { return nil }
    let expectedDirectory = runtimeDirectory.resolvingSymlinksInPath()
    let expectedConfigURL = expectedDirectory.appendingPathComponent("config.json")
    let configURL = URL(fileURLWithPath: configPath).resolvingSymlinksInPath()
    guard configURL.path == expectedConfigURL.path else { return nil }
    guard fileManager.fileExists(atPath: configURL.path) else { return nil }

    let workDirectory = expectedDirectory.path
    let binaryURL = expectedDirectory
      .appendingPathComponent("sing-box-darwin-arm64")
      .resolvingSymlinksInPath()
    guard binaryURL.path == expectedDirectory.appendingPathComponent("sing-box-darwin-arm64").path else { return nil }
    guard fileManager.fileExists(atPath: binaryURL.path) else { return nil }

    return (configURL.path, binaryURL.path, workDirectory)
  }

  private func currentCoreProcessPattern() -> String? {
    let output = runCommand("/bin/ps", args: ["-ax", "-o", "command="])
    let runtimePath = runtimeDirectory.resolvingSymlinksInPath().path
    return output
      .split(separator: "\n")
      .map(String.init)
      .first { $0.contains(runtimePath) && $0.contains("sing-box-darwin-arm64") }
      .flatMap { line in
        line.split(separator: " ").map(String.init).first { $0.contains(runtimePath) }
      }
      .flatMap { URL(fileURLWithPath: $0).deletingLastPathComponent().path }
  }

  private func isCoreRunning() -> Bool {
    guard let pattern = currentCoreProcessPattern() else { return false }
    let output = runCommand("/usr/bin/pgrep", args: ["-f", pattern])
    return !output.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
  }

  private func waitForCoreExit(timeout: TimeInterval) {
    let deadline = Date().addingTimeInterval(timeout)
    while Date() < deadline {
      if !isCoreRunning() {
        return
      }
      Thread.sleep(forTimeInterval: 0.2)
    }
  }

  private func waitForHealthCheck() -> Bool {
    guard let url = URL(string: "http://127.0.0.1:9090/proxies") else { return false }
    for _ in 0..<12 {
      if let process = coreProcess, !process.isRunning {
        return false
      }
      if let data = try? Data(contentsOf: url), !data.isEmpty {
        return true
      }
      Thread.sleep(forTimeInterval: 0.5)
    }
    return false
  }

  private func activeTunnelConflictDescription() -> String? {
    let output = runCommand("/usr/sbin/netstat", args: ["-rn", "-f", "inet"])
    let routeLines = output
      .split(separator: "\n")
      .map { String($0).trimmingCharacters(in: .whitespacesAndNewlines) }
      .filter { $0.contains("utun") }

    let defaultTunnelRoutes = routeLines.filter {
      $0.hasPrefix("default") || $0.hasPrefix("0/1") || $0.hasPrefix("128.0/1") || $0.contains("198.18.0/15")
    }
    guard !defaultTunnelRoutes.isEmpty else { return nil }

    let interfaceName = defaultTunnelRoutes
      .flatMap { $0.split(whereSeparator: \.isWhitespace) }
      .map(String.init)
      .first { $0.hasPrefix("utun") } ?? "utun"

    return "检测到系统中已有其他 TUN/VPN 会话（\(interfaceName)）。请先断开其他 VPN 或网络过滤器后再启动 TUN 模式。"
  }

  private func recentTunFailureMessage() -> String? {
    let recentLines = readLatestCoreOutput()
      .split(separator: "\n")
      .suffix(100)
      .map { stripAnsi(String($0)).trimmingCharacters(in: .whitespacesAndNewlines) }
    if recentLines.contains(where: { $0.contains("configure tun interface: add route:") && $0.contains("file exists") }) {
      return "检测到系统中存在冲突路由。请先断开其他 TUN/VPN 会话后再启动 TUN 模式。"
    }
    return recentLines.last(where: {
      $0.contains("FATAL") || $0.contains("ERROR")
    })
  }

  private func replaceLatestCoreOutput(with value: String) {
    coreOutputLock.lock()
    latestCoreOutput = value
    coreOutputLock.unlock()
  }

  private func appendLatestCoreOutput(_ value: String) {
    guard !value.isEmpty else { return }
    coreOutputLock.lock()
    if !latestCoreOutput.isEmpty {
      latestCoreOutput.append("\n")
    }
    latestCoreOutput.append(value)
    if latestCoreOutput.count > 64_000 {
      latestCoreOutput = String(latestCoreOutput.suffix(64_000))
    }
    coreOutputLock.unlock()
  }

  private func readLatestCoreOutput() -> String {
    coreOutputLock.lock()
    defer { coreOutputLock.unlock() }
    return latestCoreOutput
  }

  private func stripAnsi(_ value: String) -> String {
    guard let regex = try? NSRegularExpression(pattern: "\u{001B}\\[[0-9;]*[mK]") else {
      return value
    }
    let range = NSRange(value.startIndex..<value.endIndex, in: value)
    return regex.stringByReplacingMatches(in: value, range: range, withTemplate: "")
  }

  private func cleanupRoutes() {
    let routeDeletes = [
      ["-n", "delete", "-net", "0.0.0.0/1"],
      ["-n", "delete", "-net", "128.0.0.0/1"],
      ["-n", "delete", "-net", "1.0.0.0/8"],
      ["-n", "delete", "-net", "198.18.0.0/15"]
    ]
    for args in routeDeletes {
      _ = runCommand("/sbin/route", args: args)
    }
  }

  private func machineArchitecture() -> String {
    let output = runCommand("/usr/bin/uname", args: ["-m"]).trimmingCharacters(in: .whitespacesAndNewlines)
    return output == "arm64" || output == "aarch64" ? "arm64" : "amd64"
  }

  private func getStatusDictionary() -> [String: Any] {
    [
      "ok": true,
      "label": helperLabel,
      "clientUID": Int(clientUID),
      "coreRunning": isCoreRunning(),
      "logPath": logURL.path
    ]
  }

  private func runCommand(_ executable: String, args: [String]) -> String {
    let process = Process()
    process.executableURL = URL(fileURLWithPath: executable)
    process.arguments = args
    let pipe = Pipe()
    process.standardOutput = pipe
    process.standardError = pipe
    do {
      try process.run()
      let data = pipe.fileHandleForReading.readDataToEndOfFile()
      process.waitUntilExit()
      return String(data: data, encoding: .utf8) ?? ""
    } catch {
      return "\(error)"
    }
  }

  private func writeJSON(_ value: [String: Any], to url: URL) {
    guard JSONSerialization.isValidJSONObject(value),
          let data = try? JSONSerialization.data(withJSONObject: value, options: [.prettyPrinted])
    else { return }
    try? data.write(to: url)
  }

  private func ensureLogDirectory() {
    try? fileManager.createDirectory(at: logDirectory, withIntermediateDirectories: true)
    if !fileManager.fileExists(atPath: logURL.path) {
      fileManager.createFile(atPath: logURL.path, contents: nil)
    }
  }

  private func rotateLogIfNeeded() {
    guard let attributes = try? fileManager.attributesOfItem(atPath: logURL.path),
          let size = attributes[.size] as? NSNumber,
          size.uint64Value >= maxLogFileSize
    else { return }

    do {
      for index in stride(from: retainedLogArchiveCount, through: 1, by: -1) {
        let destination = logDirectory.appendingPathComponent("tun-helper.log.\(index)")
        let source = index == 1
          ? logURL
          : logDirectory.appendingPathComponent("tun-helper.log.\(index - 1)")
        if fileManager.fileExists(atPath: destination.path) {
          try fileManager.removeItem(at: destination)
        }
        if fileManager.fileExists(atPath: source.path) {
          try moveCappedLogFile(from: source, to: destination)
        }
      }
      fileManager.createFile(atPath: logURL.path, contents: nil)
    } catch {
      NSLog("Failed to rotate TUN helper log: %@", error.localizedDescription)
    }
  }

  private func moveCappedLogFile(from source: URL, to destination: URL) throws {
    let attributes = try fileManager.attributesOfItem(atPath: source.path)
    let size = (attributes[.size] as? NSNumber)?.uint64Value ?? 0
    guard size > maxLogFileSize else {
      try fileManager.moveItem(at: source, to: destination)
      return
    }

    let handle = try FileHandle(forReadingFrom: source)
    defer { try? handle.close() }
    try handle.seek(toOffset: size - maxLogFileSize)
    let tail = try handle.readToEnd() ?? Data()
    try tail.write(to: destination, options: .atomic)
    try fileManager.removeItem(at: source)
  }

  private func log(_ message: String) {
    logLock.lock()
    defer { logLock.unlock() }
    ensureLogDirectory()
    rotateLogIfNeeded()
    let line = "[\(ISO8601DateFormatter().string(from: Date()))] \(message)\n"
    if let data = line.data(using: .utf8), let handle = try? FileHandle(forWritingTo: logURL) {
      handle.seekToEndOfFile()
      handle.write(data)
      handle.closeFile()
    }
  }
}

final class HelperDelegate: NSObject, NSXPCListenerDelegate {
  private var helper: TunHelper?

  func listener(_ listener: NSXPCListener, shouldAcceptNewConnection connection: NSXPCConnection) -> Bool {
    let clientUID = connection.effectiveUserIdentifier
    var consoleStat = stat()
    guard clientUID != 0,
          stat("/dev/console", &consoleStat) == 0,
          consoleStat.st_uid == clientUID,
          let passwordEntry = getpwuid(clientUID)
    else {
      NSLog("Rejected TUN helper XPC client uid=%d", clientUID)
      return false
    }

    let homePath = String(cString: passwordEntry.pointee.pw_dir)
    let clientHomeDirectory = URL(fileURLWithPath: homePath, isDirectory: true)
    let exportedHelper: TunHelper
    if let helper {
      exportedHelper = helper
    } else {
      let newHelper = TunHelper(
        clientUID: clientUID,
        clientHomeDirectory: clientHomeDirectory
      )
      helper = newHelper
      exportedHelper = newHelper
    }

    connection.exportedInterface = NSXPCInterface(with: ElephantTunHelperProtocol.self)
    connection.exportedObject = exportedHelper
    connection.resume()
    return true
  }
}

let delegate = HelperDelegate()
let listener = NSXPCListener(machServiceName: helperLabel)
listener.delegate = delegate
listener.resume()
RunLoop.main.run()
