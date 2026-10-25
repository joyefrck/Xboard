# 个人中心与账号注销 Implementation Plan

**Goal:** 实现已确认的紧凑个人中心和永久账号注销。

**Architecture:** 保持现有 Vue/Naive UI 主题，使用独立删除服务处理事务，控制器只处理验证和响应。主题和 public 资源始终镜像，更新 Blade 缓存版本。

**Tech Stack:** Laravel 12、SQLite 隔离测试、现有 Vue bundle、Node test、Playwright 浏览器。

- [x] 新增 `app/Services/AccountDeletionService.php`、`app/Http/Requests/User/UserDeleteAccount.php`，在 `UserController.php` 和 `UserRoute.php` 接入当前用户注销 API；验证密码、确认值及事务回滚。
- [x] 在 `TicketRetentionService.php` 提取可定向执行的提交后附件清理；在 `StatUserJob.php` 和 `TicketService.php` 阻止已注销用户的延迟写入。
- [x] 修改 `theme/ElephantRoute/assets/umi.js`：删除导航组件和相关功能，重写个人中心为有稳定语义类名的紧凑布局，加入注销确认弹窗。修改 `elephant-route-pages-v2.css` 并同步 public、Blade 缓存版本。
- [x] 用 `tests/account-deletion.php` 隔离 SQLite 覆盖错误密码不写、跨用户隔离、完整删除、token 撤销、失败回滚及延迟统计。执行 PHP 语法检查、Dashboard/页面相关 Node 回归、附件回归、`git diff --check`。
- [x] 用本地真实主题和假 API 浏览器预览，检查桌面/手机的布局字号、横向溢出、通知和密码保存、注销取消/失败/成功流程，确认不触及真实账号。

验证结果：109 项相关 Node 测试通过；账号注销 SQLite 检查通过（含所有订单状态）；工单清理 27 项检查及附件运行测试通过；PHP/JS 语法检查和 diff 检查通过。浏览器验证 1440px 双列、390px/320px 单列与无横向溢出、14px 正文、36px 按钮，以及注销取消、错误密码、正确密码后退出登录；只使用本地模拟账号。
