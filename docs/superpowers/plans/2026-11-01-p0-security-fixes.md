# Xboard P0 修复实施方案

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在保留个性化套餐、流量包、通知、节点自动化和界面的前提下，修复已确认缺失的五组 P0 保护。

**Architecture:** 按独立问题移植上游修复，每组提供隔离运行测试。支付配置使用默认隐藏和用户接口白名单双重保护，资金变更在数据库事务内重读并锁定状态。P0 可独立交付，不等待 VLESS 或 V2bX 升级。

**Tech Stack:** PHP 8.2+、Laravel 12、Eloquent、Sanctum、Horizon、Node.js test runner。

**状态：** 独立工作区中已实施源码并开展本地验收；未提交、推送或部署。发布与生产验收仍待后续执行。

## 基准与边界

- 个性化版本：`joyefrck/Xboard`，`master`，`3deb36db44d4638a45776700ba32acf83c745b15`。
- 上游基准：`cedar2025/Xboard`，`e68fec50ef48d86f7666c5ff5e758f2a6e9fa313`。
- 仅移植必要行为；保留 `refund_amount`、独立流量包、私人套餐及其现有数据库结构。
- 支付插件继续使用当前 `plugins/` 目录；本批次不引入上游 `plugins-core/` 迁移。
- 保留现有登录验证码保护、邮件限流与失败上报、全部定制业务队列及生产挂载保护。
- 本方案不进行线上攻击验证，不生成真实订单、注册、退款或支付回调。
- 上游来源：[邮件链接](https://github.com/cedar2025/Xboard/commit/cf5cece7)、[支付配置](https://github.com/cedar2025/Xboard/commit/0fa4b4e0)、[订阅 token](https://github.com/cedar2025/Xboard/commit/e68fec50)、[支付回调](https://github.com/cedar2025/Xboard/commit/322193c5)、[订单及礼品卡并发](https://github.com/cedar2025/Xboard/commit/4f48e61a)。

## Task 1：邮件链接登录不返回凭证

**Files:**
- Modify: `app/Services/Auth/MailLinkService.php`
- Create: `tests/security-mail-link.php`
- Create: `tests/security-p0.test.js`，统一调用本方案新增隔离测试。

- [ ] 使用最小 Laravel Container、内存 Cache、隔离用户表和伪造队列写回归：开启邮件登录后，已注册/未注册邮箱都只返回 `[true, true]`；HTTP 响应不含 URL、临时 token 或用户 ID。
- [ ] 将成功返回的 `$link` 改为布尔值；登录链接仅进入已有邮件任务。
- [ ] 将 `template_name` 从不存在的 `login` 更正为现有 `mailLogin`，验证仍能生成邮件任务。
- [ ] 验证关闭功能时仍拒绝请求，发送频率限制、过期、一次性消费和禁用用户检查保持有效。
- [ ] 执行 `php tests/security-mail-link.php`；预期退出 0。新测试不加载仓库 `.env`、不启动真实邮件传输。

## Task 2：支付配置默认隐藏

**Files:**
- Modify: `app/Models/Payment.php`
- Modify: `app/Http/Resources/OrderResource.php`
- Modify: `app/Services/PaymentService.php`
- Modify: `app/Http/Controllers/V2/Admin/PaymentController.php`
- Create: `tests/security-payment-config.php`

- [ ] 构造带 `payment`、`plan`、`trafficPackage` 关系的普通/流量包订单，验证用户 JSON 不包含 `config`、签名密钥和支付配置中的嵌套值。
- [ ] `Payment` 默认隐藏 `config`；`OrderResource.payment` 仅返回 `id/name/payment/icon`，保留既有 `traffic_package` 字段。
- [ ] PaymentService 加载模型时明确读取内部配置并检查模型存在、配置为数组；不得依赖默认序列化暴露配置。
- [ ] 仅在通过管理员鉴权的配置编辑路径按需读取配置，验证后台仍可编辑原支付方式。
- [ ] 执行 `php tests/security-payment-config.php`；预期订单序列化、管理员编辑和支付内部配置读取断言全部通过。

## Task 3：订阅 token 严格校验

**Files:**
- Modify: `app/Http/Middleware/Client.php`
- Modify: `app/Utils/Helper.php`
- Create: `tests/security-subscription-token.php`

- [ ] 复用上游 `validateSubscriptionToken(mixed $token): ?string` 契约，仅接受 32 位十六进制字符串。
- [ ] 路由 token 存在时优先使用；否则仅接受 query token。POST body 不得覆盖路由或查询凭证。
- [ ] 在执行用户查询前拒绝数组、对象、整数、布尔值、空值和格式错误的字符串。
- [ ] 验证合法现有 token 的 URL 和身份不变，非法输入不触发用户查找；所有拒绝路径都不返回凭证内容。
- [ ] 执行 `php tests/security-subscription-token.php`；预期退出 0。使用隔离数据，不请求真实订阅。

## Task 4：支付回调绑定正确的验证器

**Files:**
- Modify: `app/Services/PaymentService.php`
- Modify: `plugins/Epay/Plugin.php`
- Modify: `plugins/Mgate/Plugin.php`
- Create: `tests/security-payment-callback.php`

- [ ] 保存支付记录实际配置的方法，回调 URL 方法与记录不一致时拒绝；未知或不存在的支付记录安全失败。
- [ ] 为每个 PaymentService 克隆插件实例后设置配置；验证两个商户或两个支付方式交错使用时不会互相覆盖验签配置。
- [ ] Epay/Mgate 在计算签名前拒绝空密钥、非法参数类型和缺少必要订单号/交易号；使用 `hash_equals` 比较签名。
- [ ] Epay 同时校验商户号、成功交易状态和签名类型。保持既有合法签名的排序、编码及回执协议。
- [ ] 本地 `plugins/` 没有 core/user 双目录优先级，因此不照搬上游仅服务于该目录迁移的 PluginManager 修改。
- [ ] 测试有效签名正常处理；错误方法、错误商户、错误状态、空密钥、数组字段、错误签名全部失败，且订单状态不改变。
- [ ] 执行 `php tests/security-payment-callback.php`；所有签名均使用测试密钥，禁止向实际网关发送请求。

## Task 5：取消订单、礼品卡防重复生效

**Files:**
- Modify: `app/Services/OrderService.php`
- Modify: `app/Services/GiftCardService.php`
- Modify: `app/Http/Controllers/V1/User/OrderController.php`
- Create: `tests/security-order-concurrency.php`
- Create: `tests/security-gift-card-concurrency.php`
- Create: `tests/security-money-concurrency.test.js`

- [ ] 取消订单在同一个事务中按订单 ID 加锁重读，仅允许 pending → cancelled，余额退款最多一次；竞态中输掉的取消请求返回既有安全失败语义。
- [ ] `order.cancel.before` 在锁定并确认状态后调用；`order.cancel.after` 在成功提交后调用。不得用控制器事务外读到的状态决定资金变更。
- [ ] 保留当前 `paid()` 的条件更新和 `open()` 的订单/用户锁；回归取消与支付并发、旧模型重复回调、已完成订单重放。
- [ ] 礼品卡事务内重读并锁定兑换码、模板、领取用户，再次执行资格和使用次数检查后发放奖励和记录使用；涉及邀请人余额时将相应锁和写入纳入同一事务。
- [ ] 锁顺序在同一资金路径内固定，跨用户加锁时按 ID 排序；数据库死锁重试不得重复执行提交后通知。
- [ ] 负金额订单不得走免费开通路径；真实金额 0 的合法订单仍可开通。
- [ ] 普通、私人套餐、独立流量包订单都验证取消退款一次、并发开通一次、归属保护和原有群资格行为。
- [ ] 顺序/旧模型回归用隔离 SQLite 运行；真实竞争测试必须在与生产同类型的临时数据库中以两个独立连接/进程同步起跑，断言退款总额、兑换次数和奖励余额只变化一次。SQLite 单连接通过不能替代并发验收。
- [ ] 新测试驱动仅接受显式临时数据库配置，拒绝加载应用 `.env` 或默认生产连接。`node --test tests/security-money-concurrency.test.js` 在没有隔离数据库时明确标为未验收，并阻止本组发布通过。

## Task 6：回归、发布及回滚

- [ ] 执行 `node --test tests/security-p0.test.js`，以及上述真实数据库并发测试。
- [ ] 执行 `php tests/private-plans.php`、`php tests/available-users-package-access.php`，再执行流量包、私人套餐、Telegram、工单、节点自动化相关既有回归；逐项检查脚本隔离机制后才运行。
- [ ] 对新增/修改 PHP 执行 `php -l`，对版本图执行 `php scripts/version-admin-assets.php --check`，执行 `git diff --check`。
- [ ] 执行 `php scripts/check-horizon-provisioning.php --environment=local` 及 `node --test tests/horizon-provisioning.test.js tests/horizon-fixed-workers.test.js tests/node-automation.test.js`。实际生产环境若变化，应使用重新核实的环境名称。
- [ ] 检查 CI 对精确提交构建成功，固定镜像 digest；备份代码版本和生产数据库，核实运行版本、挂载和配置缓存后再按已授权流程发布。
- [ ] 每次生产发布执行用户级 `xboard-release-checks` 全部检查：全部 11 个 connection/queue，三次 30–60 秒趋势采样、数分钟后新 SSH 会话复查、邮件/TG/工单/群资格/订单/流量结果及基础可用性。
- [ ] 终端投递、付款或并发情景没有实证时标记未验收，不能用容器 Up 或队列任务完成替代。
- [ ] P0 无必需数据库迁移。回滚优先关闭存在问题的具体入口并修正补丁，不主动恢复凭证泄露或鉴权弱点；如必须回滚应用，保留隔离措施并重新验收。

## 完成判据

五组安全测试、真实数据库竞争测试和所有受影响定制回归通过；用户接口不暴露支付/登录凭证；合法支付和订阅保持可用；生产验收覆盖所有业务队列。修复代码完成、GitHub 推送完成、镜像构建完成、生产验收完成分别报告。

## 2026-11-01 实施记录（独立工作区）

已实施相应源码修改，未提交、推送或部署。实现集中在本工作区，原主工作区和生产配置保持原状。
P0 使用隔离 Composer/Capsule 和专用临时 MariaDB，两个 PHP 进程调用真实订单/支付/礼品卡服务；不加载应用 .env。
VLESS 验证包含模型、保存校验、订阅投影、旧配置保留、探针拒绝与桌面/窄屏浏览器保存。

本批面板只导入可验证配对的 X25519 长期认证材料；VLESS Encryption 的混合 ML-KEM/X25519 密钥交换保持启用。
Mihomo XHTTP 共享 extra 字段转换为对应 xhttp-opts；xmux 转 reuse-settings。downloadSettings、h2Params 暂不接入，显式拒绝。
ECH 暂缓，真实安装包、生产节点和双架构 CI 的验证与本地源码检查分开报告。

发布仍按 P0、V2bX、VLESS 面板、独立测试节点顺序执行；本次代码修改不代表已发版。

### 本地验收结果

- P0 行为套件及专用 MariaDB 两进程竞争套件通过，未跳过数据库测试。
- 邮件一次性消费还覆盖共享 FileStore 的双进程竞争；删除缓存失败时拒绝登录。
- 发布门禁缺少隔离 DB 配置会失败；Docker build 依赖安全门禁，不能通过缺失环境假绿。
- 付款/退款/礼品卡和 Telegram 权限相关回归通过；生产邮件和通知投递不属于本次本地模拟验收。
