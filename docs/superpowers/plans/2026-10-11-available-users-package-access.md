# 可用用户与流量包权限修复 Implementation Plan

> Inline execution in the current approved branch. Use the approved design and verification-before-completion; do not overwrite existing Horizon changes.

**Goal:** 修复可用资格漏统计，并自动消除周期套餐自然到期造成的流量包权限滞后。

**Architecture:** UserService 暴露无副作用的可用用户 Builder，仪表盘和原查询复用。TrafficPackageService 用 SQL 预筛选权限差异，再在锁定用户和余额的事务中复查并同步。Console 命令由每分钟调度触发。

**Tech Stack:** Laravel 12、Eloquent、MariaDB、SQLite 隔离回归、现有编译管理端资源。

- [x] 写 `tests/available-users-package-access.php`，运行 `/opt/homebrew/bin/php tests/available-users-package-access.php`，确认当前代码缺少共享查询/定时权限修复而失败。
- [x] 提取 `UserService::getAvailableUsersQuery()` 并复用到 StatController。更新三语可用用户文案，运行 `php scripts/version-admin-assets.php` 刷新内容版本。
- [x] 添加 `TrafficPackageService::getPackageAccessSyncCandidatesQuery()` 与 `syncPackageAccessForUser()`；添加 `traffic:sync-package-access {--dry-run} {--user-id=*}` 命令及每分钟调度。
- [x] 运行新增 SQLite 回归、现有流量包/管理员统计测试、权限切换运行测试、PHP lint、Horizon provisioning 门禁和管理端资源版本检查。
- [x] 按实际生产候选备份权限属性与相关余额，备份受影响源码；校验并应用精确补丁，刷新资源版本，重载实际 Web/Horizon。
- [x] 运行 dry-run、受控同步和重复 dry-run；核对旧/新计数、余额不变和候选清零；验证真实定时任务与全部 11 个业务队列、至少三次消费快照、公开服务和新会话复查。


## 2026-10-11 生产热修复验收

- 10:18:54 备份至生产主机 `/root/xboard-package-access-20261011/`：13 个源码/资源/配置文件；3437 个有流量包余额用户的必要权限与受保护字段，3621 条相关流量包账本。源码逐文件比对，JSON 在独立内存 SQLite 中恢复并逐行比较一致。
- 精确补丁 SHA-256：`c482646087c80a2dd8f7a0cd01ba5ca606537c21ea67e652a97fd41bb5a70bf3`。未覆盖现有 `config/horizon.php` 和 `docker-compose.yaml` 修改，更新后与备份逐字节一致。
- 运行时 dry-run 候选 136；受控事务同步 `checked=136 updated=136 failed=0`；受保护用户字段与完整相关流量包账本前后完全一致，待同步候选归零。候选分类为49个正值到期用户、87个仅流量包用户。
- 管理端内容版本 `admin-8c68117b16fafafc1d62`，Chrome刷新实测总用户31133、当前可用用户5617，旧口径同一时点2303。保留API字段 `activeUsers` 兼容已有客户端。
- 1159个周期套餐已过期但流量包仍可用用户全部进入所查节点214、320的用户下发结果；两个近期到期样本及新目录流量包样本均纳入。仅检查节点服务结果，未使用用户订阅凭据做终端连通性测试。
- Web/Horizon于10:20:14重建，仍使用原SHA镜像与`init:true`。实际APP_ENV/Horizon选择local，11个队列都有运行中的消费者（共13个worker），无错误租期。
- 发布后10:20:46、10:21:14、10:21:49三轮采样。另10:23:30复查：traffic ready 0/0/3/1、stat 0/0/6/2，reserved/delayed均0，其他队列无持续积压；新失败0，邮件日志自然新增3且无错误。流量上报、用户统计与流量包扣减时间持续更新。
- 原有OctaneServiceProvider每30秒检查schedule:run；新同步命令每分钟、onOneServer、withoutOverlapping(4)。10:22、10:23、10:24每分钟的真实调度mutex均存在、TTL依次相差60秒，执行重叠锁已释放，未添加重复外部cron。
- 公网首页、管理端入口、guest域名检查和两类新版本静态资源均200；Redis PONG。Web/Horizon重启数0、无僵尸、pids远低于8831上限。
- 本地运行验证：新增22项SQLite检查、54项管理端缓存检查、现有权限切换与管理员流量包赠送运行测试、25项Node回归、PHP lint、实际Horizon provisioning和资源版本检查通过。
- 本次为已授权最小生产热修复；未新建分支、未提交/推送GitHub、未运行新镜像CI。注册/支付/工单/Telegram群资格未人为产生测试任务，不将worker覆盖等同于这些功能的端到端验收。

- 10:26:18通过新SSH会话复查：所有11队列ready/reserved/delayed均0，实际worker覆盖不变；10:26:19总用户31134、旧口径2302、可用5617、正值过期且流量包可用1160，节点214/320均纳入1160人，候选0；Web/Horizon重启数0，Web无僵尸、pids17/8831，Redis与本地guest接口正常。
- 发布后自然业务（10:20:14起）实测：注册1；付款订单1，status=3且关联用户可用；4个send_email任务、4个send_telegram任务均completed，邮件日志4且错误0；order_handle completed3、traffic_fetch completed253、stat completed506、online_sync completed252、node_automation completed48。任务完成及SMTP日志不代表用户收件箱/Telegram设备侧确认。

- 最终按36秒间隔采集10:28:37、10:29:13、10:29:49快照，实际配置/worker覆盖均通过，新增失败0。traffic ready 0/4/2、stat 0/4/4、online ready 0/4/2，队首年龄至多1秒、第三轮0秒；其他队列ready0，第三轮node_automation reserved1为正在执行任务，未发现持续积压。三轮均无Horizon僵尸。
