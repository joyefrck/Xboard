# 工单 Bot 记录刷新与附件实现计划

**Goal:** 记录页可刷新到最新消息，管理员／客服可以在 Telegram 打开工单图片。

**Architecture:** 扩展现有 TicketTelegramHandler 回调和分页键盘；TelegramService 复用现有受限传输实现 multipart 上传。附件仍使用私有存储。

**Tech Stack:** PHP、Laravel Eloquent、Symfony HttpClient/Mime、SQLite 隔离测试、Node 源码契约测试。

- [x] 扩展 `app/Services/Telegram/TicketTelegramHandler.php`：refresh 回调读取最新页，预加载 attachments，按消息生成图片按钮，image 回调重新鉴权及验证工单归属和文件存在。
- [x] 扩展 `app/Services/TelegramService.php`：sendImage 使用照片或原文件方法，multipart 每次重建；只对 editMessageText 的 message is not modified 视为成功；隐藏上传参数。
- [x] 新建 `tests/telegram-ticket-history-images.php`：隔离 SQLite、临时文件和模拟 HTTP，验证行为及错误边界，不载入 .env，不向真实 Bot 发消息；在 `.github/workflows/security-checks.yml` 增加对应回归步骤。
- [x] 运行 `php tests/telegram-ticket-history-images.php`、相关 `node --test`、`php -l` 和 `git diff --check`，检查改动范围并记录结果。

验证结果：独立 PHP 行为检查 43 项通过；工单按钮、Bot 隔离、HTTP 传输、附件、后台设置、群组和绑定相关 Node 回归 56 项通过；两个业务 PHP 文件与测试文件语法检查通过，`git diff --check` 通过。新增 CI 步骤尚未在 GitHub 执行；未执行真实 Telegram 收发或生产发布。
