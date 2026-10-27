# 自动化配置状态配色与多选筛选 Implementation Plan

> **For agentic workers:** 按下列任务在当前会话顺序实施；用户已批准设计与本地修改。

**Goal:** 为自动化列表与 IP 管理弹窗提供统一语义配色，并在列表上方加入多选状态筛选。

**Architecture:** 在现有模块导出备用计数、状态展示与组合筛选函数，列表及弹窗复用同一展示结果。React 保存独立的搜索及状态选择，数量只按搜索匹配集统计；CSS 用共享色彩变量表达标签、筛选浅色态及深色选中态。

**Tech Stack:** 现有 React createElement 后台模块、CSS、Node test runner、内存 API 预览、Playwright CLI。

---

### Task 1: 统一展示状态与筛选逻辑

**Files:** `public/assets/admin/assets/node-automation.js`、`tests/node-automation-status.test.js`。

- [x] 增加 `standbyCount(group)`，沿用 `b.enabled && b.ip !== group.current_ip` 口径。
- [x] 增加 `groupStatus(group)`，按已确认设计映射 healthy/healthy 无备用、错误、暂停、未配置、处理中和未知状态。
- [x] 增加 `filterGroups(groups, search, statuses)`，搜索不区分大小写并 trim，状态之间 OR、搜索与状态 AND；保留输入顺序。
- [x] 列表与 IP 管理弹窗调用同一状态标签函数，正常无备用显示明确提示。
- [x] 使用实际数据测试已禁用备用、当前 IP、缺失 backups、全部组状态、未知状态、搜索及多选并集。运行 `node --test tests/node-automation-status.test.js`，应全部通过。

### Task 2: 多选按钮与视觉

**Files:** `public/assets/admin/assets/node-automation.js`、`public/assets/admin/assets/node-automation.css`。

- [x] 在标题/搜索下方增加 `role="group"` 和 `aria-label="按状态筛选"`，原生按钮使用 `aria-pressed`，数量加载前显示 `—`。
- [x] 增加 `statuses` state，点击切换单项；“全部”清空；空结果“清除筛选”同时清空搜索及状态。
- [x] 状态数量基于搜索结果，不受状态选择影响；未知状态有数据或仍被选中时保留“其他”按钮。
- [x] 标签与筛选按钮共享六套配色，深底白字表达选中，浅底深字表达未选中；实现 dark 主题、focus-visible、换行及 reduced-motion。

### Task 3: 预览与验收

**Files:** `tests/node-automation-preview.cjs`、`docs/node-automation.md`、由 `scripts/version-admin-assets.php` 管理的资源版本引用。

- [x] 预览补齐 healthy 无备用、no_backup、propagating，保留共享域名和原编辑流程。
- [x] 使用 Playwright CLI 验证多选/反选/全部、搜索组合、空结果重置、键盘操作、轮询更新后分类变化、列表与弹窗颜色一致。
- [x] 验证 320、390、768、1280、1600 宽度与深浅主题，检查横向溢出并保存截图至 `output/playwright/`。
- [x] 更新功能文档，运行 `php scripts/version-admin-assets.php`。
- [x] 运行 `node --test tests/node-automation.test.js tests/node-automation-status.test.js tests/admin-asset-cache.test.js`、`php tests/admin-asset-cache.php`、`php scripts/version-admin-assets.php --check`、`git diff --check`，检查退出码和完整结果。

## 本地验收结果

- Node 自动化、状态筛选、资源缓存与 Horizon 门禁共 21 项测试通过；PHP 资源缓存 54 项检查通过。
- 实际 Horizon local 环境离线 provisioning 覆盖全部 11 个 connection/queue，无缺失或无效 lease。
- 浏览器验证多选并集、反选、全部重置、搜索组合、空结果、弹窗一致性、键盘操作、页签保留、轮询重新分类及数量刷新。
- 320、390、768、1280、1600 宽度在浅/深色下共 10 组布局检查通过。初次读取布局发生在响应式重排前，改为等待布局稳定后验证，未修改页面布局。
- 预览仅使用本地内存数据。截图保存在 output/playwright/status-filters-*.png。
- 用户已追加授权直接发布生产；生产发布和持续队列采样另保存验收记录。
