# Telegram 绑定账号显示实现计划

**目标：** 首页 Telegram 卡片显示当前绑定账号，帮助用户识别用于收取提醒和入群的 Telegram 身份。

**方案：** 保留原卡片布局，新增 14px 账号行。优先显示 @用户名，无用户名显示昵称及 ID，查询失败显示现有 ID。独立异步查询，避免阻塞首页其他区域。只根据登录用户的绑定 ID 查询，成功缓存 10 分钟，失败缓存 1 分钟；不存储额外数据库字段。

**技术栈：** Laravel、TelegramService、原生 JavaScript、现有 ElephantRoute CSS。

- [x] 在 `app/Services/TelegramBindingService.php` 封装 `account(User $user)`；未绑定返回 null，核对 Telegram 返回的 ID 和 private 类型，只返回 id/username/name。
- [x] 在 `TelegramController::binding` 与 `UserRoute` 添加 GET `/api/v1/user/telegram/binding`，使用登录用户身份、请求限流和 no-store 响应。
- [x] 在 `elephant-route-dashboard-v2.js` 加入账号格式化、独立加载和 textContent 渲染；核对加载代次及绑定 ID，忽略过期响应。
- [x] 新增账号行样式，同步 theme/public 资源，更新 Blade 缓存版本。
- [x] 执行 Dashboard、群组入口及账号显示 Node 测试，执行独立 PHP 测试，PHP 语法与 git diff 检查。
- [x] Chrome 检查本地首页及桌面/390px 合成数据预览。真实本地账号未绑定 Telegram，账号展示使用从当前源码提取的模板和渲染函数验证。

发布时按提交并推送、对应提交构建成功、生产部署、线上验收的顺序执行。现有绑定不需要迁移或重新绑定；成功查询的账号信息最多缓存 10 分钟。
