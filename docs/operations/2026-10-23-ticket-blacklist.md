# 工单 AI 审核和黑名单

## 行为

- DeepSeek 审核新建工单标题、正文及用户回复；不上传或识别图片。
- 明确辱骂、人身攻击、恶意攻击、威胁：首次工单禁言七天，到期后再犯永久封禁账号。
- 禁言只限制创建和回复工单，不修改套餐或余额；到期按时间判断恢复，历史违规次数保留。
- 不提供用户申诉入口。管理员通过用户管理的账户状态下拉框选择正常、封禁、工单禁言；黑名单仅用于查询记录和证据。
- 手动工单禁言同样持续七天，保留登录会话，计入后续违规升级依据；重复保存不会延长截止时间。选择正常可解除禁言，但不清空违规历史。
- 管理端原有批量封号和编辑用户封号统一记录，并撤销登录令牌。
- AI 自动封禁的账号同样允许管理员在用户管理中将账户状态改为正常，解除账号封禁和工单禁言。旧登录会话不恢复，用户需重新登录；违规证据和历史次数保留，正常提交不会因旧记录立即被重新封禁，再次攻击仍按再犯规则处理。解封记录保存管理员操作人。
- 处罚、原始提交文字及必要上下文独立保存在 `v2_user_blacklist_event`，不随十四天工单清理删除。
- 审核通过后才保存正式工单/附件并触发原有通知。失败重试一次，仍失败返回 503，用户需稍后重新提交；不计违规。没有待审积压队列。
- 三个用户提交入口共享现有认证限流机制，限制为每分钟六次；已有未关闭工单时，新建请求不消耗 AI 调用。

## 服务端配置

```dotenv
TICKET_MODERATION_ENABLED=true
DEEPSEEK_API_KEY=<由部署环境注入>
DEEPSEEK_MODERATION_MODEL=deepseek-flash
```

密钥只在服务器环境中保存，不写入代码、前端配置、文档或日志。黑名单 API 仅报告是否已配置。

模型和 JSON 模式依据 DeepSeek 官方文档：
- https://api-docs.deepseek.com/zh-cn/api/create-chat-completion/
- https://api-docs.deepseek.com/zh-cn/guides/json_mode/

每次 HTTP 调用最长五秒、最多两次，适配现有客户端十二秒超时。返回结果必须有完整 JSON、合法分类，以及本次文字中的原文证据；历史内容不能作为本次处罚证据。客服历史上下文最多六条，每条最多两千字符。

## 上线顺序

生产发布按 Xboard 发布验收流程执行，保留生产已有热修复和源码挂载：

1. 先备份数据库，部署代码并执行 `php artisan migrate --force`，为用户表新增字段并创建记录表。
2. 注入服务端环境变量，刷新实际生效的配置，并重启需要重新载入配置的 Web/Octane 进程。
3. 验证实际选中的配置、所有业务 workers 和队列趋势；本功能不新增队列。
4. 在隔离数据库验证正常提交、首次禁言、管理员解封和再次攻击封禁；生产仅使用合成文本验证 DeepSeek 连通性，不创建真实业务记录。
5. 检查管理端新版本资源、黑名单菜单、搜索/分页/详情及管理员操作记录。

将 `TICKET_MODERATION_ENABLED=false` 可停止新的 AI 判定，已有禁言和封号仍保持。管理员可使用既有用户编辑功能解除账号封禁；不会自动抹去 AI 违规历史。

## 本地验证

```sh
php tests/ticket-moderation.php
node --test tests/user-blacklist.test.js tests/admin-ticket-*.test.js tests/ticket-*.test.js
php tests/ticket-retention.php
php tests/ticket-image-attachments.php
git diff --check
```

`tests/ticket-moderation.php` 使用内存 SQLite 和伪造 HTTP，绝不启动应用或加载 `.env`。真实接口可运行 `php scripts/verify-ticket-moderation.php`，密钥通过进程环境注入；脚本只使用四个合成样本，不访问数据库或实际用户工单。这些样本是连通性和行为冒烟验证，不能代表所有文本上的判定准确率。

浏览器验收使用真实管理端壳及合成数据，检查了 1440px 桌面和 390px 手机页面、证据详情及用户管理账户状态选择；没有访问生产账号。
