# 后台静态资源缓存

`config/octane.php` 使用 Octane 2.11 的 `static_file_headers`，仅为后台 JS、CSS、语言包和字体设置 `public, max-age=31536000, immutable`。后台 HTML 返回 `private, no-store`，API、上传文件和用户主题不受影响。

## 更新资源

在项目根目录运行：

```sh
php scripts/version-admin-assets.php
php scripts/version-admin-assets.php --check
```

脚本按后台资源内容生成版本，更新 Blade、CSS 字体引用和编译后 JS 的循环导入。重复执行不改变版本。将所有输出的文件一起提交、发布；CI 在版本未同步时阻止镜像构建。

不要只更新 `index.js` 或 `vendor.js` 的 URL，它们相互导入，版本不一致可能重复初始化 React。所有可变后台资源引用必须带版本。worker 文件名已带构建指纹，修改 worker 时还应更新文件名及 vendor 中对应引用。

## 发布验证

静态缓存配置由 Octane 主进程读取，发布后需要重启 Web 容器，单独 reload worker 不够。不需要迁移数据库或重启 Horizon、Redis。若启用了 Laravel 配置缓存，按部署流程先重建配置缓存。

检查实际线上后台资源 URL 返回一年缓存头，后台 HTML 返回 `no-store`。若 OpenResty 反代 Octane，确认没有隐藏或覆盖 `Cache-Control`；若直接提供静态文件，须在现有后台静态资源 location 中同步设置：

```nginx
# 仅适用于后台 JS/CSS/字体/语言包的静态 location。
# 保留原 root/alias/try_files，不应用于 HTML、API 或上传文件。
add_header Cache-Control "public, max-age=31536000, immutable";
```

已有 `expires` 或 `Cache-Control` 时合并成一套策略，避免冲突；不要给 404/500 加长期缓存。修改 OpenResty 前备份配置，测试通过后 reload，并验证外网实际响应。

浏览器 Network 面板保持 Disable cache 未勾选。访问一次后普通刷新，JS、CSS、语言包应显示 disk cache / memory cache，HTML 和 API 仍正常联网。首次访问和版本升级仍需下载；modulepreload 提前发现大依赖，不能代替带宽优化。
