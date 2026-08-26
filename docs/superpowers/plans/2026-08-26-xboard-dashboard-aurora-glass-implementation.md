# Xboard Dashboard 2.0 Aurora Glass Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在不改变 Dashboard 2.0 布局和业务逻辑的前提下，实现已确认的淡雾灰毛玻璃视觉系统与实心色块 SVG 图标。

**Architecture:** 保持现有独立 V2 JavaScript/CSS 资源结构。JavaScript 新增无依赖的内联 SVG 图标映射与渲染助手，替换模板中的 Unicode 图标；CSS 只重构视觉变量、玻璃材质、色块与交互状态，现有网格和响应式断点保持不变。

**Tech Stack:** 原生 JavaScript、CSS、Laravel Blade、Node.js `node:test`、Chrome 真实运行态验收。

---

### Task 1: 固化视觉与图标回归契约

**Files:**
- Modify: `tests/elephant-route-dashboard-v2.test.js`

- [ ] **Step 1: 写入失败测试**

在现有 Dashboard V2 测试末尾增加：

```js
test('Dashboard 2.0 uses aurora glass tokens and solid SVG icon tiles without unicode placeholders', () => {
  const script = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.js');
  const styles = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.css');

  assert.match(script, /var SOLID_ICONS = \{/);
  assert.match(script, /function solidIcon\(name, className\)/);
  assert.match(script, /<svg[^>]+aria-hidden="true"/);
  assert.doesNotMatch(script, /icon: '⌂'|icon: '◇'|icon: '↗'|icon: '\?'/);
  assert.doesNotMatch(script, /er-v2-platform-icon">[●▦◆]/);
  assert.match(styles, /--er-v2-page: #edf1f6/);
  assert.match(styles, /backdrop-filter: blur\(24px\) saturate\(140%\)/);
  assert.match(styles, /\.er-v2-platform-icon\.is-android/);
  assert.match(styles, /\.er-v2-platform-icon\.is-windows/);
  assert.match(styles, /\.er-v2-platform-icon\.is-macos/);
  assert.match(styles, /\.er-v2-platform-icon\.is-ios/);
});
```

- [ ] **Step 2: 运行测试并确认失败**

Run: `node --test tests/elephant-route-dashboard-v2.test.js`

Expected: 新测试因缺少 `SOLID_ICONS`、`solidIcon()` 和雾灰玻璃变量而失败，既有测试继续通过。

### Task 2: 建立实心 SVG 图标系统

**Files:**
- Modify: `theme/ElephantRoute/assets/elephant-route-dashboard-v2.js`
- Modify: `public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.js`
- Test: `tests/elephant-route-dashboard-v2.test.js`

- [ ] **Step 1: 增加本地图标映射与助手**

在常量区定义菜单、平台、Telegram、节点、联系、教程与箭头的实心 SVG path：

```js
var SOLID_ICONS = {
  dashboard: '<path d="M12 2.7 2.5 10.5l1.9 2.3L6 11.5V21h5v-6h2v6h5v-9.5l1.6 1.3 1.9-2.3L12 2.7Z"/>',
  store: '<path d="M7 2h10l1 4h4v3l-2 13H4L2 9V6h4l1-4Zm-.3 17h10.6L19 9H5l1.7 10Z"/>',
  android: '<path d="M7.4 7 5.7 4.1l1-.6 1.8 3.1A10 10 0 0 1 12 6c1.2 0 2.4.2 3.5.6l1.8-3.1 1 .6L16.6 7A7 7 0 0 1 20 12H4a7 7 0 0 1 3.4-5ZM4 13h16v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-7Z"/>',
  windows: '<path d="M2 4.5 11 3v8H2V4.5Zm11-1.8L22 1v10h-9V2.7ZM2 13h9v8l-9-1.5V13Zm11 0h9v10l-9-1.7V13Z"/>'
};

function solidIcon(name, className) {
  return '<svg class="er-v2-solid-icon ' + (className || '') + '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + (SOLID_ICONS[name] || SOLID_ICONS.dashboard) + '</svg>';
}
```

- [ ] **Step 2: 替换侧栏与 Dashboard 模板图标**

将 `NAV_ITEMS` 的字符 `icon` 改为 `iconName`，在 `buildSidebar()` 中通过 `solidIcon(item.iconName)` 渲染。将下载平台、Telegram 标记、快捷入口和外链箭头全部改为 `solidIcon()`，并为平台色块追加 `is-android`、`is-windows`、`is-macos`、`is-ios` 类名。

- [ ] **Step 3: 同步 production 资源并验证语法**

将主题脚本逐字同步到 `public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.js`。

