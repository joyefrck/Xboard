# 自动化配置列表与 IP 管理 Implementation Plan

> **For agentic workers:** 按下列任务在当前会话顺序实施；用户已批准设计与本地修改。

**Goal:** 将自动化备用池改为与节点管理同序的域名合并列表，常显操作并用弹窗管理 IP。

**Architecture:** 将 ServerService 现有自然排序方法公开供自动化 fetch 复用；将 GroupRow 分为摘要行和 IP 管理内容，并复用原生 dialog 容器。页面按域名从最新响应取得弹窗对象，保持轮询与表单状态。

**Tech Stack:** PHP / Laravel / SQLite 隔离测试、现有 React createElement 后台模块、CSS 主题变量、Playwright CLI 内存预览。

---

### Task 1: 复用节点排序

**Files:** `app/Services/ServerService.php`、`app/Http/Controllers/V2/Admin/Server/AutomationController.php`、`tests/node-automation-api.php`。

- [x] 为 fetch 增加乱序 code（包含 2/10、空值、相同展示 ID）及重复域名的隔离 API 测试，并确认旧实现失败。
- [x] 将已有 `sortByDisplayNodeId(Collection $servers): Collection` 改为 public；fetch 调用 `ServerService::sortByDisplayNodeId(Server::all([...]))`，先排序再分组。
- [x] 运行 `php tests/node-automation-api.php`，应退出 0，排序断言与原业务断言通过。

### Task 2: 列表与弹窗

**Files:** `public/assets/admin/assets/node-automation.js`、`public/assets/admin/assets/node-automation.css`、`docs/node-automation.md`。

- [x] 摘要列表显示域名/节点、当前 IP、状态/备用及四个常显按钮；原操作处理器和禁用条件保持。
- [x] 抽取原展开内容为 IP 管理组件，共用 Dialog 容器；保存响应和轮询使用最新 group；在弹窗显示错误与任务状态。
- [x] 用容器查询使中等宽度按钮右对齐换行，手机两列按钮；复用现有主题变量。
- [x] 文档将「展开域名」入口改为「IP 管理」。

### Task 3: 浏览器验收与资源版本

**Files:** `tests/node-automation-preview.cjs`、由 `scripts/version-admin-assets.php` 管理的后台资源引用。

- [x] 扩充内存预览为多状态、多域名数据并覆盖共享域名。
- [x] 运行 `node --test tests/node-automation.test.js tests/admin-asset-cache.test.js`，所有测试通过。
- [x] 运行预览和 Playwright CLI，检查四个操作、弹窗打开/关闭/焦点恢复、表单保存、日志及轮询刷新；桌面和手机无页面横向溢出。
- [x] 运行 `php scripts/version-admin-assets.php`，再运行 `php scripts/version-admin-assets.php --check` 和 `git diff --check`；均退出 0。

## 验证结果

- 排序回归在旧实现失败，复用排序后 API 的 65 项检查通过。
- `node --test tests/node-automation.test.js tests/admin-asset-cache.test.js`：10/10 通过。缓存测试原先硬编码 8 个资源，入口已有黑名单样式后实际为 9 个；更新了这一过期断言。
- `php tests/admin-asset-cache.php`：54 项检查通过。后台资源版本为 `admin-ded4f844ff5c9d327a4d`。
- 内存预览验证了保存失败提示及输入保留、保存后弹窗内数据刷新、检测与手动切换轮询、日志、搜索、启用策略下的编辑锁、Escape、遮罩和焦点恢复。共享弹窗补齐正反向 Tab 循环，48 次导航检查通过。
- 320、390、768、1024、1280、1440、1600 七种宽度检查通过，每行四个操作均可见；页面及行内容无横向溢出。深色错误文字在当前主题背景上的对比度为 6:1。
- 预览仅使用本地内存数据，未调用生产数据库或 Cloudflare；未发布生产环境。
