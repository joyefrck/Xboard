# Subscription Behavior Monitor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 每两小时检测异常订阅行为，向指定用户 ID 3 的工单 Telegram 合并发送一条可解释的告警，不自动封号。

**Architecture:** 主机 Python 任务读取已有访问日志；容器内 Artisan 命令只输出 token 哈希映射与脱敏流量统计，发送阶段才读取邮箱与当前套餐。模型规则在 Python 纯函数中实现；通知服务通过 Redis 状态、互斥锁及独立工单机器人 HTTP 发送维护去重和失败恢复。

**Tech Stack:** Python 3.11 标准库、Laravel 12/PHP 8.2、现有 Redis、Symfony NativeHttpClient/Mime、systemd。

---

## 接口契约

主机调用 `docker exec index-web-1 php artisan behavior:snapshot --mode=identities --at=<epoch>`，接收 JSON：`version=1`、`config`、`subscribe_path`、`users`（hash => `{id, excluded}`）、`known_node_ips`、`last_success_at`。config 包含 `owner_user_id=3`、`dedupe_seconds=86400`、`threshold=70` 以及设计 R1/R2 阈值。

流量模式为 `behavior:snapshot --mode=traffic --ids=1,2 --at=<epoch>`，接收 `{traffic: {user_id: {bytes7d, known, last_updated}}}`。只有存在日统计记录且数据库读取成功时 known=true。不输出邮箱、token、UUID、订阅链接或完整 UA。

主机将 `{version:1,run_id,window_start,window_end,candidates,diagnostics}` 通过 stdin 交给 `behavior:notify`。candidate 为 `{user_id,score,rules:[R1/R2],metrics,reasons}`，metrics 仅白名单数值、已识别 UA 家族。输出仅 `{status,matched,sent,suppressed,delivery,run_id}` 等脱敏汇总。命令返回非零表示技术失败；不确定送达单独标记。

通知命令接受 `--dry-run`（不发送、不写去重状态）及 `--test`（向本人发送带明确测试标签的一条合并测试消息）；测试内容使用虚构账号示例，不查询真实异常邮箱。

## Task 1: 规则和日志采集

**Files:** Create `scripts/subscription-behavior-monitor.py`, `tests/test_subscription_behavior_monitor.py`.

- [ ] 写纯函数测试，使用真实形式的 nginx combined log，区分 GET/HEAD/301/403/空响应及自定义订阅路径。
- [ ] 先运行确认新模块缺失而失败：`python3 -m unittest discover -s tests -p test_subscription_behavior_monitor.py -v`。
- [ ] 实现 parse、流式 gzip/轮转读取、时区窗口、规则评分及窗口累计；历史回放只允许 dry-run。
- [ ] 使用下述明确样本检验 R1，与每分钟普通客户端对照：

```python
events = [{"at": start + i * 35, "source": "192.0.2.1", "ua_family": "curl"} for i in range(120)]
# 两小时跨度内超过 60 次，至少半小时，CLI 占比 100%，中位间隔 35 秒：R1=80。
normal = [{"at": start + i * 60, "source": "192.0.2.1", "ua_family": "clash"} for i in range(120)]
# 次数虽多，普通客户端无脚本家族，且间隔不短于 60 秒：不命中 R1。
```

- [ ] R2 样本：三簇相隔一小时，每簇 12 次、间隔两秒，七日流量 32 MiB；末簇在新行为窗口内。验证 70 分。将 known 改为 false、或三簇改为两簇，均不告警。
- [ ] 实现 Docker CLI 编排、严格 JSON 边界、脱敏错误输出及 host flock。配置/数据库/日志失败时退出非零，不打印 subprocess 敏感输出。
- [ ] 核对生产历史 replay：已封禁账号只在显式 dry-run replay 开关下作为模式样本，正常执行应排除。

## Task 2: 后端命令、发送和去重

**Files:** Create `config/subscription_behavior.php`, `app/Console/Commands/SubscriptionBehaviorSnapshot.php`, `app/Console/Commands/NotifySubscriptionBehavior.php`, `app/Services/SubscriptionBehaviorAlertService.php`, `tests/subscription-behavior-alerts.php`.

