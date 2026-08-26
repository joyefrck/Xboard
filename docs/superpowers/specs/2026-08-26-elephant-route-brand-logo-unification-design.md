# 大象网络前台 Logo 统一设计

## 目标

以最新版大象网络客户端 Logo 为唯一品牌图源，统一官网、认证页面、用户中心及所有前台浏览器标签中的 Logo，消除当前新版 SVG 与旧 JPEG 混用的问题。

## 现状

- 最新客户端 Logo 位于 `clients/elephant-route-deprecated/assets/images/logo.svg`，是本次品牌图形的权威来源。
- 用户中心侧栏已经通过 `theme/ElephantRoute/assets/client-logo.svg` 使用该新版图形。
- 登录、注册和找回密码页面仍由 `elephant-route-auth.js` 优先加载 `/login_logo.jpeg`、`/home_logo.jpeg` 等旧 JPEG。
- 官网首页仍引用 `/landing/assets/elephant-route-logo.jpg`。
- 官网首页和 `/app` 共用的前台应用壳都没有显式声明完整的 favicon 资源。

## 范围

### 包含

- 官网首页 `/` 中可见的品牌 Logo。
- `/app` 下登录、注册、找回密码页面中的品牌 Logo。
- `/app` 下用户中心及其他前台路由的浏览器标签 Logo。
- 官网首页与 `/app` 应用壳的 favicon、PNG fallback 和 Apple Touch Icon。
- `theme/ElephantRoute` 与 `public/theme/ElephantRoute` 中浏览器实际使用资源的同步。
- 官网 Open Graph 与结构化数据中的组织 Logo 地址。

### 不包含

- 管理后台页面及其品牌配置。
- 客户端内部 Logo 的重新设计。
- 页面布局、文案、交互或配色调整。
- 生产发布或服务器配置变更。

## 设计

### 唯一图源

`clients/elephant-route-deprecated/assets/images/logo.svg` 是唯一权威图源。浏览器使用的 SVG 必须与它字节一致，不从截图重绘，也不在 Logo 外增加白底、色块或装饰边框。

### 前台资源

在 ElephantRoute 主题中保留 `assets/client-logo.svg` 作为页面内品牌资源，并从同一 SVG 生成适合浏览器标签的 favicon 资源：

- `favicon.svg`
- `favicon-32x32.png`
- `favicon-16x16.png`
- `apple-touch-icon.png`
- `favicon.ico`

这些资源分别同步到 `theme/ElephantRoute/assets/` 和 `public/theme/ElephantRoute/assets/`，同名文件必须字节一致。PNG 与 ICO 使用透明背景，保留深蓝外框和绿色中线，不裁切图形。

### 页面接入

- `theme/ElephantRoute/dashboard.blade.php` 在 `<head>` 中声明 SVG favicon、PNG fallback、ICO 和 Apple Touch Icon。该应用壳覆盖登录、注册、找回密码、用户中心及其他 `/app` 前台路由。
- `elephant-route-auth.js` 直接使用 `/theme/ElephantRoute/assets/client-logo.svg`，移除旧 JPEG 候选链；认证页仍保留现有可点击回首页行为与无障碍文本。
- `public/landing/index.html` 的页头品牌图片改用新版 SVG，并声明同一套 favicon。Open Graph 和 JSON-LD 的组织 Logo 也指向新版浏览器资源。
- 更新 Blade 中相关资源的缓存版本，确保部署后浏览器不会继续使用旧图。

## 兼容与失败处理

- SVG 是现代浏览器首选 favicon，32px、16px PNG 与 ICO 提供旧浏览器 fallback。
- Apple Touch Icon 使用从同一 SVG 渲染的高分辨率 PNG。
- 页面内 Logo 不再回退到旧品牌资源；若新版资源缺失，应由自动化测试和部署验收发现，而不是静默显示旧 Logo。

## 验证

- 校验客户端 SVG、主题 SVG 与公开主题 SVG 内容一致。
- 校验主题目录与公开主题目录中的 favicon 资源逐一一致。
- 校验认证脚本不再包含旧 JPEG Logo 路径，并只引用新版主题 SVG。
- 校验官网与应用壳包含完整 favicon 声明，官网可见 Logo、Open Graph 和 JSON-LD 均引用新版资源。
- 运行现有 ElephantRoute 前台聚焦测试与 `git diff --check`。
- 在真实浏览器中强制刷新，分别查看官网、登录、注册、找回密码和用户中心的页面内 Logo 与标签图标；同时检查桌面和移动端，不改变现有布局。

## 验收标准

- 所有用户可见页面的浏览器标签均显示最新版客户端 Logo。
- 官网、登录、注册、找回密码和用户中心不再显示旧 JPEG Logo。
- Logo 透明背景、比例和颜色与客户端 SVG 一致。
- 管理后台保持不变。
- 主题源目录与公开目录的品牌资源一致，聚焦测试通过。
