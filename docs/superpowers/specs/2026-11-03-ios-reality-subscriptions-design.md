# iOS 客户端新版 REALITY 订阅兼容设计

用户已在真机对照测试后批准开始修复。范围为 Xboard 的订阅识别、能力过滤、Stash 输出和无兼容节点诊断；本设计不修改 VPS 核心或新增生产节点。

## 证据和边界

- 同一有效订阅：Karing 返回无服务器的 sing-box 模板，Stash 返回无代理的 YAML，Quantumult X 返回空内容，Mihomo 返回 18 个 TCP REALITY Vision 节点；均未启用 VLESS Encryption。
- iOS Stash 3.6.5（1352）导入含混合 KeyShare 的 YAML 后，多节点 HTTP 检测通过，东京 03 为 71 ms。
- Quantumult X 1.8.1（951）使用现有 `buildVless()` 生成的东京 03 原生配置，通过网页响应检测，HTTP 延迟 71 ms。
- Karing 1.2.25.2802 是当前 App Store 正式版；完整 YAML 可导入 18 个节点，但所测节点失败为 `reality verification failed`。不得据导入成功而放行新版 REALITY。
- 以上不能证明更早版本、macOS、VLESS Encryption、XHTTP 或所有线路均兼容。

## 实现

1. 客户端实际身份与请求输出格式分别处理。规范 Quantumult X 的空格、编码空格和连字符别名，保留 Karing 四段版本；复合 UA 中的兼容标识不得替换实际 App 身份或版本。
2. 普通新版 REALITY 的能力表增加已验证的 iOS Stash、Quantumult X 版本，且仅开放 TCP。平台信息和 macOS 版本单独处理；未知或未验证客户端继续过滤。Encryption/XHTTP 仍由各自已有能力限制控制。
3. Stash 输出完整 `reality-opts.support-x25519mlkem768: true`，现代 REALITY 默认 Chrome，显式 uTLS 设置仍由统一配置服务决定。传统 REALITY 维持原有行为。
4. Quantumult X 保持已通过真机测试的原生 REALITY/Vision 字段与格式，不加入 Mihomo 专用参数。
5. 过滤后没有服务器时返回可观察、脱敏的兼容性错误与计数，不把模板中的 DIRECT 算作服务器。混合订阅保留兼容节点，并使策略组只引用实际输出节点。每次请求的诊断不得跨 Octane 请求泄漏。

## 验收

先用真实控制器到各渲染器的隔离测试复现，再修改实现；覆盖版本边界、别名、复合 UA、格式覆盖、传统节点、混合节点、增强配置禁止降级、元数据与秘密排除。最后回归现有 Mihomo/Shadowrocket/Elephant 订阅，以及 Horizon 门禁。

本地通过仅说明代码与隔离输出通过。正式原链接导入、刷新与实际 HTTPS 流量须在发布后单独验收；发布遵守全部 Xboard 发布检查。Karing 当前版本仍需经验证的兼容入口或未来已修复内核。
