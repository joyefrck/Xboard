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

## 工单退款与本期套餐权限调整

启用前运行标准迁移。保留原有工单密钥，在客服服务端另存操作令牌。每个客服或机器人使用独立令牌，服务端配置身份、令牌 SHA-256 摘要和权限。以下为配置格式示例，套餐 ID 应替换为本面板的基础、高级、旗舰普通套餐 ID，摘要替换成对应随机令牌的 64 位小写十六进制摘要。刷新配置缓存并重载常驻进程。

```dotenv
TICKET_BOT_OPERATORS='{"support-bot":{"sha256":"<操作令牌摘要>","scopes":["refund","plan_access"]}}'
TICKET_BOT_ACCESS_PLAN_IDS=11,12,13
```

`refund` 可读取订单及办理退款，`plan_access` 可查看与调整档位。两个权限可单独授予。全部新接口使用对应操作令牌作为 Bearer Token，沿用前文的路径前缀和响应格式。旧令牌仅用于原有工单操作。

| 方法与路径 | 请求与结果 |
| --- | --- |
| GET /{id}/orders | 按订单 ID 倒序返回完整订单号、产品、周期、状态、支付与余额抵扣金额。金额单位为分。用 limit 和响应的 next_before_id 作为下页 before_id 翻页。 |
| POST /{id}/refunds/preview | 提交 trade_no。返回 amount（实际可退金额，单位为分）、commission_deduction、balance_before、balance_after、related_orders、rule 和 effect。end_subscription 表示结束当前套餐，reclaim_package_remainder 表示回收该笔独立流量包的剩余流量。 |
| POST /{id}/refunds | 提交 quote、version、reason、expected_amount。expected_amount 原样使用预览的 amount。成功返回 refund_id、action_id、实际金额、前后余额和关联订单。 |
| GET /{id}/plan-access | 返回购买套餐 purchased_plan、本期生效套餐 effective_plan、节点实际使用的 effective_profile、原订阅截止时间、本期调整截止时间和 allowed_plans。流量包接管或订阅不可用时 effective_plan 为空。 |
| POST /{id}/plan-access/preview | 提交 target_plan_id。返回 before、after（含权限组、限速、设备数）、override_until 和费用 charge_amount。 |
| POST /{id}/plan-access/changes | 提交 quote、version 和 reason。成功返回 action_id、前后档位和截止时间。选回购买档位可提前恢复。 |

机器人根据已读工单生成操作时，预览请求一并提交工单详情的 version。若其间有新消息或备注，服务端返回 409 ticket_changed。两种预览均返回 version、quote 和 expires_at。先向操作方展示权益回收范围或调档前后差异，再用同一身份、工单和预览版本确认。确认时携带 Idempotency-Key，超时按原键、原请求重试。相同订单的关联退款只能入账一次。工单内容或业务状态改变、凭证过期均须重新预览。操作身份取自服务端凭据。系统把执行凭证独立保存，工单保留期结束后仍可按工单号、业务编号追查。

例如，客服服务端读取工单 123 的某一笔订单后，可这样办理退款。下列变量 base 包含路径前缀，op 是服务端配置的操作令牌，trade 是已核对的完整订单号。

```sh
preview=$(curl --fail-with-body -sS -H "Authorization: Bearer $op" \
  -H 'Content-Type: application/json' -d "$(jq -n --arg t "$trade" '{trade_no:$t}')" \
  "$base/123/refunds/preview")
printf '%s\n' "$preview" | jq '.data | {amount,related_orders,effect,balance_after,rule}'
# 核对后保存 key 和 body；网络超时后原样重发。
key=$(uuidgen)
body=$(printf '%s' "$preview" | jq -c '.data | {quote,version,expected_amount:.amount,reason:"按工单申请退至余额"}')
curl --fail-with-body -sS -H "Authorization: Bearer $op" -H "Idempotency-Key: $key" \
  -H 'Content-Type: application/json' -d "$body" "$base/123/refunds"
```

本期调档适用于有到期日且仍在有效期内的指定普通订阅。applies_to_current_traffic 为 false 时，调整会在套餐重新有可用流量后生效。购买套餐、计费、流量额度和已用量继续按原订阅计算。续费后本次调整仍在原截止时间结束。再次调档沿用当前调整的截止时间。套餐流量耗尽时独立流量包按自己的权限使用，套餐流量重置后在有效期内恢复调整档位。正式换购或套餐退款会取消调整。到期恢复由每分钟调度处理，节点与客户端在各自的常规刷新周期取得变化。

常见错误包括 403（权限未配置或不匹配）、404（记录不存在或订单与工单用户不匹配）、409（工单关闭、预览或余额变化、重复退款、幂等键冲突）和 422（字段无效、目标档位不适用）。退款试算按后台规则拒绝时返回 400 和具体原因。
