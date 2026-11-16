# 工单 Bot API 接入指南

机器人使用独立的 Bearer Token 访问 Xboard 工单。每轮先拉取待回复工单，再读详情和用户资料。回复、备注和关单都需要提交读取详情时拿到的版本。接口使用 JSON，时间为 Unix 秒，流量为字节。

## 启用与认证

1. 部署包含本接口的 Xboard 代码并运行标准 Laravel 迁移 `php artisan migrate --force`，创建操作记录表。
2. 生成一个随机令牌，把原文保存在机器人服务的密钥配置中。将其 SHA-256 小写十六进制摘要写入 Xboard 的 `TICKET_BOT_TOKEN_SHA256`。
3. 按部署方式刷新 Laravel 配置缓存并重载常驻 HTTP 进程。配置为空时，接口返回 403。替换摘要并重载配置即可轮换令牌。

下面的命令把令牌保存在当前 shell 变量中，只输出 Xboard 需要的摘要。

```sh
export TICKET_BOT_TOKEN="$(openssl rand -hex 32)"
printf '%s' "$TICKET_BOT_TOKEN" | openssl dgst -sha256
```

所有请求都通过 HTTPS 发送 `Authorization: Bearer <令牌>`。同一把密钥代表一个名为 `ticket-bot` 的服务身份，可读全部工单和工单关联的用户资料，可回复、备注和关闭工单。密钥应保存在机器人服务端。现有客服查询密钥与这套接口独立。

## 读取工单

以下路径均相对于 `/api/v1/ticket-bot/tickets`。成功的 JSON 响应将内容放在 `data` 中。

| 方法和路径 | 返回内容 |
| --- | --- |
| `GET /` | 工单摘要，默认只取待客服回复的工单。按工单 ID 从小到大排列。 |
| `GET /{id}` | 主题、类型 `level`、处理状态、创建和更新时间、内部备注 `remarks`、版本 `version`，以及按消息 ID 正序排列的 `messages`。 |
| `GET /{id}/user-context` | 该工单用户的 ID、脱敏邮箱、注册时间、账号状态、套餐和流量。用户记录已删除时返回 404。 |
| `GET /{id}/attachments/{attachment_id}` | 该工单对应的私有图片原始字节。格式由响应的 `Content-Type` 指明，为 JPEG、PNG 或 WebP。图片已清理或归属其他工单时返回 404。 |

列表支持 `state=pending|answered|closed|all`。其中 `pending` 表示待客服回复，`answered` 表示客服已回复且工单仍开启，`closed` 表示已关闭。`limit` 默认为 50，范围 1–100。收到非空的 `next_after_id` 时，将其作为 `after_id` 取下一页。**每轮轮询都从第一页重新开始**，旧工单收到用户追问后会重新进入待处理列表。

消息包含 `id`、`role`（`user` 或 `staff`）、`message`、`created_at` 和 `attachments`。Bot 回复在对话中显示为客服消息。每张图片提供 `id`、`name`、`mime_type` 和 `size`。

用户资料的 `account_status` 为 `normal` 或 `banned`。`plan_id`、`plan_name` 和 `expires_at` 来自用户关联套餐，结合 `active_product_type`（`plan`、`traffic_package` 或 `none`）和 `active_product_name` 判断当前产品。无固定到期日的套餐返回 `expires_at: null`。`plan_used_bytes`、`plan_remaining_bytes` 统计当前有效套餐，`package_remaining_bytes` 为流量包余额，`remaining_bytes` 为合计可用流量余额。判断账号是否封禁请读取 `account_status`。

## 回复、备注和关闭

| 方法和路径 | JSON 字段 | 行为 |
| --- | --- | --- |
| `POST /{id}/replies` | `version`、`message` | 发送 1–10000 字的客服回复，返回 `message_id`，沿用现有回复钩子和邮件通知频率。已关闭的工单拒绝回复。 |
| `POST /{id}/notes` | `version`、`note` | 在管理员备注末尾另起一行追加文字，仅客服侧可见。沿用后台 1000 字总上限，超长返回 422，原备注保留。 |
| `POST /{id}/close` | `version` | 关闭工单。对已关闭工单提交当前版本，也会返回成功。 |

每次写操作生成一个 `Idempotency-Key` 请求头，使用 UUID 即可。网络超时或收到 5xx 后，使用**相同的工单、路径、版本、内容和键**重试。成功的写操作与结果在同一事务中保存。即使工单后来有了新消息，重试也只返回第一次成功的结果。新操作使用新键。键的作用域为单张工单，允许 1–128 位字母、数字和 `._:-`。

成功结果包含 `ticket_id`、`action` 和操作后的 `version`。服务端会在数据库行锁内核对当前状态、最新消息和备注。如果机器人正在生成回答时，人工客服已回复或用户又发了截图，写入会返回 409。重新读取详情、判断是否仍需处理后，再以新版本和新键提交。工单按保留策略删除时，相应的 Bot 操作记录也会一起删除。

| HTTP 状态 | 机器人如何处理 |
| --- | --- |
| 401 / 403 | 检查令牌或服务端是否已启用 API。 |
| 404 | 工单、关联用户或图片已不存在，或图片与工单不匹配。 |
| 409 `ticket_changed` | 放弃基于旧上下文生成的操作，重新读取详情。 |
| 409 `ticket_closed` | 结束本次回复流程。 |
| 409 `idempotency_key_reused` | 同一工单上的键已用于其他请求，检查本地操作记录。 |
| 422 | 检查字段、长度、版本格式及幂等键。备注总长度超限时 `message` 为 `remarks_limit_exceeded`。 |
| 429 | 遵循 `Retry-After` 后重试。当前限制为同一来源 IP 每分钟 120 次。 |

## 调用示例

以下示例使用 curl 与 jq，将域名和工单 ID 换为已有的测试工单。

```sh
base='https://panel.example.com/api/v1/ticket-bot/tickets'
auth="Authorization: Bearer $TICKET_BOT_TOKEN"
curl --fail-with-body -sS -H "$auth" "$base?state=pending&limit=10"

ticket=123
detail=$(curl --fail-with-body -sS -H "$auth" "$base/$ticket")
version=$(printf '%s' "$detail" | jq -er '.data.version')
curl --fail-with-body -sS -H "$auth" "$base/$ticket/user-context"

# 每次新的回复生成一次，将 key、version 和 body 保留到请求成功。
key=$(uuidgen)
body=$(jq -n --arg v "$version" --arg m '请告诉我遇到问题的客户端版本。' '{version:$v,message:$m}')
curl --fail-with-body -sS -H "$auth" -H "Idempotency-Key: $key" \
  -H 'Content-Type: application/json' -d "$body" "$base/$ticket/replies"
```

## 本地验证

`php tests/ticket-bot-api.php` 使用内存 SQLite、临时图片存储和模拟邮件队列，覆盖全部七个 HTTP 入口。

验证 MySQL 行锁时，可启动仅监听临时 Unix socket 的独立 MySQL 实例，并创建空库 `ticket_bot_fixture`。设置 `TICKET_BOT_TEST_MYSQL_SOCKET` 为其 socket 路径后执行同一脚本。脚本会在该库建立测试数据，用两个 PHP 进程同时重试同一操作，校验只产生一条回复。
