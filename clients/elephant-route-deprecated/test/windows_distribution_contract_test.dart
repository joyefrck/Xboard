import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('Windows installer has stable identity and lifecycle cleanup', () {
    final installer =
        File('windows/installer/ElephantNetwork.iss').readAsStringSync();
    final pubspec = File('pubspec.yaml').readAsStringSync();

    expect(
      installer,
      contains('AppId={{5F1D7A6E-2B3C-4A91-9D74-E0C8F6B1A245}'),
    );
    expect(installer, contains('PrivilegesRequired=admin'));
    expect(installer, contains('ElephantNetworkService'));
    expect(installer, contains('MicrosoftEdgeWebview2Setup.exe'));
    expect(installer, contains('vc_redist.x64.exe'));
    expect(installer, contains('function PrepareToInstall'));
    expect(installer, contains('function InitializeUninstall'));
    expect(installer, contains('RemoveUserData := True'));
    expect(installer, contains('RestoreOwnedLegacyProxy'));
    expect(installer, contains('/IM sing-box-windows-amd64.exe'));
    expect(installer, contains('#define AppVersion "1.6.8"'));
    expect(installer, contains('#define AppBuild "10608"'));
    expect(pubspec, contains('version: 1.6.8+10608'));
  });

  test(
      'release script builds unsigned artifacts and preserves integrity checks',
      () {
    final script = File('scripts/build_windows_release.ps1').readAsStringSync();

    expect(script, isNot(contains('WINDOWS_CERT_THUMBPRINT')));
    expect(script, isNot(contains('New-SelfSignedCertificate')));
    expect(script, isNot(contains('signtool.exe')));
    expect(script, contains('Get-AuthenticodeSignature'));
    expect(script, contains('MicrosoftEdgeWebview2Setup.exe'));
    expect(script, contains('vc_redist.x64.exe'));
    expect(script, contains(r'Get-FileHash $Installer -Algorithm SHA256'));
  });

  test('native service is constrained to local IPC and hosts sing-box', () {
    final header = File('windows/common/windows_protocol.h').readAsStringSync();
    final goModule = File('windows/service_go/go.mod').readAsStringSync();
    final pipe = File('windows/service_go/pipe_windows.go').readAsStringSync();
    final service =
        File('windows/service_go/service_windows.go').readAsStringSync();
    final core = File('windows/service_go/core_singbox.go').readAsStringSync();
    final legacyService = File('windows/service/service_main.cpp');
    final bridge =
        File('windows/runner/windows_service_bridge.cpp').readAsStringSync();

    expect(header, contains('kPipeName'));
    expect(header, contains('ElephantNetworkService.v1'));
    expect(header, contains('kMaxConfigBytes = 4 * 1024 * 1024'));
    expect(goModule, contains('github.com/sagernet/sing-box v1.12.25'));
    expect(legacyService.existsSync(), isFalse);
    expect(pipe, contains(r'\\.\pipe\ElephantNetworkService.v1'));
    expect(
      pipe,
      contains(r'D:P(A;;GA;;;SY)(A;;GA;;;BA)(A;;GRGW;;;IU)'),
    );
    expect(service, contains('svc.Run(serviceName, host)'));
    expect(service, contains('coreStartTimeout = 60 * time.Second'));
    expect(core, contains('box.New'));
    expect(core, contains('ENABLE_DEPRECATED_SPECIAL_OUTBOUNDS'));
    expect(core, contains('ENABLE_DEPRECATED_LEGACY_DNS_SERVERS'));
    expect(core, contains('ENABLE_DEPRECATED_TUN_ADDRESS_X'));
    expect(core, isNot(contains('CreateProcess')));
    expect(core, isNot(contains('sing-box-windows-amd64.exe')));
    expect(bridge, contains('"default_interface"'));
    expect(bridge, contains('"tun_ipv4_address"'));
    expect(bridge, contains('JsonBoolean(json, "strict_route")'));
    expect(bridge, contains('"core_exit_code"'));
  });

  test('connected Windows latency uses the in-process service core', () {
    final vpnService =
        File('lib/core/singbox/windows_vpn_service.dart').readAsStringSync();
    final runner = File('lib/core/singbox/windows_latency_job_runner.dart')
        .readAsStringSync();
    final probe =
        File('windows/service_go/latency_probe.go').readAsStringSync();
    final guide = File('docs/windows-release.md').readAsStringSync();

    expect(vpnService, contains('WindowsLatencyJobRunner'));
    expect(vpnService, isNot(contains('WindowsServiceLatencyRunner')));
    expect(vpnService, isNot(contains('WindowsLatencySession')));
    expect(vpnService, isNot(contains('sing-box-windows-amd64.exe')));
    expect(runner, contains("'startLatencyTest'"));
    expect(runner, contains("'getLatencyTest'"));
    expect(runner, contains("'cancelLatencyTest'"));
    expect(probe, contains('for attempt := 0; attempt < 2; attempt++'));
    expect(probe, contains('DisableKeepAlives: false'));
    expect(probe, contains('best := -1'));
    expect(probe, isNot(contains('curl.exe')));
    expect(guide, contains('do not launch a second sing-box or `curl.exe`'));
  });

  test('Windows support guide collects diagnostics without sharing config', () {
    final guide = File('docs/windows-release.md').readAsStringSync();

    expect(guide, contains('Get-NetTCPConnection'));
    expect(guide, contains('Get-Service ElephantNetworkService'));
    expect(guide, contains('sing-box-windows-amd64.exe" check'));
    expect(guide, contains('Never ask'));
    expect(guide, contains('users to upload `config.json`'));
  });

  test(
    'bundled Windows core is the verified AnyTLS-capable release',
    () {
      const binaryBase = 'assets/bin/windows/sing-box-windows-amd64';
      const binaryPath = '$binaryBase.exe';
      final result = Process.runSync(binaryPath, const ['version']);
      final version = File('$binaryBase.version').readAsStringSync().trim();
      final checksum = File('$binaryBase.sha256')
          .readAsStringSync()
          .trim()
          .split(RegExp(r'\s+'))
          .first;
      final actualChecksum = sha256.convert(File(binaryPath).readAsBytesSync());

      expect(result.exitCode, 0);
      expect(version, '1.12.25');
      expect(result.stdout.toString(), contains('sing-box version $version'));
      expect(actualChecksum.toString(), checksum);
    },
    skip: !Platform.isWindows,
  );
}
