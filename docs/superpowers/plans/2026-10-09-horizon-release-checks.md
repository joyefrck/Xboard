# Horizon 发布检查实施计划

**目标：** 恢复 2026-10-09 队列消费故障，并让大版本发布必须验证原有业务队列。

**方案：** 将新增节点自动化 supervisor 合并进原有 `local` 配置。使用安装的 Horizon `ProvisioningPlan::deploy()`，将命令队列替换成内存收集器，验证实际部署的 connection/queue 和超时租约；测试不启动 Laravel、不加载 `.env`、不连接生产。镜像构建在 Composer 安装后执行该检查。发布 skill 另提供只读运行态快照，核对当前环境、真实进程、队列趋势及失败任务。

**技术：** PHP、Laravel Horizon、Node test runner、Docker。

- [x] 生产备份配置、容器进程和脱敏队列快照；修正配置并仅重启 Horizon，保留任务及现有 Compose 网络和 init。
- [x] 创建 `scripts/check-horizon-provisioning.php` 和 `tests/horizon-provisioning.test.js`；运行 `node --test tests/horizon-provisioning.test.js`，确认当前错误配置因缺失业务队列失败，并验证历史 wildcard 故障及 worker/lease 故障。
- [x] 修改 `config/horizon.php`，合并自动化队列；在 Dockerfile Composer 安装后运行 `php scripts/check-horizon-provisioning.php --environment=local`，不通过则阻止镜像构建。
- [x] 运行 `node --test tests/horizon-provisioning.test.js tests/horizon-fixed-workers.test.js tests/node-automation.test.js` 及 PHP 语法检查；确认本地生产配置 SHA-256 一致。
- [x] 创建 `~/.codex/skills/xboard-release-checks/SKILL.md` 和只读快照脚本，验证 skill 与脚本，要求大版本发布前后必检。
- [x] 持续采样队列、邮件结果、Telegram 任务状态、失败任务、僵尸进程和公开接口；通过新 SSH 会话重新验收。

本次继续当前 `master`，不创建分支；生产热修复已获授权。本地代码不自动提交或推送，交付时明确版本与热修复状态。

## 验证记录

- 备份：`/root/xboard-incident-20261009-013851-horizon`，含配置前后副本、脱敏队列及通知任务快照。
- 生产仅修改 `config/horizon.php` 并于 01:38:54 重启 Horizon；Web 启动时间未变，Compose init 和网络配置保持现状。
- 01:39:33：8 个历史支付通知 job 均 completed，订单 ready=0；邮件 54、流量 2899、统计 4931、在线 1506。
- 01:40:20：邮件 10、流量 2574、统计 2913、在线 2。
- 01:42:05：邮件/统计/在线为 0，流量 1806，邮件已发送 85 条且错误 0。
- 01:46:44：流量历史积压清空，其余短暂新统计/在线任务年龄 0–1 秒。
- 01:49:04 新 SSH 会话：11 个队列全部有真实活跃 worker，ready/reserved/delayed 均为 0，新增失败任务 0；5 个容器均无僵尸进程、cgroup max 事件 0，公开首页/接口 200，Redis PONG。
- 本地与生产配置 SHA-256：`48a56e9fda93269871d015c8d6ff659fcd2fd4e614e3cd639169e1f02e1d7d34`。
- 相关测试 15/15 通过，PHP 语法和 skill 校验通过；离线门禁也使用生产安装的 PHP/Horizon 运行通过。未构建或发布新镜像。
- 观察窗口无新注册或付款；未伪造账户或付款。历史 Telegram job completed 与邮件无错误证明服务端发送结果，未宣称用户设备或收件箱已确认。
