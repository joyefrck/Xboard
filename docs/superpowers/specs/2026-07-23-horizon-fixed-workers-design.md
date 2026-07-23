# Horizon 固定 Worker 设计

## 背景

生产服务器有 4 个 CPU 核心。现场采样确认 `index-horizon-1` 会在
`traffic_fetch`、`stat`、`online_sync` 三个高频队列之间反复调整 worker：
`balanceCooldown` 为 3 秒，短时采样中也约每 3 秒出现一批新 PHP PID，同时旧
PID 退出。

这些队列处理的是节点每 60 秒产生的短任务。任务没有明显积压，最近 24 小时也
没有失败任务风暴。因此当前主要开销不是任务执行本身，而是 Horizon 在突发队列
之间自动平衡时反复启动 Laravel worker。

## 目标

- 消除三个高频队列之间的自动伸缩抖动。
- 保持流量、统计、在线状态、订单、Telegram 和邮件任务彼此隔离。
- 不改变节点上报周期、业务逻辑、数据库结构或邮件限速行为。
- 部署后保持队列接近清空，并显著降低 Horizon 的持续 CPU 开销。

## 方案

将现有共享的 `Xboard` 自动平衡 Supervisor 拆分为以下固定 Supervisor：

| Supervisor | 队列 | 固定 worker 数 |
| --- | --- | ---: |
| `XboardTraffic` | `traffic_fetch` | 2 |
| `XboardStat` | `stat` | 1 |
| `XboardOnline` | `online_sync` | 1 |
| `XboardCore` | `order_handle`, `send_telegram` | 1 |

每个 Supervisor 关闭自动平衡，并通过相同的最小、最大进程数表达固定容量，避免
Horizon 根据瞬时队列长度迁移 worker。

现有 `XboardEmail` 保持独立、低并发及邮件限速逻辑不变。本次不扩大到邮件队列
调度优化。

## 容量依据

生产现场约每分钟完成：

- 32 个 `TrafficFetchJob`，平均执行约 0.15 秒；
- 32 个 `StatUserJob` 和 32 个 `StatServerJob`，合计执行时间远低于单 worker
  每分钟可用容量；
- 32 个 `UpdateAliveDataJob`，平均执行约 0.03 秒。

`traffic_fetch` 使用 2 个 worker，为偶发慢事务和节点同步上报保留余量。`stat`
和 `online_sync` 各 1 个 worker 已有明显容量余量。核心低频队列共用 1 个固定
worker。

## 测试

新增配置契约测试，验证：

- 高频队列分别属于预期 Supervisor；
- 每个 Supervisor 关闭自动平衡；
- 固定 worker 数与本设计一致；
- 高频队列不再出现在同一个自动平衡 Supervisor；
- `XboardEmail` 仍保持独立且最大并发为 2。

同时运行现有 Node 配置测试及 PHP 语法检查。

## 部署

仅同步本次配置和测试对应的提交到生产主机，然后：

1. 对线上当前配置做时间戳备份；
2. 重建 `horizon` 服务，不重建 `web`、Redis 或 MariaDB；
3. 验证 `horizon:status` 和 `horizon:supervisors`；
4. 连续采样 CPU、worker PID 和各队列长度。

## 验收与回滚

验收条件：

- 高频队列 worker PID 在观察窗口内保持稳定；
- 高频队列没有持续增长的积压；
- 最近失败任务不增加；
- Horizon CPU 不再出现由 worker 创建/退出导致的持续高占用。

如果任一高频队列持续积压，先将对应固定 worker 增加 1 个。如果部署产生异常，
恢复备份的 `config/horizon.php` 并重新创建 `horizon` 服务。
