# 自动化节点地址复制 Implementation Plan

**Goal:** 每个节点文字后增加复制图标，复制所在域名与该节点配置端口，例如 `us.443ds443.com:1001`。

**Architecture:** 在现有 React 模块增加节点行组件，独立管理复制反馈；优先使用 Clipboard API，无法使用时以临时文本框复制。复用后台主题变量与现有列表，缺少端口时禁用按钮。

**Tech Stack:** React createElement、CSS、Playwright CLI、现有 Node/PHP 检查。

- [x] 修改 `public/assets/admin/assets/node-automation.js`：加入节点复制按钮、SVG、成功及失败反馈、缺少端口保护。
- [x] 修改 `public/assets/admin/assets/node-automation.css`：按钮布局、悬停和键盘焦点、深浅主题及长名称换行。
- [x] 更新 `docs/node-automation.md`，运行 `php scripts/version-admin-assets.php` 同步资源版本。
- [x] 使用 `tests/node-automation-preview.cjs` 与 Playwright 验证三个端口、跨域名、键盘复制、降级复制、失败反馈、深浅色和窄屏；仅操作本地内存数据。
- [x] 运行 `node --test tests/node-automation.test.js tests/node-automation-status.test.js tests/admin-asset-cache.test.js`、`php tests/admin-asset-cache.php`、`php scripts/version-admin-assets.php --check`、`git diff --check`，记录结果。

## 验证结果

- 13 项 Node 测试及 54 项 PHP 缓存检查通过，资源版本 `admin-280119e2e27372f39db5` 校验通过。
- 浏览器实际剪贴板验证三个不同端口、两个域名、用户示例地址、键盘 Enter、成功反馈重置、降级复制、失败提示、重试与焦点恢复。
- 320、390、768、1280、1600 宽度的深浅主题共 10 组检查无横向溢出，全部 9 个复制按钮尺寸和位置正常；缺少端口禁用，长名称换行正常。
- 截图：`output/playwright/node-copy-{390,1600}-{light,dark}.png`。仅修改本地，未推送或部署。
