# Ticket Blacklist Implementation Plan

> **For agentic workers:** Execute inline in this chat, task by task. No delegation is needed.

**Goal:** 自动执行七天工单禁言及再犯永久封号，黑名单用于查阅记录，手动状态操作统一放在原有用户管理。

**Architecture:** DeepSeekTicketReviewer 只做文字分类；UserBlacklistService 统一处罚和记录；TicketModerationService 在审核后锁定用户并提交工单或处罚。保留既有工单服务及通知 hooks，新增可读的后台 React 模块，复用管理端布局。

**Tech Stack:** Laravel 12、Eloquent、Laravel HTTP client、现有 React 管理端、SQLite 隔离测试。

---

### Task 1: 数据与处罚服务
- [x] 新建 `database/migrations/2026_10_23_000001_create_ticket_blacklist.php`：`v2_user.ticket_muted_until`、`ticket_abuse_count`，独立 `v2_user_blacklist_event`。
- [x] 新建 `app/Models/UserBlacklistEvent.php`，修改 `app/Models/User.php` 添加字段转换和关联。
- [x] 新建 `app/Services/UserBlacklistService.php`：检查状态、首犯七天禁言、再犯封号、手动封号/解封记录、撤销登录令牌。所有变更锁用户行。
- [x] 隔离测试首犯不影响套餐、截止边界、禁言中不增加次数、再犯封禁、手动封号及登录令牌清理。

### Task 2: DeepSeek 和提交入口
- [x] 新建 `config/ticket_moderation.php`、`app/Services/DeepSeekTicketReviewer.php`、`app/Services/TicketModerationService.php`。
- [x] 固定官方 HTTPS 地址；JSON 校验布尔判定、类别、原因和当前文字中的原始证据。最长两次调用；失败抛出不包含第三方响应和凭证的 503。
- [x] 修改 `app/Http/Controllers/V1/User/TicketController.php` 的创建、回复、提现入口：先审核，锁用户重新验证，再调用已有 TicketService；通知 hooks 仍在成功后执行。
- [x] 修改 `app/Http/Middleware/User.php` 拒绝已封禁账号的既有会话。
- [x] `.env.example` 只列空密钥；本地 `.env` 安全保存用户提供的凭证。
- [x] 测试通过、违规、上下文归属、畸形输出、超时、无密钥、审核期间出现封禁的场景。

### Task 3: 管理端
- [x] 新建 `app/Http/Controllers/V2/Admin/BlacklistController.php` 并注册黑名单 fetch/detail 路由。
- [x] 修改 `UserController.php` 的编辑和批量封号，统一使用处罚服务并保留原有能力。
- [x] 新建 `public/assets/admin/assets/user-blacklist.js`、`user-blacklist.css`，接入已有 React runtime 和布局。
- [x] 修改管理端 bundle 注册 `/user/blacklist` 路由、用户管理子菜单；更新三种语言菜单文案和 Blade 资源版本。
- [x] 验证邮箱搜索、状态过滤、分页、证据转义、空态及错误态。

### Task 4: 验证与交付
- [x] `php tests/ticket-moderation.php`：隔离内存数据库与伪造 HTTP，不启动项目或读取生产配置。
- [x] `node --test tests/user-blacklist.test.js tests/admin-ticket-*.test.js tests/ticket-*.test.js`：后台模块行为及现有工单回归。
- [x] `php tests/ticket-retention.php`、`php tests/ticket-image-attachments.php`：清理和附件逻辑回归。
- [x] PHP 语法检查、JS 语法检查、`git diff --check`、扫描新增内容中没有密钥。
- [x] 只用正常投诉、明确辱骂及引用样本调用真实 DeepSeek；验证用户提供的密钥与实际模型可用。
- [x] 本地浏览器检查桌面和移动端黑名单及记录详情。
- [x] 写明上线前需要迁移、环境变量、部署及实际队列验收；不自动部署生产。

## 验证结果

- 隔离后端：98 项检查通过，覆盖原有用户编辑封号、批量封号和解封。
- Node 相关回归：88 项通过；另外工单清理、附件、套餐及流量包 PHP 验证通过。
- 真实 DeepSeek：正常投诉、明确辱骂、引用辱骂、提示注入共四个合成样本均符合预期。
- 浏览器：1440px 和 390px，手动封号提交及搜索过滤通过；手机页面宽度 390px、scrollWidth 390px。
- 密钥扫描、PHP/JS 语法和 git diff --check 通过。
- 未运行生产迁移、未推送 GitHub、未部署线上。

## 账户状态入口调整（按用户截图修订）

- [x] 移除黑名单页面的手动封号弹窗、入口按钮及独立 ban API，保留查询和证据详情。
- [x] `UserUpdate` 接受 `account_status=normal|banned|ticket_muted`；旧 `banned` 参数继续兼容。新参数优先，不能写入用户表。
- [x] `UserBlacklistService::setAccountStatus` 在用户事务中执行七天工单禁言、正常解除和账号封禁；重复保存不延长禁言，解除不清除违规历史。
- [x] 用户管理编辑抽屉、列表和详情徽标显示工单禁言；原有两个状态保留。三种语言文案齐全。
- [x] 更新隔离测试，覆盖状态切换、登录令牌保留/撤销、重复保存、再犯升级、失败回滚；移除旧弹窗测试。
- [x] 统一管理端资源版本，本地 Octane 重载，用测试账号在实际用户编辑页面确认下拉框、保存回显和黑名单没有封号按钮。

### 入口调整验收结果

- 隔离后端 125 项检查通过；Node 相关测试 87 项通过。
- 实际本地后台使用临时普通用户验证：账户状态有封禁、正常、工单禁言；保存禁言为七天，列表和重新打开抽屉回显正确；切回正常后解除，历史保留。临时账号已清理。
- 黑名单页只保留查看记录，没有手动封号按钮、封号弹窗或独立封号 API。
- 修正处罚记录时间序列化，浏览器确认显示当前北京时间。
- 本地 Octane 已重载；生产未发布。
- 补充 AI 自动封禁后由用户管理切回正常的完整验证：解封、旧会话不恢复、正常工单可提交、历史和管理员解封记录保留、再次攻击升级；隔离后端共 137 项检查通过。
