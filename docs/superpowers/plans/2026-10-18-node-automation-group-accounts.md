# 公共检测账号按权限组实现计划

**Goal:** 修复单一检测账号不能覆盖多个权限组的错误。
**Architecture:** settings 增加权限组到用户 ID 的 JSON 映射，ProxyProbe 依据节点权限选择账号，管理页逐权限组编辑。
**Tech Stack:** Laravel / PHP、现有 React 管理模块、SQLite 隔离集成测试。

- [x] 在 tests/node-automation-api.php 增加双权限组保存、选择、清除、错误归属、用户失效及并发保护断言，运行确认旧实现失败。
- [x] 新增 database/migrations/2026_10_18_000001_add_group_probe_users_to_node_automation_settings.php；实现安全升级、旧账号归组与认证失效，增加迁移回归。
- [x] 修改 NodeAutomationSetting、ProxyProbe 与 AutomationController：保存映射、返回权限组和账号标识、按组搜索、实际节点权限校验。
- [x] 修改 node-automation.js 的权限组账号列表与节点操作可用条件，更新预览数据和 docs/node-automation.md。
- [x] 运行 node --test tests/node-automation.test.js、相关权限回归、PHP 语法、后台资源版本检查及隔离浏览器交互验收，核对差异。

验收：node --test tests/node-automation.test.js tests/horizon-provisioning.test.js（13 项）；API 54 条断言、迁移升级与权限 22 条检查通过；Chrome 本地预览完成分组搜索、保存、单组清除和刷新保留检查。本地阶段未连接生产、未提交代码。


## 2026-10-18 生产热更新验收

- 方式：最小文件包更新宿主机挂载源码；未提交或推送 GitHub。保留线上历史 Horizon、流量包、订阅监控及 Compose 热修复。基础镜像仍为 `ghcr.io/joyefrck/xboard:sha-3231603a968a8d66c765749ce0e6b12b11210b02`；本次未构建新镜像，不将镜像标签视为新源码版本。
- 文件包 SHA-256：`77ee9a5d83d8380ae6af33c28809caaa1330034761e1079bd11a68ac17110785`。前端版本 `admin-93a6bb0221ded8a2e921`，逐文件校验和资源版本检查通过。
- 备份：`/root/xboard-release-backups/20261018-group-accounts-010604/`，包含相关数据库表、代码、Compose、策略及 DNS 快照；已在隔离数据库恢复并核对域名组、备用数和原账号，再移除临时验证库。
- 只执行本次 `2026_10_18_000001_add_group_probe_users_to_node_automation_settings` 迁移。旧账号迁入实际所属 Level2，旧全局字段已清空。Level3 等其余组未代选账号。
- Web 与 Horizon 容器均已重建并核对新容器 ID，保留原镜像、init 与外部网络；Redis PONG。生产 PHP 环境下 API 54 条断言、状态机 39 条、旧/新迁移升级回归通过。
- 01:12:06、01:12:42、01:13:19（Asia/Shanghai）三轮采样：11 条队列均有活跃消费者，实际选择 `local`；临时统计与流量 ready 任务最终清零，新增失败任务 0，僵尸进程 0。
- Web/Horizon PID 1 为 docker-init，pids.current 分别 18/27，pids.max 均 8831，重启计数 0。全新 SSH 会话再次确认 Horizon、Redis 和本地公开接口正常。
- 自然业务：邮件任务 2 条 completed、邮件错误 0；Telegram 任务 8 条 completed；观察到 3 次注册、1 笔支付订单且 status=3（已完成）；用户/节点流量统计最近更新约 1 秒。此证据不代表收件箱/设备端已确认，也未逐项验证没有自然任务的工单通知与群资格流程。未伪造注册、付款或测试消息。
- 公网首页、管理页、公开接口和新静态资源 HTTP 200。Chrome 已验证 Level2/Level3 各自独立保存入口，未再显示全局共用账号说明。
- 41 条 DNS 记录的 id/name/type/content/ttl/proxied 与发布前一致，无新增切换。原开启的 jp24/jp25 缺少 Level3 账号、us5 已无关联节点，均保持暂停；用户明确选择稍后自行配置 Level3。真实备用及自动切换演练尚未验收。


## 同日跟进：独立流量包兼容修复

- 根因：ProxyProbe 将周期套餐到期作为独立拒绝条件，错误阻止仍有有效独立流量包余额的账号；普通节点资格查询原本已允许该账号使用 Level3 节点。
- 修复：未封禁、无待开通订单、代理身份有效且归属正确时，接受「未到期周期套餐余额」或「有效独立流量包余额」任一路径。不改动用户期限、余额、流量包账本或节点权限。
- 回归：先重现旧逻辑失败，再验证修复通过；覆盖到期套餐加流量包、纯流量包、封禁、待开通、错误权限组、无效状态与零余额流量包。API 63 条、状态机 39 条、权限同步 22 条检查通过，Node 15 项通过；生产 PHP 环境隔离 API 回归同样通过。
- 已按原有源码热更新方式部署 ProxyProbe、测试和文档，Web/Horizon 均已重建，镜像及前端版本不变，未提交/推送 GitHub。
- 发布包 SHA-256：`b8a84dfdaf70b8acbeeca84f3a9430c0924847fc62ec9c24934fcaa8d8c97be6`。
- 本次备份：`/root/xboard-release-backups/20261018-probe-package-011836/`；相关表备份已在隔离库恢复，权限组账号映射、域名组及备用列表核对一致。
- Chrome 实际搜索、保存用户已选定的 Level3 账号成功，刷新后保留；Level2 配置仍在。节点 24、25 的账号选择与实际使用权限检查通过。保持所有自动化暂停，未执行真实备用探测或自动切换；41 条 DNS 记录比对未变。
- 01:19:54、01:20:31、01:21:07（Asia/Shanghai）采样覆盖全部 11 队列，失败任务和僵尸进程 0；临时流量/统计任务队首年龄约 1–3 秒，无长时间不消费。数分钟后全新 SSH 会话再次通过覆盖检查。
- 自然业务记录显示工单通知任务 2 条 completed、订单后台任务 3 条 completed，流量/统计持续消费，用户和节点统计约 1 秒前更新。该窗口没有新注册、支付或邮件日志，相关端到端结果未重复验收；未主动发送测试通知。
- Redis PONG，Web/Horizon 为 docker-init、重启计数 0、pids.current 17/26（上限 8831）。公网与前端资源检查通过。
