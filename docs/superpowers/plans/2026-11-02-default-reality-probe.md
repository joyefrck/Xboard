# 普通 REALITY 默认兼容探针实施计划

**Goal:** 节点未填写后端内核和 release 时，也能测试使用新旧核心的主机与备用 VPS。

**Architecture:** 用户已确认节点表单保持原样。域名组包含 VLESS 且 `tls=2` 的节点时统一选 Mihomo 1.19.33；仅在临时探针出口固定 Chrome 和 `support-x25519mlkem768=true`。只对失败 REALITY 节点使用 `false` 再尝试一次，兼容旧核心的认证 KeyShare 规则，已成功节点和非 REALITY 失败节点不补测。两次都失败才判失败。节点存储和订阅构建器保持原样；非 REALITY 域名组继续使用 sing-box。Encryption/XHTTP 继续拒绝，认证、SNI、证书校验、拒绝直连回退及每次尝试的进程 watchdog/清理沿用现有流程。

**Tech Stack:** PHP、Symfony Process、Mihomo、Node test runner、Python 本地 REALITY fixture。

## 执行步骤

- [x] 扩充 `tests/node-automation-reality.php`：无后端登记也选择 Mihomo；非 REALITY 保持原运行时；临时探针固定 Chrome 和混合 KeyShare，并验证模型及订阅输出未变化。先运行确认旧实现失败。
- [x] 调整 `app/Services/NodeAutomation/MihomoProbeRuntime.php` 的选择条件及出口参数，不修改共享订阅构建器。
- [x] 调整 `app/Services/NodeAutomation/ProxyProbe.php`，在普通 REALITY 首次失败后进行传统 KeyShare 补测；先补测试并确认旧代码失败，再验证成功与持续失败、非 REALITY 失败、身份/SNI、节点设置不变和拒绝直连回退。
- [x] 在 `tests/node-automation-reality-runtime.php` 中增加去掉后端登记与指纹的场景，验证真实 HTTPS 204、错误认证、公钥、停服和清理。Python fixture 下发 `decryption=none`，与 Xboard 实际服务端 API 保持一致，避免把客户端 `encryption=none` 误作旧核心的加密算法。
- [x] 更新 `docs/node-automation.md`，说明探针自动选择与节点表单独立。
- [x] 运行参数、自动化状态/API、VLESS/订阅、Horizon 回归及真实回环探针验收，检查 PHP 语法和 diff。

## 验证记录

- `php tests/node-automation-reality.php`：49 项通过；未登记后端的运行时选择及旧核心补测分别完成先失败、后通过验证。
- `node --test tests/node-automation.test.js tests/node-automation-status.test.js tests/horizon-provisioning.test.js tests/horizon-fixed-workers.test.js tests/vless-admin-form.test.js`：22 项通过。
- `php tests/vless-config.php`：64 项通过；`php tests/vless-elephant-subscription.php`：11 个 Elephant、32 个第三方实际订阅场景通过。
- `php scripts/check-horizon-provisioning.php --environment=local`：无缺失队列或无效 lease。修改 PHP 文件的 `php -l` 和 `git diff --check` 通过。
- 无外网的临时 Linux arm64 容器：固定 Mihomo 1.19.33 与已缓存的 V2bX core-v1.0.2/core-v1.1.0 发行包分别执行真实回环脚本并通过；覆盖已登记、未登记、订阅 Firefox、错误 UUID、公钥、停服、缺失运行时和文件/进程清理。
- 旧 core-v1.0.2 混合 KeyShare 失败可复现，增加传统 KeyShare 补测后通过。core-v1.1.0 的首个有效身份请求在部分隔离运行中出现 TLS 错误 35，最后一次完整运行通过，尚未分离该间歇失败的环境或协议原因；未通过放宽认证、证书校验或直连回退使测试通过。

## 范围

初始范围为本地代码修改。2026-11-03 用户明确授权立即发布生产，并指定用 `jp21.443ds443.com` 的 NodeID 21、备用 IP `45.78.57.58` 实际测试验收；保持当前 IP 和策略开关，不进行 DNS 切换。按 Xboard 完整发布门禁执行代码推送、CI/镜像验证、备份、部署、业务队列趋势与新 SSH 会话复查。

## 用户追加：Mihomo 客户端新版 REALITY 订阅

用户已确认目标是 Mihomo 客户端连接新版 REALITY，不是 VPS 运行 Mihomo 服务端。新增独立的新版 REALITY 兼容开关，使用已有 `reality_settings.support_x25519mlkem768` 字段；未指定自定义 uTLS 时默认 Chrome。无需填写服务端内核或 release，仍保留已有后端版本推断。旧节点默认行为、增强项后端能力门禁和客户端版本筛选保持原样。

- [x] 先补充无后端和 uTLS 配置的参数、模型持久化、管理 API、实际订阅链路和表单交互测试，并确认旧实现失败。
- [x] 在模型和保存校验中支持可选布尔字段；仅新版 REALITY 缺省指纹为 Chrome，显式不兼容指纹仍拒绝。
- [x] 在 REALITY 配置区增加兼容开关及说明，同步三种语言和后台静态资源版本。
- [x] 使用实际订阅控制器生成 Mihomo 配置，在固定新版 V2bX 上完成真实认证 HTTPS 测试；执行相关回归和隔离表单预览。

追加验证（2026-11-03，Asia/Shanghai）：

- `php tests/vless-config.php`：73 项通过，包括独立兼容开关、缺省 Chrome、模型字段保留、管理 API 验证及旧节点行为。
- `php tests/vless-elephant-subscription.php`：11 个 Elephant 和 41 个第三方/独立兼容订阅场景通过。
- 后台资源、表单、自动化状态/API 和 Horizon：26 项 Node 测试通过。`php tests/node-automation-reality.php`：49 项通过。
- 新增 `--subscription-baseline` 真实回环场景，直接调用实际订阅控制器，将生成的出口交给官方 Mihomo 1.19.33，未经过自动化出口的 Chrome/混合 KeyShare 覆盖：认证 HTTPS 204 通过，错误 UUID/停服失败，文件和进程清理通过。已加入 CI。
- Playwright 隔离表单勾选并提交：确认兼容字段为 true、内核/release 留空、自定义 uTLS 未开启、原密钥不变。截图 `output/playwright/reality-subscription-toggle.png`。仅使用本地 mock API 和 fixture 数据。
- 后台静态资源版本 `admin-a49028680d2900df72c7` 通过内容校验；相关 PHP 语法、Horizon 离线门禁和 diff 检查通过。
