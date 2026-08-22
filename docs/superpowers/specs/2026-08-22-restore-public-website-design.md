# 恢复大象网络公开官网设计

## 目标

在不影响用户面板、客户端下载、AI 客服、后台资源和生产环境的前提下，将此前下线的大象网络公开官网恢复到本地 Docker 测试环境。

恢复范围包括：

- `/`：官网首页。
- `/welcome`：官网首页兼容入口。
- `/pricing.html`：定价方案。
- `/terms.html`：服务条款。
- `/privacy.html`：隐私政策。
- `/refund.html`：退款说明。

## 内容来源

页面内容采用各文件从 Git 历史中删除前的最后版本：

- `public/landing/index.html` 使用提交 `2b92c1892de7a708297e4ae7f5a4df3b3cc76e20` 的父提交版本。
- `public/pricing.html`、`public/terms.html`、`public/privacy.html`、`public/refund.html` 使用提交 `b62ce856f4fd153479022edc2f9f9dbc5462a63e` 的父提交版本。
- 已保留的 `public/landing/assets/` 图片继续复用，不复制或重做素材。

此次按最后上线版本原样恢复，不改版、不重写营销文案，也不擅自修改法律政策。服务条款、隐私政策和退款说明保留原有“最近更新日期：2026年3月17日”。

## 路由与页面行为

`routes/web.php` 继续使用现有 Host 白名单逻辑保护公开入口。通过白名单校验后：

- `/` 和 `/welcome` 都读取并返回 `public/landing/index.html`，响应类型为 `text/html; charset=utf-8`。
- 如果首页文件意外缺失，返回明确的 `404`，不静默回退到登录面板。
- `/app` 及 `/app#/login`、`/app#/register` 的用户面板行为保持不变。
- 首页与定价页的登录、注册按钮继续指向现有 SPA 地址。
- 首页页脚继续链接定价方案、服务条款和隐私政策；退款说明通过直接 URL 保持可访问。

静态的 `.html` 文件由 Laravel Octane 当前公开目录直接提供，不为四个关联页新增重复路由。

## 文件边界

新增或修改：

- 恢复 `public/landing/index.html`。
- 恢复 `public/pricing.html`。
- 恢复 `public/terms.html`。
- 恢复 `public/privacy.html`。
- 恢复 `public/refund.html`。
- 修改 `routes/web.php`，恢复首页响应逻辑。
- 修改 `tests/home-login-redirect.test.js`，将“首页必须跳登录”的旧断言替换成官网恢复契约测试。
- 如静态关联页缺少现有覆盖，新增一个聚焦测试验证文件存在、标题和互链。

明确不修改：

- `public/download/index.html`。
- `public/landing/assets/` 中现有图片。
- `/support/ai`。
- `/app` 用户面板及 ElephantRoute 主题资源。
- 后台静态资源、客户端源码、数据库、Redis、Horizon 和生产环境。

## 测试环境发布

本地 Compose 的 `web` 容器将当前仓库绑定到 `/www`，因此恢复的静态文件会直接出现在容器中。路由代码由常驻 Octane 进程加载，完成代码与测试检查后只重启 `xboard-web-1`，不重启 `xboard-horizon-1` 或 `xboard-redis-1`。

本地验收地址为 `http://127.0.0.1:7001`。验收覆盖：

- `/` 与 `/welcome` 返回官网首页且不跳转登录页。
- 四个关联页均返回 `200`，标题与内容类型正确。
- `/app` 仍返回用户面板。
- 首页、定价、服务条款、隐私政策、退款说明之间的预期链接可到达。
- 页面引用的本地图片返回 `200`。
- `web`、`horizon` 和 `redis` 容器保持运行，Redis 保持健康。

## 测试策略

先调整回归测试使其表达新的官网契约，并在恢复实现前验证测试失败。恢复文件和路由后再次运行聚焦测试，确认由失败转为通过。随后运行：

- PHP 语法检查。
- 公开页面与下载页相关的 Node.js 测试。
- `git diff --check` 和变更文件审计。
- 容器内文件检查。
- 本地 HTTP 状态、最终 URL、标题、关键链接和图片检查。

## 风险与回滚

- 旧页面的营销信息和法律说明可能需要未来单独复核；本次恢复保持历史内容，避免把内容更新与恢复动作混在一起。
- Octane 未重启时可能继续使用旧路由，因此本地发布必须包含 `web` 容器重启和重启后的 HTTP 验证。
- 如果测试环境验收发现问题，回滚仅需撤销本次页面与路由改动并重启 `web` 容器；不涉及数据库或持久化数据恢复。
- 本次不推送远端、不发布生产，测试环境通过后再由用户决定是否更新内容或安排生产发布。
