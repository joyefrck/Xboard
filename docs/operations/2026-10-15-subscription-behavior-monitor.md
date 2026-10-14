# 订阅行为检测生产运行记录

2026-10-15，Asia/Shanghai。已获用户批准实施、生产启用及发送本人专属测试消息。

## 行为与通知

服务器每个偶数整点运行，独立于 Codex 桌面。timer 为 enabled、active、Persistent=yes；上线验收时下次触发 2026-10-15 02:00。

- R1：最近两小时成功非空 GET 至少 60 次、跨度至少 30 分钟、脚本 UA 比例至少 80%、间隔中位数不超过 60 秒、短于 60 秒比例至少 70%，80 分。
- R2：最近 24 小时至少三个相隔 30 分钟的连发簇、滚动 60 秒至少十次且中位间隔不超过三秒，最新簇在新窗口内；七个日历日已记录计费流量不超过 128 MiB，70 分。
- R2 使用流量前验证全站日统计最近 30 分钟有更新，以及 stat ready/reserved 最老任务未超过 30 分钟。健康不足时流量为未知，停止低流量加分，R1 照常。
- 排除 ID 3、封禁、管理员、员工；HEAD、失败响应、空订阅、单纯高频普通客户端、共享出口本身不触发告警。
- 仅通过工单机器人发送给 ID 3 的绑定 Telegram。多个账号合并一次发送，包含 UID、邮箱、当前套餐/有效流量包及每条规则原始证据。同 UID/规则 24 小时去重；超长名单一次 sendDocument 内存附件。
- 无异常静默；没有封号、重置订阅或修改用户权限路径。超时和未知回执保存 uncertain，不盲目重发；明确拒绝保存 pending，后续运行恢复。

## 代码与部署边界

当前 master，只提交本功能文件。生产 Git 基础为 `3231603a968a8d66c765749ce0e6b12b11210b02`，其已有热修复保留。本次按文件校验部署 CLI 功能，未推送 GitHub、未触发新镜像构建、未重建 Web/Horizon。

生产源目录：`/opt/1panel/apps/openresty/openresty/www/sites/xboard/index`。日志：`/opt/1panel/www/sites` 下五个已确认站点的 access.log 及轮转文件。生产实际 APP_ENV/Horizon environment/首个匹配 provisioning pattern 均为 local，无配置缓存。

Web/Horizon 实际镜像均为 `sha256:a2d44ff00856fc3371bdc2273e556004d27d167f5b7ef320b8d72fccd154bf86`。init=true，重启计数 0，原有 PID 保留。最终十个运行文件（两个命令、四个服务、配置、Python 和两个 unit）逐个 SHA-256 与本地一致。

备份及脱敏证据：`/root/xboard-behavior-20261015`。初始本功能文件全部不存在，file-manifest.json 记录该状态；迭代前文件保存在 before-health 和 before-timestamp-SnapshotService.php。final-runtime-manifest.json 记录最终运行文件哈希。未备份或输出邮箱、消息正文、订阅 token 或机器人凭据。

## 验证证据

- 本地 Python 25 项、PHP 62 项隔离检查、既有 Telegram 19 项、Horizon/节点自动化 15 项全部通过。新 PHP lint、Python 编译、真实 provisioning 离线检查及 Git whitespace 检查通过。
- 生产 dry-run 11.14 秒：最近 24 小时 518106 条日志、新窗口 35943 条、成功订阅 GET 15834 次，零候选。16 个文件仅读取四个，跳过十二个旧归档。
- 10/14 19:00 的显式 dry-run 历史回放：6571 命中 R1，两小时 154 次、间隔中位数 35 秒。已封禁用户仅回放纳入，正式执行排除。回放 20.79 秒。
- 带虚构账号且明确测试标签的合并电报，单次 API 返回 sent=1；不代表用户设备已读。超长附件与失败状态用 MockHTTP 隔离测试验证，未向本人额外发送真实附件测试。
- 首次 systemd 运行 00:42:25–00:42:52，退出 0、零候选、零通知。二次验收发现 RedisStore 数字时间返回字符串，任务安全退出；已在 PHP 白名单输出归一化并新增先失败后通过的回归测试。最终版本 00:54:50 及新 SSH 会话 00:58:16 再次成功，last_success 已持久化并以整数输出。
- 最终三次队列采样为 00:55:39、00:56:26、00:57:12，间隔约 46 秒。全部十一队列有活跃消费者，零新增失败、零僵尸。stat ready/reserved 11/1 降至 0/0；online_sync ready 3 降至 0，其他队列三次为零，无持续积压。00:58:17 独立新会话复查：全部十一队列消费覆盖通过，ready/reserved/delayed 均零，新增失败零。
- Redis PONG。Web cgroup pids.current/max 为 18/8831，Horizon 为 26/8831，僵尸 0；五个业务容器均运行，Web/Horizon /www 挂载当前已确认源目录。公网 www.elephant111.com 的首页、/app、公开配置接口、静态 CSS 均为 200。
- 自然业务只读证据：最近一小时四封邮件日志、零错误；最近一天 85 个已支付订单均 status=3；用户/节点统计更新间隔 0–1 秒；最近完成任务覆盖流量、用户/节点统计、在线数据、节点自动化。上线后另有一笔自然付款 status=3、一封邮件日志且无错误。没有伪造注册、支付、工单或群资格操作。

自然业务证据不等于本次上线后每个业务流程已逐个端到端验证：自然付款只读状态为完成，未逐订单核对所有权限与管理员通知；本次没有群资格或真实工单交互样本；邮件 SMTP 日志也不证明收件箱收到。通知功能已以指定本人测试和 API 回执验证。

## 运维

```sh
systemctl status subscription-behavior-monitor.timer
systemctl show subscription-behavior-monitor.service -p Result -p ExecMainStatus
journalctl -u subscription-behavior-monitor.service --since today --no-pager
```

阈值与接收人集中在 config/subscription_behavior.php。变更后由下一轮 CLI 读取，当前没有配置缓存；未来引入 config cache 时需重新确认配置装载。日志丢失/只有旧窗口、数据库或机器人配置故障返回非零，不推进检查时间。技术失败写入 journal；不使用成功“无异常”消息掩盖失败。

停用可执行 `systemctl disable --now subscription-behavior-monitor.timer`。回滚先停 timer，按初始 manifest 将新增文件移入备份目录，恢复本次迭代涉及的原文件（如有），再 daemon-reload；不要删除其他 Redis、数据库或已有热修复。邮箱只在发送阶段读取，报告和日志仅存脱敏指标。

规则报告自动化异常，无法单凭订阅行为证明举报身份；由本人复核处理。
