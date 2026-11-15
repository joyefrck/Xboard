# 认证页自动填充与简体中文 Implementation Plan

**Goal:** 登录邮箱使用浏览器账号自动填充；登录、注册和找回密码固定简体中文并移除语言菜单。

**Architecture:** 修改 ElephantRoute 现有共享认证组件，直接通过 Naive UI 的 autocomplete/inputProps 设置原生输入属性，避免渲染后的 DOM 补丁。组件初始化时同步应用语言和 i18n；只移除该组件内的语言菜单。

**Tech Stack:** Vue 3 / Naive UI，仓库维护的主题 bundle，Node.js，Playwright CLI。

- [x] 修改 `theme/ElephantRoute/assets/umi.js`：邮箱 `username`、登录密码 `current-password`、注册与找回密码及确认密码 `new-password`；设置稳定的原生 name；动态密码 autocomplete 加入 Vue 更新属性列表。
- [x] 认证组件初始化为 `zh-CN`，删除页脚语言下拉组件，保留注册/找回密码/返回登录链接。
- [x] 重新生成 gzip/Brotli，镜像到 `public/theme/ElephantRoute/assets/`，更新两份 `dashboard.blade.php` 的 bundle 缓存版本。
- [x] 本地隔离预览三个页面，包含英文偏好进入与页内导航，核对真实原生输入属性、简体中文、语言菜单移除及桌面/移动端布局。
- [x] 运行现有主题测试、压缩镜像一致性检查与 `git diff --check`。浏览器保存账号菜单需用户实际 Chrome 配置验证；本任务仅修改代码，不发布生产。

验证结果：116 项现有测试全部通过；7 次真实浏览器路由与视口检查通过，含英文偏好、三页导航往返、原生字段自动填充与表单关联。桌面及移动端截图已检查。新增原生 POST form 后同步调整认证页标题选择器，保留原布局。
