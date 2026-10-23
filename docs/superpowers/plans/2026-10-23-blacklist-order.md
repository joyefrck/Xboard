# 黑名单封禁时间排序实施计划

> **For agentic workers:** 在当前会话逐项执行；用户已授权修改后直接生产发布。

**Goal:** 最新封禁或工单禁言显示在黑名单最前面。

**Architecture:** 在 Eloquent 用户查询中聚合处罚事件时间，排序后分页；沿用现有 API 和界面。

**Tech Stack:** PHP、Laravel Eloquent、SQLite 隔离测试、MariaDB 生产。

---

- [ ] 在 `tests/ticket-moderation.php` 新增 25 个有时间记录及一个历史封禁账号的隔离测试；`php tests/ticket-moderation.php` 应先失败。
- [ ] 在 `BlacklistController::fetch()` 使用 `withMax` 聚合 `account_ban` / `ticket_mute` 的 `created_at`，按聚合时间和用户 ID 降序排序；删除原状态和禁言截止时间排序。
- [ ] 运行 `php tests/ticket-moderation.php`、`node --test tests/user-blacklist.test.js tests/horizon-provisioning.test.js tests/horizon-fixed-workers.test.js`、实际 local 环境 provisioning 和资源版本门禁。
- [ ] 提交并推送当前 master；核对对应 CI、镜像、生产源文件和备份，部署最小改动。
- [ ] 在生产 MariaDB 验证实际排序、三次队列采样及新 SSH 会话、业务结果和公网可用性，保存脱敏审计。
