# iOS REALITY 订阅兼容性

## 已验证范围

2026-11-03 真机对照使用同一有效账户的 TCP VLESS + REALITY + Vision 节点，未启用 VLESS Encryption。认证参数、服务端和测试线路不变。

| 客户端 | 实测版本 | 原订阅 | 对照结果 |
| --- | --- | --- | --- |
| Stash | iOS 3.6.5（1352） | 无服务器，策略组仅 DIRECT | 完整 YAML 可导入，多个节点 HTTP 检测通过，东京 03 为 71 ms；东京 02 有超时，不代表所有线路通过 |
| Quantumult X | iOS 1.8.1（951） | 空响应，`Response invalid` | 现有 `QuantumultX::buildVless()` 生成的东京 03 原生配置通过网页响应检测，HTTP 延迟 71 ms；单节点对照不等于完整远程订阅已验收 |
| Karing | iOS 1.2.25.2802 | `singbox outbounds: No server available` | 完整 YAML 可导入 18 节点，所测节点报 `reality verification failed`；App Store 当时最新正式版亦为 1.2.25.2802 |

对照中强制返回另一种订阅格式只用于定位，不作为正式配置或客户端能力证明。私密订阅、UUID、私钥和原始响应不进入仓库。

## 输出策略

- Stash 已验证的 3.x 版本从 3.6.5 起允许普通新版 TCP REALITY；发出 `client-fingerprint: chrome` 与 `reality-opts.support-x25519mlkem768: true`。显式 uTLS 指纹通过统一配置服务选择，传统节点不自动增加混合握手开关。
- 普通 TCP 的头部类型 `none` 或 `tcp` 不输出 `network` / `http-opts`；只有 HTTP 头部输出 `network: http`。真机启动曾发现旧渲染器错误输出 `network: none`，现由实际控制器测试和 Stash 构造器测试共同覆盖。
- 实际 Stash 请求为 `Stash/3.6.5 Clash/1.11.0`，不包含操作系统字段。没有平台字段时仅按已验证 3.x 版本序列处理；显式 macOS 标识及 4.x 不会套用这项 iOS 放行规则。此规则不是 macOS 的验证结论。
- Quantumult X 从已实测 1.8.1 起允许普通新版 TCP REALITY；规范 `Quantumult%20X`、`Quantumult X`、`Quantumult-X`、`QuantumultX` 标识。实际 iOS UA 为 `Quantumult%20X/1.8.1 (iPhone17,1; iOS 27.2)`。保持原生 REALITY、Vision 和 `fast-open=false` 字段，不输出 Mihomo 专有开关。显式 macOS 标识仍不放行。
- 不因本次 TCP 对照放行 Stash / Quantumult X 的 Encryption、XHTTP、现代 REALITY 的 WS/gRPC；这些组合需要独立验证。
- Karing 的复合 UA 中 Mihomo、Clash、sing-box 和 NekoBox 字样不等于实际运行对应内核版本。保留实际四段 App 版本、优先识别 App 本身；本轮不放行 Karing 的现代 REALITY。
- 显式格式 flag 只选择输出格式；版本化 flag 的现有客户端声明优先级保持不变，平台信息优先取真实 User-Agent。版本缺失、预发布或另一个产品携带的兼容名称不能冒用本次验证版本。

## 无兼容节点诊断

输入有节点、经协议类型、客户端版本或 VLESS 能力过滤后没有兼容节点时，订阅返回 HTTP 422：

```json
{
  "code": "NO_COMPATIBLE_NODES",
  "message": "当前客户端没有兼容节点，请使用已验证版本的客户端或兼容线路。",
  "diagnostics": {
    "input_nodes": 1,
    "compatible_nodes": 0,
    "protocol_rejections": 0,
    "version_rejections": 0,
    "vless_feature_rejections": 1,
    "hybrid_reality_rejections": 1
  }
}
```

Karing 因现代 REALITY 被过滤时返回对应提示。错误保留 `subscription-userinfo`，使用 `Cache-Control: no-store`；响应头包含 `X-Subscription-Compatible-Nodes`、`X-Subscription-Filtered-Nodes` 和 `X-Subscription-Vless-Filtered-Nodes`。混合订阅仍返回兼容节点，全部计数属于单个协议实例，不进入共享缓存。原本没有可用节点、权限筛选和用户主动筛选到零条不冒充客户端不兼容。

错误只包含分类计数，不含节点名称、ID、凭据、订阅 token 或完整 URL。各 App 如何展示 HTTP 422 及缓存旧配置，仍须在发布后使用原链接验证。

## 验证和发布

```sh
php tests/ios-reality-subscription.php
php tests/vless-config.php
php tests/vless-elephant-subscription.php
php tests/node-automation-reality.php
node --test tests/stash-subscription-compatibility.test.js tests/quantumultx-subscription-compatibility.test.js tests/karing-subscription-compatibility.test.js tests/vless-admin-form.test.js
php scripts/check-horizon-provisioning.php --environment=local
```

新增隔离测试经过真实 ClientController、协议匹配和各渲染器，包含实际 UA、原生字段、版本边界、平台与格式覆盖、增强配置隔离、空节点和同容器后续请求，以及输出元数据和秘密排除。

本地通过后，生产发布仍需要完整 Xboard 发布检查；原链接需分别验证导入、再次刷新、HTTPS 访问及错误 UUID 的负向控制。不得把前述对照项当作新版生产原链接的验收结果。

Karing 当前版若要立即连接，可另行提供经验证的兼容协议入口；套餐无兼容节点时才涉及新增节点及 V2bX 运行配置。本轮没有自动升级、降级核心、改动节点参数或扩展套餐权限。

## 参考

- [Stash 协议文档](https://stash.wiki/proxy-protocols/proxy-types)：REALITY 混合 KeyShare 参数。
- [Quantumult X 原生配置示例](https://github.com/crossutility/Quantumult-X/blob/master/sample.conf)：REALITY / Vision 字段。
- [Karing App Store](https://apps.apple.com/us/app/karing/id6472431552)：正式版本信息。
- [Karing sing-box REALITY 客户端](https://github.com/KaringX/sing-box/blob/karing_v1.14.2/common/tls/reality_client.go)：公开分支中的 ML-KEM 过滤行为；不能用公开分支单独证明商店二进制，真机结果另行记录。
