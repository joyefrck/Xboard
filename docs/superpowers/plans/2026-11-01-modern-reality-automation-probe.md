# 新版 REALITY 自动化探针修复实施方案

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** 恢复登记为 Xray/core-v1.1.0 的普通 REALITY 节点配置验证、DNS 绑定和自动化检测，发布并执行全部 Xboard 生产验收。

**Architecture:** 原 sing-box 1.14.2 保持不变；域名组包含新版 REALITY 时使用固定 Mihomo 1.19.33。两种运行时共享账号选择、回环端口分配、HTTPS 204 请求、独立 watchdog 与临时凭证清理。Mihomo 节点复用 ClashMeta 构建器；每个 SOCKS listener 绑定指定节点，默认规则拒绝，禁止直连回退。保留 Encryption/XHTTP 的现有拦截。

**Tech Stack:** PHP、Laravel、Symfony Process、sing-box、Mihomo、Python 本地 TLS/REALITY fixture、Node test runner、Docker。

用户已授权修复、发布和完整验收。继续当前 master，不操作无关未跟踪文件，不轮换生产节点密钥或修改协议。

## Task 1：先建立复现与参数测试

- [x] 新增 `tests/node-automation-reality.php`：隔离容器和 SQLite；现代 REALITY 选择 Mihomo、Chrome 与混合 KeyShare；IP 固定而 SNI 保留；错误端口拒绝；Encryption/XHTTP 仍拒绝；多 listener 各自绑定指定节点。
- [x] `php tests/node-automation-reality.php` 在修改实现前失败；修改后通过。
- [x] 更新 `tests/vless-config.php`：新 REALITY 可以配置自动化，增强 Encryption/XHTTP 仍受保护。

## Task 2：最小运行时适配

- [x] 新增 `app/Services/NodeAutomation/MihomoProbeRuntime.php`，只负责版本检查、代理配置与 listener 配置。
- [x] 修改 `ProbeProtocol.php` 明确区分传统 sing-box 和支持新版 REALITY 的运行时；不能允许增强协议被降级探测。
- [x] 修改 `ProxyProbe.php` 选择运行时并共享现有 watchdog、请求、端口与凭证清理。
- [x] 修改 `ServerSave.php` 仅解除已适配的现代 REALITY 自动化限制。
- [x] 新增 `scripts/install-node-automation-mihomo.sh`，固定官方 1.19.33 包及 SHA256；支持 Linux amd64/arm64 和 Darwin arm64。
- [x] 修改 `Dockerfile`、`config/node_automation.php` 和 `docs/node-automation.md`，安装/配置新运行时并记录兼容范围。

## Task 3：真实协议验收与回归

- [x] 使用本地 TLS 伪装端与 V2bX core-v1.1.0 启动独立 REALITY fixture，不请求生产 DNS/用户数据。
- [x] 运行真实 PHP ProxyProbe：正确身份通过 HTTPS 204；错误 UUID、错误公钥、停止服务均失败；默认拒绝阻止直连假阳性；临时凭证与进程最终清理。
- [x] 执行 `php tests/vless-config.php`、`node --test tests/node-automation.test.js tests/node-automation-status.test.js tests/horizon-provisioning.test.js tests/horizon-fixed-workers.test.js`。
- [x] 执行修改 PHP 的 `php -l`、安装脚本 `sh -n`、`git diff --check` 与 `php scripts/check-horizon-provisioning.php --environment=local`。

## Task 4：发布和生产验收

- [ ] 核实生产登录方式；记录代码、镜像、挂载和全部队列基线，备份本次修改文件及数据库。
- [ ] 提交并推送精确版本，等待对应提交 CI/镜像成功，再按现有挂载部署方式发布。
- [ ] 在用户报告的域名上验证配置与 DNS 绑定；实际探测正常节点。不擅自开启其他暂停组或切换 IP。
- [ ] 按 `xboard-release-checks` 验证实际配置选择、11 个 connection/queue 消费者、三次 30–60 秒队列趋势、新 SSH 会话复查、自然业务结果及公网可用性。
- [ ] 无终端邮件/TG、付款或其他真实场景样本时明确标为未验收，不以队列完成替代终端交付证据。

## 本地实测记录

- 新版 REALITY 参数与混合组 SNI 检查 21 项、VLESS 检查 64 项、Node 自动化/资源/队列回归 24 项通过。
- 官方 Mihomo 1.19.33 与发布包 V2bX core-v1.1.0 Linux arm64（源码 ddf6e9d）完成真实 PHP 探针验收：9 种混合协议配置检查、HTTPS 204、错误 UUID/公钥、停服、缺失运行时、临时凭证及进程清理均通过。
- 真实 SOCKS 握手发现旧 3 秒连接预算不足，新版运行时使用不超过请求总时限的 8 秒建连预算；保留 25 秒独立 watchdog。
- 生产通过 Termius 会话接入。被替换 PHP/config 文件哈希与 66f484a3 基准一致；生产使用历史 git HEAD 加选择性热更新，发布继续保留该方式，不整体覆盖工作树。
- 生产基线 14:17（Asia/Shanghai）：全部 11 个 connection/queue 有消费者；新失败 0、僵尸 0，最近 10 分钟邮件 3/错误 0、注册 3、付款 5。