- [ ] 使用隔离的 SQLite 内存数据库、ArrayStore 和 MockHttpClient 写行为测试，不加载生产 .env，不连接 Telegram。
- [ ] 先运行确认服务缺失而失败：`php tests/subscription-behavior-alerts.php`。
- [ ] snapshot 身份模式 select 字段白名单，hash token 后删除原值；排除本人、banned、is_admin、is_staff。流量模式查询候选 UID 的日统计，七个日历日跨度按 Asia/Shanghai 的日期边界计算。
- [ ] 发送命令检查 version、时间窗口、UID、规则、分数、白名单指标、输入大小；拒绝非法或可注入内容。重新查询用户状态并过滤已封禁/管理员。
- [ ] 当前套餐优先可用周期套餐，否则有效 FIFO 流量包并标注原套餐。只向配置的 ID 3 telegram_id 发送，不广播，不创建工单。
- [ ] Redis 原子锁与 pending/inflight/sent/uncertain 状态：输入只保存 UID 与白名单指标；sendMessage/sendDocument 成功后再标记 sent；明确拒绝保留 pending；不确定送达不盲目重发；重复 run_id 与同 UID/rule 二十四小时内抑制重复。
- [ ] 常规消息纯文本，超长消息用 Symfony Mime 内存文本附件调用一次 sendDocument；不得将真实邮箱或机器人凭据写到磁盘或日志。
- [ ] 回归测试以下真实业务要求：多个候选一次发送、字段完整、已封禁用户发送前排除、去重、新规则提醒、空名单静默、单附件覆盖全部账号、Telegram 明确拒绝重试/超时 uncertain、未知流量不能变成零。
- [ ] 核验 lint 与现有通知回归：`php -l` 对全部新 PHP；`node --test tests/telegram-http-runtime-safety.test.js tests/telegram-ticket-bot-isolation.test.js tests/telegram-ticket-inline-actions.test.js`。

## Task 3: 定时器与集成审核

**Files:** Create `deploy/subscription-behavior-monitor.service`, `deploy/subscription-behavior-monitor.timer`.

- [ ] service 从现有生产源目录启动 Python，指定 `/opt/1panel/www/sites` 日志根目录和 `index-web-1`；oneshot、超时、运行目录、互斥、只读日志、不暴露 Docker socket 给业务容器。
- [ ] timer 为 Asia/Shanghai 的偶数整点，Persistent=true；使用 `systemd-analyze calendar` 和 `systemd-analyze verify` 核验。
- [ ] 审查两个实现契约一致、日志不含敏感字段、不改既有业务文件、不具封号路径。
- [ ] 完成全部新测试及 Horizon 配置离线门禁，检查 `git diff --check`；只提交本功能文件和已获批设计，不混入已有热修复。

## Task 4: 生产备份、启用与验收

- [ ] 建立带日期的生产备份目录，记录原文件是否存在、SHA256、unit 状态；核对 app bind mount 与生产未提交热修复，不覆盖未授权文件。
- [ ] 发布前执行 `/Users/joyefrack/.codex/skills/xboard-release-checks/scripts/queue-snapshot.php` 的脱敏基线，核对 actual env=local 和所有业务消费者。
- [ ] 仅复制新文件，逐个核对 SHA256；CLI 进程新加载代码，不重启 Octane/Horizon，不改其队列配置。
- [ ] `behavior:snapshot` 身份/流量、host dry-run 与历史 replay 成功；告警邮件与机器人 token 不输出到工具。
- [ ] `behavior:notify --test` 发一条明确标注检测测试的合并消息，仅报告 Telegram API 成功，不假称用户设备已确认。
- [ ] 安装 unit，daemon-reload、enable timer，启动首轮 service；核对 Result、exit code、下次触发时间、持久化和没有异常时零消息。
- [ ] 至少三次队列快照，间隔 30–60 秒，数分钟后新连接复查；验证公网、Redis、进程/cgroup、新增失败任务与自然业务统计。
- [ ] 记录功能测试、上线文件、备份、timer、首轮结果及未覆盖的自然业务验收；不将热修复等同于新镜像发布或 GitHub 推送。
