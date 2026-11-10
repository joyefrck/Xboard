# Clash Verge 导入协议修复计划

**Goal:** 点击桌面 Clash Verge 导入入口时使用 `clash-verge://`，避免通用 `clash://` 被其他客户端接管。

**Architecture:** 保留主题对原始 Clash/Clash Meta 列表项的增强，在捕获阶段拦截原点击事件，读取现有订阅信息并生成专用协议链接。保留完整订阅 URL 和名称，名称参数位于 URL 参数之前，以兼容客户端提取 URL 到查询串末尾的行为。重复增强只绑定一次。

**Tech Stack:** 原生 JavaScript、Node test、JSDOM、Blade。

1. 在 `tests/elephant-route-import-clients.test.js` 模拟原始通用协议点击处理器；验证点击图标也只触发专用协议、参数往返、重复增强及非桌面行为。运行测试确认现有实现失败。
2. 修改 `theme/ElephantRoute/assets/elephant-route-dashboard.js`：添加专用链接和导入处理器，增强原始列表项并阻止原处理器；同步 `public/theme/ElephantRoute/assets/elephant-route-dashboard.js`。
3. 两份 `dashboard.blade.php` 只更新 dashboard JS 缓存版本；同步对应缓存断言。
4. 运行导入、Dashboard 2.0 和相关主题测试、JS 语法检查及 `git diff --check`。真实客户端订阅导入不使用用户凭证自动执行；本次不包含生产部署。
