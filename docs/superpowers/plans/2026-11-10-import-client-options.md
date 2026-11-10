# 订阅导入客户端调整

**目标：** 按用户截图的既有菜单样式加入 FlClash 与 Clash Mi，移除 Karing，保留首次登录菜单恢复修复。

**设计：** 二维码、复制、Clash Verge、FlClash、Clash Mi、Hiddify、其余现有客户端。FlClash 使用 `flclash://install-config?url=...`（官方注册的专用 scheme），Clash Mi 使用 `clashmi://install-config?url=...`。复用现有列表项，图标直接使用官方源文件，沿用统一尺寸、14px 字体、间距和 hover。FlClash 在非 iOS 平台显示；Clash Mi 保留 iOS 并扩展到桌面、Android。

**范围：** ElephantRoute dashboard JS/CSS、订阅弹窗样式选择器、图标、theme/public 镜像和 Blade 缓存版本。仅本地实现，不发布。

- [x] 更新客户端入口、顺序与图标；移除 Karing 注入。
- [x] 将菜单主题样式的 Karing 检测改为 Clash Mi，保证 iOS 上也沿用同一视觉系统。
- [x] 更新现有测试，验证跨平台菜单、导入 URI 编码与幂等性。
- [x] 运行相关测试、JS 语法及 diff 检查；浏览器验收桌面和窄屏截图。

官方来源固定于 FlClash `be84736605527034689c95bf27828ff27d912c5e`、Clash Mi `880020f77cb630bb87645e646fc042e31a5e9ab6`。
