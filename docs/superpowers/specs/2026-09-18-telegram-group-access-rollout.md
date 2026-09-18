# Telegram 入群审核：上线和运行说明

状态：代码实现及本地验收完成。以下为生产发布操作手册，实际部署进度以发布记录为准。正式入群审核默认关闭。

业务口径见 [完整流程说明书](2026-09-18-telegram-group-access-guide.md)。付费购买（含余额和流量包）、管理员实际发放套餐均产生长期资格；过期、耗尽或更换套餐不会删除资格。自动试用、免费优惠订单和仅充值不产生资格。已有群成员不清退。

## 1. 代码覆盖范围

- `TelegramGroupEligibilityService` 统一管理永久资格。订单开通成功、后台用户修改时实际增配/续期、单个和批量创建时显式分配套餐、管理员发放流量包均在原事务中记录资格。
- `TelegramGroupAccessService` 生成 10 分钟审批邀请，将加密链接与网站用户、Telegram 身份及群 ID 关联；重复点击复用有效邀请。
- `POST /api/v1/user/telegram/join-group` 仅使用当前登录用户身份；每个限流键每分钟最多 6 次。`GET /api/v1/user/telegram/group-status` 返回状态。两者禁止响应缓存。
- 普通用户配置的 `telegram_discuss_link` 固定返回 `null`，不再提供旧链接。新字段 `telegram_group_access_enabled` 控制 Dashboard 入口。旧客户端如果只支持原群链接，会隐藏该入口，需要使用新版网页。
- 普通绑定 Bot 接收 `chat_join_request`，持久化请求后投递独立队列。工单 Bot、工单密钥和工单队列保持独立。
- `telegram_group` 队列使用独立连接配置和 `XboardTelegramGroup` supervisor。重复回调、重复投递及批准响应丢失可恢复；最长五次业务尝试后转 `failed`，不会自动放行。
- 批准和邀请消费状态在同一数据库事务中落盘。成功后撤销邀请，临时撤销失败由恢复命令补做。
- Telegram 绑定拒绝同一 Telegram 绑定多个网站账号。已经用某个 Telegram 身份成功入群的账号，换绑后需人工处理，不能用来给多个 Telegram 账号重复放行。
- 后台 Telegram 设置增加官方群 ID、配置检查、审核开关；配置未就绪时服务器拒绝启用，界面恢复已保存值。审核开启期间修改 Bot 令牌或站点网址，须先关闭审核并重新配置 Webhook。

## 2. 发布前

1. 备份数据库和应用密钥。邀请链接使用现有 `APP_KEY` 加密，不能随意更换密钥。
2. 保持入群审核关闭。在新代码环境先执行本次迁移，再让 Web 和订单等队列加载新代码：

   ```sh
   php artisan migrate --path=database/migrations/2026_09_18_000001_create_telegram_group_access_tables.php --force
   ```

   此迁移只创建资格、邀请、申请三张表，不自动授予历史资格。新版本付款/管理员发放会立即写入资格，因此必须先建表。

3. 预览历史付费及明确人工结算订单，不写数据库：

   ```sh
   php artisan telegram:group-backfill
   ```

   输出网站用户 ID、来源、订单 ID，以及来源不明的 `needs_review` 用户 ID。预览可能按多笔订单重复列出同一用户，实际写入时每个用户只有一条首次资格记录。

4. 核对历史付款清单后执行：

   ```sh
   php artisan telegram:group-backfill --apply
   ```

5. 对历史管理员赠送、手动添加且无法从订单确认的用户，使用核实后的真实 ID 和依据。以下 `123`、`456`、`1` 是占位示例，执行前替换：

   ```sh
   php artisan telegram:group-backfill --user-id=123 --user-id=456 --operator-id=1 --reason='已核对历史管理员套餐发放记录'
   # 先审阅上一条预览，再执行同一名单：
   php artisan telegram:group-backfill --user-id=123 --user-id=456 --operator-id=1 --reason='已核对历史管理员套餐发放记录' --apply
   ```

   管理员 ID 必须对应现存管理员；所有用户 ID 预校验通过后才写入。当前 `plan_id` 或无订单的流量包只作为核对线索，不自动推断为管理员赠送。已经被移除且无历史证据的套餐不会出现在自动候选列表，需要人工提供 ID。

