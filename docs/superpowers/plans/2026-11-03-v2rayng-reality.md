# v2rayNG 普通 REALITY 订阅兼容优化

**Goal:** 修复已在 Pixel 6 Pro 验证可连接的 v2rayNG 2.2.2 被订阅过滤的问题。只修改 v2rayNG；用户在本地验收完成后追加授权发布生产。

**Architecture:** 沿用 General 的原生 VLESS URI。以实机 Xray-core v26.6.1 的结果建立 v2rayNG 2.x、版本不低于 2.2.2 的普通 TCP REALITY 能力规则。保留增强加密、XHTTP、其他传输及其他客户端的现有门禁。明确识别以 v2rayNG 开头的版本，拒绝从兼容标记继承其他应用版本；平台约束允许 Android 或未声明平台。

**Tech Stack:** PHP/Laravel 订阅控制器、现有独立 PHP 回归脚本、ADB Android 实机。

用户已经选择上一轮提出的 v2rayNG 优化方案。比较过只改能力规则与全局移除过滤：采用前者并补充身份解析边界，避免扩大其他客户端能力。无需改节点表单、服务端核心或订阅地址。

## 实施步骤

- [x] 新增 `tests/v2rayng-reality-subscription.php`，通过实际控制器验证现代 REALITY 的 18 节点输出、原生 URI 参数、连续刷新、元信息及敏感字段剔除；验证旧版、预发布版、未知身份、增强节点和其他客户端继续被过滤。先运行确认原实现失败。
- [x] 调整 `VlessConfigService::supportsClient()` 的 v2rayNG / General 分支，仅支持普通 TCP（无 HTTP 伪装）REALITY；调整 `ClientController::getClientInfo()`，将 v2rayNG 纳入严格原生身份识别并识别其平台，不修改其他客户端策略。General 仅为 v2rayNG 成功响应补充 `subscription-userinfo`，修复原渲染器成功响应没有流量头的问题。
- [x] 将新增回归加入现有 VLESS CI；运行 VLESS、iOS 订阅、Elephant 订阅和 PHP 语法检查。
- [x] 使用当前实际订阅的节点参数，通过修改后的本地控制器生成完整订阅；用仅 USB 回环可访问的临时地址验证 v2rayNG 导入、连续刷新、东京 03 连接及 HTTPS 出口。此项与生产原链接验收区分记录。
- [x] 停止测试 VPN 和临时服务，删除本轮 USB 测试订阅分组、ADB 转发与设备临时输入文件，检查 diff。

## 验证记录

- 新增实际订阅链路 132 项通过；修改前在“普通现代与旧节点应同时保留”的断言失败，修复后通过。
- `php tests/vless-config.php` 73 项、`php tests/vless-elephant-subscription.php` 41 个场景、`php tests/ios-reality-subscription.php` 179 项通过；相关 Node 回归 14 项通过，四个 PHP 文件语法检查及 `git diff --check` 通过。
- Pixel 6 Pro / Android 17 / v2rayNG F-Droid 2.2.2：通过 USB 回环访问本地实际订阅控制器，真实 UA 为 `v2rayNG/2.2.2`。初次导入及两次刷新全部 HTTP 200、18 节点；逐屏核对 18 个节点名称完整且无重复。
- 从导入的完整订阅中选择东京 03，真实连接 74ms，Chrome Google 搜索加载成功，浏览器出口与该节点一致。没有测试其余 17 条线路的连通性。
- 本地验收时生产没有部署，原链接的生产 HTTP 422 状态不在本地修复完成声明内。USB 临时服务和端口转发已停止；原用户分组和上一轮单节点对照保留。

## 验收边界

原订阅生产链接需发布后才能恢复；本地验收不能标记生产已修复。NekoBox、Hiddify、Surfboard 的订阅与服务器配置均不改动。未验证的更高主版本不自动放行。单节点连接测试不代表全部线路在线。

## 追加生产授权

2026-11-03 用户明确要求“发布生产”。按完整 Xboard 发布检查执行：当前分支提交推送、对应 CI 与双架构签名镜像验证、生产漂移核对和备份、最小文件部署、全部业务队列的三轮趋势与新 SSH 会话复查、公网检查；上线后使用手机原订阅链接验证 18 节点刷新和选定节点访问。保留生产已有 bind mount 与历史改动，不覆盖整站。
