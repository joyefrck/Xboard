# Telegram Worker FD 泄漏修复设计

## 现场结论

生产 `XboardCore` worker 同时消费 `order_handle` 与 `send_telegram`。2026-07-25
下午，该进程持有 501 个到 Telegram 的 `CLOSE_WAIT` socket 和 503 个 Swoole
`eventfd`，达到进程软上限 1024，随后无法加载 Guzzle 异常类并持续产生
`No file descriptors available`。

在相同生产容器内的隔离复现表明：

- Laravel HTTP/Guzzle cURL 每完成一次 HTTPS 请求增加 2 个 FD；
- 显式 `curl_close()` 不能阻止该 Swoole 6.1.2 cURL 路径泄漏；
- Symfony `NativeHttpClient` 连续请求时 FD 数保持不变。

## 目标

- Telegram 请求不再经过当前镜像中泄漏 FD 的 cURL 路径。
- Telegram 故障不能阻塞订单队列。
- 429 限流只产生一层有界重试，并遵守 Telegram 的 `retry_after`。
- 即使运行时再次出现缓慢资源增长，Telegram worker 也会在到达资源上限前回收。
- 不改变 Telegram 消息格式、按钮参数、Webhook 或订单业务逻辑。

## 方案

### HTTP 传输

`TelegramService` 显式使用 Symfony `NativeHttpClient`，通过 PHP stream 发送 GET
请求。每次请求完整读取响应体，再解析 Telegram JSON。构造函数接受可选
`HttpClientInterface`，为后续传输测试保留注入边界。

最多发送 3 次请求。连接错误和 5xx 使用 1 秒退避；429 使用响应中的
`parameters.retry_after`，并将单次等待限制为 5 秒，避免任务长时间占用 worker。
400 等永久客户端错误不重试。

### 队列隔离

移除共享的 `XboardCore`：

- `XboardOrder` 固定 1 个 worker，仅消费 `order_handle`；
- `XboardTelegram` 固定 1 个 worker，仅消费 `send_telegram`。

Telegram worker 设置 `maxJobs=25`、`maxTime=900`，作为运行时资源异常的第二道
保护。`SendTelegramJob` 只允许队列层执行 1 次，避免与服务层 3 次请求形成
乘法重试；任务超时设为 50 秒，与 10 秒请求超时和最多 5 秒退避的上界匹配。

## 测试与验收

配置契约测试验证 supervisor 隔离、固定进程数和 Telegram 回收上限。Telegram
传输契约测试验证显式使用 `NativeHttpClient`、不再使用 Laravel HTTP/cURL、
429 有界退避，以及任务不再二次重试。

部署后只重建 `horizon`，不重建 Web、Redis、MariaDB 或 Docker。验收要求：

- `XboardOrder` 与 `XboardTelegram` 分别存在且各 1 个 worker；
- Telegram worker 初始 FD 处于低位，请求后不再每次增加 2；
- 队列无持续积压，新增 `No file descriptors available` 停止；
- Horizon、内部 guest API 和公开站点保持健康。

## 回滚

部署前备份 `config/horizon.php`、`TelegramService.php` 和 `SendTelegramJob.php`。
如新传输无法请求 Telegram 或 Horizon 启动失败，恢复三个文件并只重建
`horizon`。
