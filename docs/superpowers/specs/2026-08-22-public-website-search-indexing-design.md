# 官网搜索引擎可索引性设计

## 目标

让恢复后的官网首页、定价方案、服务条款、隐私政策和退款说明具备稳定的搜索引擎抓取与索引基础，同时保持首页图片优化成果，不增加首屏网络请求或运行时依赖。

## 现状与问题

- `/` 与 `/welcome` 都以 `200` 返回同一首页，首页 canonical 却指向 `/welcome`，规范地址信号冲突。
- 首页主标题由 JavaScript 打字动画注入，初始 HTML 的 `<h1>` 为空；定价页使用 `<h2>` 作为页面主标题。
- 定价页与三份政策页缺少 canonical；政策页也缺少页面描述和明确的 robots 指令。
- `robots.txt` 允许抓取，但没有 sitemap 声明；`/sitemap.xml` 不存在。
- 公开页面没有统一的 Open Graph URL；首页缺少可直接读取的站点级结构化数据。

## 方案比较

1. 只补 meta 标签：改动最少，但仍保留重复首页、空静态主标题和页面发现能力不足的问题。
2. 静态 SEO 基线加服务器端规范化，采用：用 301 统一首页地址，为公开页补齐静态语义、canonical、robots、sitemap 和最小结构化数据。无需额外服务，对加载性能影响可忽略。
3. 引入动态 SEO/SSR 层：可以根据后台数据生成更多内容，但当前只有五个稳定公开页，复杂度和维护成本明显过高。

## 地址与抓取策略

- `https://www.elphantroute.com/` 是唯一首页 canonical。
- `/welcome` 保留为旧入口，但服务器端以 `301` 永久跳转到 `/`；跳转仍沿用现有 Host 安全检查。
- sitemap 只列 `/`、`/pricing.html`、`/terms.html`、`/privacy.html`、`/refund.html` 五个可索引公开页面，不列跳转地址、登录后的 `/app` 或管理路由。
- `robots.txt` 明确允许抓取，并声明 `https://www.elphantroute.com/sitemap.xml`。
- 每个公开页面显式使用 `index,follow,max-image-preview:large`，同时提供绝对 canonical URL。

## 页面语义与元数据

- 首页在初始 HTML 中直接提供 `CONNECT THE UNSEEN` 主标题；现有打字动画启动时先清空标题再重建，视觉效果不变。
- 定价页将页面主标题从 `<h2>` 改为 `<h1>`，样式类不变。
- 五个页面均提供独立 title、description、canonical、robots、Open Graph title/description/type/url。
- 首页添加静态 JSON-LD，描述 `WebSite` 和 `Organization` 的名称、URL、Logo；不为动态定价生成可能过期的 Offer 数据。

## 性能约束

- 不新增图片、字体、脚本、第三方请求或客户端 SEO 库。
- sitemap、robots 和元数据均为静态文本；JSON-LD 体积很小，不阻塞渲染。
- 保留现有 WebP 图片、懒加载、尺寸声明和异步解码配置。

## 测试与验收

- 聚焦测试验证五页的 title、description、robots、canonical、静态非空 H1 和正确 Open Graph URL。
- 测试验证首页 JSON-LD、`/welcome` 的 301 路由、robots sitemap 声明，以及 sitemap 只包含五个唯一 canonical URL。
- 运行现有官网、页脚和图片性能测试，防止导航或图片优化回退。
- 本地 Docker 验证五页、robots 和 sitemap 返回 `200`，`/welcome` 返回 `301`，并检查响应正文和 Content-Type。
- 用真实浏览器检查首页打字动画、定价主标题、页脚导航和控制台，确认视觉与交互无回退。

## 发布边界

本次只修改并验证本地仓库与本地 Docker，不推送远程，也不部署生产。上线生产后仍需在 Google Search Console 和 Bing Webmaster Tools 提交 sitemap；代码可提高可抓取性，但不能保证搜索引擎立即收录或排名。

## 回滚

删除新增 sitemap 和 SEO 元数据，恢复旧 robots 内容、`/welcome` 页面路由及原主标题标签即可。所有变更均为静态文件和路由代码，不涉及数据库或持久化数据。
