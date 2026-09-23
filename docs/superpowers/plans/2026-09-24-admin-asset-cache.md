# 后台静态资源缓存与加载优化

**目标：** 后台再次打开时复用浏览器缓存；资源变更后自动生成新版本，避免旧包与新包混用。

**设计：** 使用当前 Octane 2.11 的 `static_file_headers`，仅为后台 JS、CSS 和字体设置一年缓存。后台 HTML 使用 `private, no-store`，API 不受影响。所有入口资源使用同一个内容指纹；`index.js` 与 `vendor.js` 存在循环导入，必须同步更新其 URL。增加 vendor 的 modulepreload，语言包按原有顺序 defer 加载，入口模块位于语言包之后。

仅缓存不能改善首次访问，所以同时提前发现大依赖。完整拆分编辑器、图表需要可重建的后台源码，本次保留已有打包结构。

## 实施与验证

- [x] 增加 `scripts/version-admin-assets.php`：按排序后的 JS/CSS/字体内容生成版本，归一化模块引用中的旧版本后计算 SHA-256，支持 `--check`。更新模块循环引用和 Blade 的所有后台资源 URL。
- [x] 修改 `config/octane.php` 的后台资源缓存范围；修改 `routes/web.php` 的后台 HTML 响应头。
- [x] 修改 `resources/views/admin.blade.php`：版本化 CSS、modulepreload vendor、按顺序 defer 语言包，然后启动入口模块。
- [x] 更新已有固定日期的模块版本测试，增加缓存范围、版本更新与加载顺序回归验证。运行 PHP lint、版本检查及相关 Node 测试。
- [x] 在本地 Docker 验证真实 HTTP 缓存头与 API/HTML 隔离；通过 Chrome 验证登录页、多语言切换和再次访问的资源缓存。

## 发布要求

每次后台资源变化后运行 `php scripts/version-admin-assets.php`，将脚本改动的资源与模板一起发布。检查模式发现版本漂移时必须失败。静态缓存配置由 Octane 主进程读取，生效需要重启 Web 服务，单独 reload worker 不足；保留 Horizon、Redis 和数据。

生产目前未获明确发布指令，本次先交付本地可验证变更。生产若由 OpenResty 直接服务文件，须另外在相同静态路径添加对应响应头，并检查线上实际响应，不能以本地结果代替线上验收。

## 验证结果

- 104 项相关 Node 测试通过；54 项 PHP 缓存检查通过。PHP 8.2 容器内检查、语法检查和版本一致性检查通过。
- 本地 Web 重启后，后台 JS/CSS/语言包返回一年缓存，后台 HTML 为 no-store，API 保持 no-cache/private。
- Chrome 正常刷新：7 项后台资源全部 disk cache；vendor 85 ms，页面 Load 704 ms。该耗时仅为本地结果。
- 登录页渲染及中英文切换通过，已恢复中文。未发布生产。
