# 仪表盘用量查询 Implementation Plan

**Goal:** 快捷入口“联系我们”替换为“用量查询”，公开已有 `/traffic` 查询页面，并适配现有 Aurora 界面。

**Architecture:** 复用现有路由、Vue 查询表格和 `/user/stat/getTrafficLog` 接口。路由展示模块将查询页面纳入现有用户界面主题，并添加标题；不改变统计、倍率计算和数据范围。

**Tech Stack:** 现有主题 JavaScript、Naive UI、Aurora CSS、Node 测试、Playwright 本地预览。

- [x] 替换仪表盘快捷按钮文案、图标与路由行为。
- [x] 为 `/traffic` 注册页面与用户外壳标记，增加“用量查询”标题和本月统计说明，沿用现有卡片与表格。
- [x] 同步 theme/public 镜像与 Blade 缓存版本；更新受影响的版本和路由测试。
- [x] 用本地内存 API 验证入口跳转、刷新、返回、数据与空状态、不同宽度的布局和字体；运行主题相关测试与差异检查。

## 本地验收

- 三套主题相关测试共 60 项通过，`git diff --check` 通过。
- 入口为“用量查询”，沿用原 `/traffic` 路由和查询 API；原“联系我们”快捷按钮已替换。
- 浏览器确认数据、空状态、入口导航、刷新和返回、标题幂等；2 倍率记录展示 1 GB 实际上行、3 GB 实际下行、8 GB 计费总计，保持原查询逻辑。
- 320、390、768、1280、1600 宽度分别直接刷新验收；常规字号 14px，手机卡片正常铺满内容宽度，表格可横向滚动。
- 截图位于 `output/playwright/usage-dashboard-entry.png` 和 `output/playwright/usage-query-*.png`。全部使用本地内存接口，未推送或部署。
