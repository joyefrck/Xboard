-- =====================================================
-- Xboard 数据库索引优化脚本
-- 目标：优化查询性能，支持2000-5000用户规模
-- 执行前务必备份数据库！
-- =====================================================

-- 检查数据库版本和引擎
SELECT VERSION() AS mysql_version;
SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'v2_%';

-- =====================================================
-- 1. 用户表优化 (v2_user)
-- =====================================================

-- 检查现有索引
SHOW INDEX FROM v2_user;

-- 添加套餐ID索引（用于查询特定套餐的用户）
ALTER TABLE v2_user ADD INDEX idx_plan_id (plan_id);

-- 添加用户组索引（用于按组查询用户）
ALTER TABLE v2_user ADD INDEX idx_group_id (group_id);

-- 添加过期时间索引（用于查询即将过期的用户）
ALTER TABLE v2_user ADD INDEX idx_expired_at (expired_at);

-- 添加创建时间索引（用于按注册时间查询）
ALTER TABLE v2_user ADD INDEX idx_created_at (created_at);

-- 添加邀请用户ID索引（用于佣金系统查询）
ALTER TABLE v2_user ADD INDEX idx_invite_user_id (invite_user_id);

-- 组合索引：状态查询优化（未封禁且有效的用户）
ALTER TABLE v2_user ADD INDEX idx_banned_expired (banned, expired_at);

-- =====================================================
-- 2. 订单表优化 (v2_order)
-- =====================================================

SHOW INDEX FROM v2_order;

-- 用户订单查询优化（最常用的查询）
ALTER TABLE v2_order ADD INDEX idx_user_id_status (user_id, status);

-- 支付时间索引（用于统计和对账）
ALTER TABLE v2_order ADD INDEX idx_paid_at (paid_at);

-- 创建时间索引（用于订单列表分页）
ALTER TABLE v2_order ADD INDEX idx_created_at (created_at);

-- 套餐订单查询
ALTER TABLE v2_order ADD INDEX idx_plan_id (plan_id);

-- 佣金状态查询
ALTER TABLE v2_order ADD INDEX idx_commission_status (commission_status);

-- 组合索引：邀请用户的有效订单
ALTER TABLE v2_order ADD INDEX idx_invite_user_paid (invite_user_id, status, paid_at);

-- =====================================================
-- 3. 统计表优化 (v2_stat_user)
-- =====================================================

SHOW INDEX FROM v2_stat_user;

-- 时间范围查询优化
ALTER TABLE v2_stat_user ADD INDEX idx_record_at (record_at);

-- 记录类型和时间组合查询
ALTER TABLE v2_stat_user ADD INDEX idx_record_type_at (record_type, record_at);

-- 用户统计查询（如果索引不存在）
-- 注意：根据迁移文件，部分索引可能已存在，执行前检查
-- ALTER TABLE v2_stat_user ADD INDEX idx_user_id (user_id);

-- =====================================================
-- 4. 服务器统计表优化 (v2_stat_server)
-- =====================================================

SHOW INDEX FROM v2_stat_server;

-- 时间范围查询
ALTER TABLE v2_stat_server ADD INDEX idx_record_at_type (record_at, record_type);

-- =====================================================
-- 5. 工单表优化 (v2_ticket)
-- =====================================================

SHOW INDEX FROM v2_ticket;

-- 用户工单查询（最常用）
ALTER TABLE v2_ticket ADD INDEX idx_user_id_status (user_id, status);

-- 工单列表分页
ALTER TABLE v2_ticket ADD INDEX idx_created_at (created_at);

-- 待回复工单查询
ALTER TABLE v2_ticket ADD INDEX idx_reply_status (reply_status);

-- 组合索引：用户的待处理工单
ALTER TABLE v2_ticket ADD INDEX idx_user_status_reply (user_id, status, reply_status);

-- =====================================================
-- 6. 工单消息表优化 (v2_ticket_message)
-- =====================================================

SHOW INDEX FROM v2_ticket_message;

-- 工单消息查询
ALTER TABLE v2_ticket_message ADD INDEX idx_ticket_id (ticket_id);

-- 用户消息查询
ALTER TABLE v2_ticket_message ADD INDEX idx_user_id (user_id);

-- 时间排序
ALTER TABLE v2_ticket_message ADD INDEX idx_ticket_created (ticket_id, created_at);

-- =====================================================
-- 7. 佣金日志表优化 (v2_commission_log)
-- =====================================================

SHOW INDEX FROM v2_commission_log;

-- 邀请用户的佣金记录
ALTER TABLE v2_commission_log ADD INDEX idx_invite_user_id (invite_user_id);

-- 用户的消费佣金
ALTER TABLE v2_commission_log ADD INDEX idx_user_id (user_id);

-- 时间查询
ALTER TABLE v2_commission_log ADD INDEX idx_created_at (created_at);

-- =====================================================
-- 8. 邀请码表优化 (v2_invite_code)
-- =====================================================

SHOW INDEX FROM v2_invite_code;

-- 邀请码查询
ALTER TABLE v2_invite_code ADD INDEX idx_code (code);

-- 用户的邀请码
ALTER TABLE v2_invite_code ADD INDEX idx_user_id_status (user_id, status);

-- =====================================================
-- 9. 套餐表优化 (v2_plan)
-- =====================================================

SHOW INDEX FROM v2_plan;

-- 显示状态和排序
ALTER TABLE v2_plan ADD INDEX idx_show_sort (show, sort);

-- 用户组查询
ALTER TABLE v2_plan ADD INDEX idx_group_id (group_id);

-- =====================================================
-- 10. 通知表优化 (v2_notice)
-- =====================================================

SHOW INDEX FROM v2_notice;

-- 显示状态和创建时间
ALTER TABLE v2_notice ADD INDEX idx_show_created (show, created_at);

-- =====================================================
-- 验证索引创建结果
-- =====================================================

-- 查看所有表的索引情况
SELECT 
    TABLE_NAME,
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS COLUMNS,
    INDEX_TYPE,
    NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME LIKE 'v2_%'
GROUP BY TABLE_NAME, INDEX_NAME, INDEX_TYPE, NON_UNIQUE
ORDER BY TABLE_NAME, INDEX_NAME;

-- 统计每个表的索引数量
SELECT 
    TABLE_NAME,
    COUNT(DISTINCT INDEX_NAME) AS index_count
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME LIKE 'v2_%'
GROUP BY TABLE_NAME
ORDER BY index_count DESC;

-- =====================================================
-- 使用说明
-- =====================================================

/*
执行步骤：

1. 备份数据库（重要！）
   mysqldump -u root -p xboard > xboard_backup_$(date +%Y%m%d_%H%M%S).sql

2. 检查表大小和行数
   SELECT 
       TABLE_NAME,
       TABLE_ROWS,
       ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS 'Size(MB)'
   FROM information_schema.TABLES
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'v2_%'
   ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC;

3. 分批执行SQL（建议按表逐个执行，而非一次性全部执行）
   - 先执行小表的索引
   - 再执行大表的索引
   - 在低峰时段执行

4. 验证索引效果
   - 使用 EXPLAIN 分析查询计划
   - 检查慢查询日志改善情况
   - 监控数据库性能指标

5. 如需回滚，执行：
   DROP INDEX idx_索引名 ON 表名;

注意事项：
- 大表添加索引可能需要较长时间，期间表会被锁定
- 建议在维护窗口或低流量时段执行
- 索引会占用额外磁盘空间
- 过多索引会影响写入性能，需要权衡
*/
