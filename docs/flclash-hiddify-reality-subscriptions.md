# FlClash / Hiddify / v2rayN 混合 REALITY 订阅

## 支持范围

| 客户端 | 最低准入版本 | 输出 | 本轮验证 |
| --- | --- | --- | --- |
| 官方 FlClash | 0.9.3 正式版 | ClashMeta YAML | macOS 0.9.3 内核解析完整默认订阅；Linux arm64 0.9.3 安装包内核连接验证 |
| Hiddify | 5.5.5 正式版 | SingBox JSON | Linux arm64 5.5.5 官方内核解析完整默认订阅并连接验证 |
| v2rayN | 7.25.6 正式版 | 原生 VLESS URI（Base64 订阅） | Linux arm64 7.25.6 随包 Xray 26.10.10 连接验证；需使用支持混合 REALITY 的 Xray 内核 |

本轮开放 TCP + VLESS + REALITY + Vision 的 X25519MLKEM768 混合握手，也兼容未设置 Vision 的普通 TCP 配置。TCP HTTP 伪装、混合 REALITY 的其他传输、VLESS Encryption 和 XHTTP 不因本次变更放行。大象 ElephantNetwork 2.1.0 的准入及增强功能保持独立。

版本缺失、预发布或四段版本不视为通过验证的正式版本。更高正式版本沿用最低版本准入，未逐个测试未来版本。版本门槛是订阅能力判断，不是身份认证。

## 使用

发布此修改后，三个客户端使用原订阅链接即可，无需追加格式参数。客户端的自定义 User-Agent 应恢复默认：

- `FlClash/v0.9.3 clash-verge Platform/macos`
- `HiddifyNext/5.5.5 (macos) like ClashMeta v2ray sing-box`
- `v2rayN/7.25.6`

原生产品名称优先于复合 UA 中的兼容名称，HiddifyNext 规范化为 Hiddify。FlClash 不再套用大象的 2.1.0 门槛。格式 flag 仍可选择渲染器，但不能为这两个产品抬高实际 UA 中的版本或授予其他渲染器的能力。

FlClash 收到 `client-fingerprint: chrome` 和 `reality-opts.support-x25519mlkem768: true`。Hiddify 收到 `tls.utls.fingerprint: chrome` 和原生 `tls.reality.key_share: hybrid`。Hiddify 专有字段不写入其他 sing-box 客户端的配置，传统 REALITY 不自动添加该字段。

## 验证

```sh
php tests/flclash-hiddify-reality-subscription.php
php tests/v2rayn-reality-subscription.php
php tests/vless-config.php
php tests/vless-elephant-subscription.php
php tests/ios-reality-subscription.php
php tests/v2rayng-reality-subscription.php
php tests/passwall-reality-subscription.php
php tests/node-automation-reality.php
```

新增订阅测试经过真实控制器与渲染器，覆盖官方复合 UA、422 恢复、原生输出、版本与格式边界、增强节点过滤、后端推导、元数据和服务端秘密排除，并已加入 CI。

2026-11-10 隔离连接使用经官方 Release SHA-256 核验的 FlClash 0.9.3 与 Hiddify Core 5.5.5 Linux arm64 二进制，服务端为 Xray 26.11.9。TLS 1.3 伪装目标仅接受 X25519MLKEM768。使用 Xboard 实际订阅输出的节点字段，经两个客户端分别传输 HTTP、HTTPS 各 128 KiB，逐字节核验并在负向测试后重新连接。

两个客户端的错误 Short ID 均失败；FlClash 关闭混合握手、Hiddify 指定 classical 均失败。完整默认订阅分别通过原生 macOS FlClash 的配置与 Profile 解析，以及 Hiddify 官方内核的 `parse` 与配置检查。

证据位于本地 `output/flclash-hiddify-reality-20261110/`。本轮未验证各平台 GUI 导入、系统代理、TUN 或生产线路，也未执行生产部署。发布仍需完整 Xboard 发布验收及线上原链接复测。

v2rayN 7.25.6 的独立证据位于 `output/v2rayn-reality-audit-20261110/`。随包 Xray 26.10.10 在相同隔离服务器和仅接受 X25519MLKEM768 的 TLS 目标下，通过 HTTP、HTTPS 各 128 KiB 传输、错误 Short ID 负测和恢复重测。该测试将 Xboard 原生 URI 按固定版本的官方导入器/配置生成器映射为 Xray 配置，未执行 GUI 导入。v2rayN 的 sing-box 模式不属于此验证结论；自定义旧内核也不能仅凭 App 版本判断可用。

## 官方来源

- [FlClash 0.9.3](https://github.com/chen08209/FlClash/releases/tag/v0.9.3)
- [FlClash 固定引用的 REALITY 内核](https://github.com/chen08209/Clash.Meta/blob/024245dc72bc92f48237f865119afe0cd53ac9e6/component/tls/reality.go)
- [Hiddify 5.5.5 原生 User-Agent](https://github.com/hiddify/hiddify-app/blob/v5.5.5/lib/core/model/app_info_entity.dart)
- [Hiddify 5.5.5 固定引用的 REALITY 实现](https://github.com/hiddify/hiddify-sing-box/blob/1614c5edb52dd44a4cc079201ba1e790fdfad8a0/common/tls/reality_client.go)
