# 节点自动化 Bot API 接入指南（Hermes）

AI 助理通过独立的 Bearer Token 管理「自动化配置 → 备用池」：查看域名状态、添加备用 IP、测试备用、开启和暂停自动化。这个令牌只能访问本组接口，不能登录后台。手动切换 DNS、删除备用、修改 Cloudflare 连接和公共检测账号，仍只能在后台操作。

## 启用与认证

1. 部署代码后运行 `php artisan migrate --force`，为切换记录表增加 `actor` 列。
2. 生成随机令牌，原文只保存在 Hermes 的密钥配置里，把 SHA-256 摘要写入 Xboard 的 `NODE_AUTOMATION_BOT_TOKEN_SHA256`：

```sh
export NODE_AUTOMATION_BOT_TOKEN="$(openssl rand -hex 32)"
printf '%s' "$NODE_AUTOMATION_BOT_TOKEN" | openssl dgst -sha256
```

3. 刷新配置缓存并重载 HTTP 进程。摘要为空时接口返回 403，令牌错误返回 401。替换摘要即可轮换令牌。

请求统一发送 `Authorization: Bearer <令牌>` 和 `Accept: application/json`。路径前缀为 `/api/v1/node-automation-bot`。每个 IP 每分钟最多 60 次请求。成功响应的内容放在 `data` 里，失败时读取 `message`。

## 接口

| 方法和路径 | 说明 |
| --- | --- |
| `GET /domains` | 全部可自动化的域名，顺序与节点管理一致。 |
| `GET /domains/{domain}` | 单个域名详情，包含每个备用 IP 最近一次的检测结果 `latest_check`。 |
| `POST /domains/{domain}/backups` | 添加备用。JSON：`ip`（必填，公网 IPv4），`name`、`priority`（0–999）可省略。返回 201。 |
| `POST /domains/{domain}/backups/{ip}/test` | 测试备用池中的这个 IP，连续两轮全部关联节点都通过才算成功。返回 202 和任务。 |
| `POST /domains/{domain}/enable` | 开启自动化。系统会先测当前 VPS，再测备用，至少一台备用通过后才把 TTL 改为 60 秒并开启。返回 202 和任务。 |
| `POST /domains/{domain}/pause` | 暂停自动化。 |
| `GET /operations/{id}` | 查询任务进度。 |

域名对象的主要字段：`configured`（后台是否已保存配置并匹配 DNS）、`enabled`、`state`、`reason`、`current_ip`、`current_check`、`pending_operation`、`nodes`、`backups`。`state` 的取值与后台列表一致，例如 `healthy`、`paused`、`suspect`、`propagating`、`no_backup`、`config_error`、`dns_conflict`、`unconfigured`。

备用对象的 `state` 取值：`unchecked`（未测试）、`ready`（测试通过）、`failed`（测试失败）、`active`（当前正在使用）、`needs_review`（切走后的原 VPS，要在后台人工重新加入）。

任务对象：`id`、`action`（`test`、`enable`）、`status`、`done`、`succeeded`、`message`、`backup_ip`、`nodes`（每个节点的测试结果）、`request_id`。`status` 为 `queued`、`running` 时还在执行；`done` 为 `true` 后读取 `succeeded` 和 `message` 判断结果。

## 推荐流程

1. 添加备用：`POST /domains/us.example.com/backups`，body 为 `{"ip": "203.0.113.10"}`。**自动化开启时也可以直接添加**，新备用在真正切换前一定会被连续测试两次，所以不需要先暂停。
2. 自动化已开启：调用 `.../backups/203.0.113.10/test`，每 10 秒查询一次 `GET /operations/{id}`，直到 `done` 为 `true`。
3. 自动化未开启：直接调用 `.../enable`，它本身就会测试当前 VPS 和备用；按同样方式轮询结果。
4. 汇报：把 `message` 和 `nodes` 里失败的节点告诉管理员。

`test` 和 `enable` 请求可以带 `Idempotency-Key: <UUID>` 请求头。超时后用同一个 UUID 重试，会返回原来那个任务，不会重复排队；同一个 UUID 换成别的操作会返回 422。

## 常见错误

| 状态码 | 含义 |
| --- | --- |
| 404 | 节点管理中没有这个域名、备用池中没有这个 IP，或任务不存在。 |
| 409 | 添加的 IP 已在备用池（`data` 返回已有备用）；或域名尚未在后台保存配置、匹配 DNS。 |
| 422 | 业务规则拒绝，例如不是公网 IPv4、与当前 IP 相同、备用已满 10 台、已有任务执行中、策略已开启。原因见 `message`。 |
| 429 | 请求过于频繁，稍后重试。 |

由 Hermes 发起的操作在后台「全部切换记录」中标注为「· Hermes」。