Run: `node --check theme/ElephantRoute/assets/elephant-route-dashboard-v2.js && node --check public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.js`

Expected: 两个命令均退出 `0`。

### Task 3: 实施雾灰极光毛玻璃视觉系统

**Files:**
- Modify: `theme/ElephantRoute/assets/elephant-route-dashboard-v2.css`
- Modify: `public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.css`
- Test: `tests/elephant-route-dashboard-v2.test.js`

- [ ] **Step 1: 重构颜色与材质变量**

在 `:root` 中增加并应用以下令牌：

```css
--er-v2-page: #edf1f6;
--er-v2-glass: linear-gradient(135deg, rgba(255, 255, 255, 0.76), rgba(250, 252, 255, 0.5));
--er-v2-glass-line: rgba(255, 255, 255, 0.72);
--er-v2-cyan: #00abbc;
--er-v2-blue: #4c66e7;
--er-v2-violet: #8759ec;
--er-v2-brand-gradient: linear-gradient(100deg, var(--er-v2-cyan), var(--er-v2-blue) 60%, var(--er-v2-violet));
```

Dashboard 工作区使用 `--er-v2-page`；一级卡片使用 `--er-v2-glass`、高光描边、冷色阴影和 `backdrop-filter: blur(24px) saturate(140%)`。内部指标与列表只使用轻量半透明白层。

- [ ] **Step 2: 增加实心色块和平台配色**

```css
.er-v2-sidebar-icon,
.er-v2-platform-icon,
.er-v2-shortcut-icon,
.er-v2-telegram-mark {
  display: grid;
  place-items: center;
  color: #fff;
  background: var(--er-v2-brand-gradient);
  box-shadow: 0 8px 18px rgba(55, 91, 217, 0.22), inset 0 1px rgba(255, 255, 255, 0.28);
}

.er-v2-platform-icon.is-android { background: linear-gradient(145deg, #36d989, #13a966); }
.er-v2-platform-icon.is-windows { background: linear-gradient(145deg, #28a7f5, #176dd9); }
.er-v2-platform-icon.is-macos { background: linear-gradient(145deg, #596477, #202839); }
.er-v2-platform-icon.is-ios { background: linear-gradient(145deg, #8158ff, #3769f2 56%, #1bcbd2); }
```

统一 SVG 尺寸、圆角、焦点、悬停和禁用状态。深色模式将页面底色与玻璃变量切换为深蓝黑，但不改变平台色。

- [ ] **Step 3: 保持既有布局并同步 CSS**

不修改 `.er-v2-top-grid`、`.er-v2-download-grid`、`.er-v2-bottom-grid` 的列定义和断点。将 CSS 逐字同步至 `public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.css`。

Run: `cmp -s theme/ElephantRoute/assets/elephant-route-dashboard-v2.css public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.css`

Expected: 退出 `0`。

### Task 4: 缓存、回归与真实浏览器验收

**Files:**
- Modify: `theme/ElephantRoute/dashboard.blade.php`
- Modify: `public/theme/ElephantRoute/dashboard.blade.php`
- Modify: `tests/elephant-route-dashboard-v2.test.js`

- [ ] **Step 1: 更新缓存版本并通过专项测试**

将两个 Blade 入口中的 V2 CSS/JS 后缀统一更新为 `er20260826dashboardV2Aurora1`，同步测试断言。

Run: `node --test tests/elephant-route-dashboard-v2.test.js`

Expected: 全部 Dashboard V2 专项测试通过。

- [ ] **Step 2: 运行相关回归与同步检查**

Run:

```bash
node --test tests/elephant-route-dashboard-v2.test.js tests/elephant-route-dashboard-actions.test.js tests/app-download-rate-limit.test.js tests/ticket-appeal-types.test.js tests/telegram-http-runtime-safety.test.js tests/telegram-ticket-bot-isolation.test.js tests/stacked-traffic-package.test.js
git diff --check
cmp -s theme/ElephantRoute/assets/elephant-route-dashboard-v2.js public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.js
cmp -s theme/ElephantRoute/assets/elephant-route-dashboard-v2.css public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.css
cmp -s theme/ElephantRoute/dashboard.blade.php public/theme/ElephantRoute/dashboard.blade.php
```

Expected: 所有测试通过，三个 `cmp` 与 `git diff --check` 均退出 `0`。

- [ ] **Step 3: Chrome 运行态验收**

刷新 `http://127.0.0.1:7001/app#/dashboard`，确认：页面根底色为淡雾灰；一级卡片毛玻璃清晰；菜单与下载使用实心 SVG 色块；布局尺寸与修改前一致；Dashboard 根节点无额外纵向溢出；离开并返回路由不会重复挂载；控制台无错误。