## 3. 群侧配置及正式启用

1. 使用目标私有群，Bot 设为管理员并授予邀请/审批权限。
2. 人工核对并撤销原有免审邀请，限制普通成员直接拉人；其他管理员不得绕过审核随意放人。这些群侧操作不由本次代码自动执行。
3. 后台「Telegram 设置」填写官方群 ID（例如 `-100…`），保持绑定 Bot 开启。重新点击普通 Bot 的「设置 Webhook」，包含消息、回调及入群事件。
4. 点击「检查群配置」。检查私有群、Bot 管理员审批权限，以及站点 Webhook 地址、认证参数和事件订阅。通过检查不代表已完成旧链接与历史名单的人工核对。
5. 部署环境清理旧配置缓存（若使用），按现有运维方式重载 Web、Horizon 和调度服务。确认活动环境下出现 `XboardTelegramGroup`。当前仓库 Horizon 配置的环境键是 `local`；其他 `APP_ENV` 的部署须同步配置该 supervisor。
6. 确认每分钟调度正常，后台开启「启用入群审核」。开关关闭时不签发、不处理新申请，也不会恢复旧裸链接。
7. 使用少量已确认的测试账号完成真实验收：付费过期、管理员发放过期、未获资格、未绑定、转发邀请、退出后重入。查看 Telegram 实际成员状态与服务器请求记录，不能只凭网页展示链接认定入群成功。

## 4. 失败恢复与运行观察

申请表的 `status` 为 `pending`、`approved`、`declined`、`failed`；`attempts`、`next_attempt_at`、`last_error` 用于检查恢复情况。不记录原始 Bot 报错或明文邀请链接。

```sh
php artisan horizon:status
php artisan telegram:group-retry
# 排除网络、权限等故障后，人工重新排队当前目标群失败申请：
php artisan telegram:group-retry --failed
```

恢复命令默认只处理当前启用的目标群。每分钟调度在后台运行，避免 Telegram 故障拖住其他调度任务；队列任务去重，并通过用户级锁串行签发与审核。判断链接时使用 Telegram 提供的申请提交时间，因此有效期内提交但排队延迟的申请仍可审核。

群配置检查的 API 暂时异常不会自动开关审核；页面提示暂不可用。申请审核失败保持待重试，达到上限后须人工处理。网络故障期间不使用原群链接作为备用通道。

## 5. 暂停及回滚

优先在后台关闭入群审核，暂停新增申请；不删除资格、不踢出现有成员。保留三张新表及密钥，以免丢失历史资格和待处理申请。

若回滚到旧版本，旧代码可能重新暴露群链接并采用旧审批规则；必须先暂停旧入口/旧自动审核并核查群侧邀请，否则不满足本次准入要求。不要直接回滚迁移删除资格表。

## 6. 本地验收记录（2026-09-18）

- `php tests/telegram-group-access.php`：72 项运行时检查。真实 SQLite 迁移、Eloquent、订单开通、管理员修改/单个/批量生成、事务回滚和历史补录；Telegram 采用模拟传输，不操作真实群。
- `php tests/telegram-group-eligibility.php`：资格边界规则通过。
- `php tests/admin-user-traffic-package-grant.php` 与 `php tests/traffic-package-access-switching.php`：原流量包行为通过。
- `node --test tests/*.test.js`：273 项通过。包括新增申请交互、过期链接、关闭弹窗后的迟到响应、后台模块缓存版本一致性和 Bot 隔离。同步修正两处旧页面缓存断言，未改动对应页面业务。
- 修改 PHP/JS 语法通过，Dashboard theme/public 镜像一致，`git diff --check` 通过。
- Chrome 使用真实 Dashboard 与后台组件、合成账号和模拟接口验证：桌面 1470px、手机 390px；正文/按钮 14px，按钮高 36px，无水平溢出。绑定命令长文本正常截断，复制反馈位于遮罩上方。后台配置检查和保存/重载通过。
- 本地 Docker SQLite 已执行本次迁移，Octane 已重载，Horizon 正常且新 supervisor 存在。`/app` 返回 200，未登录资格/申请接口返回 403，无认证 Webhook 返回 401。
- 本地审核开关保持关闭；没有历史资格批量写入，没有调用真实 Telegram 群管理接口，没有生产发布。
