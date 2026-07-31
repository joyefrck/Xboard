# 大象网络主域名切换设计

## 目标

将大象网络主域名从 `https://www.elephant111.com` 切换为
`https://www.elephant111.org`，让线上生成链接、后续客户端构建的内置兜底地址和
Android VPN 直连规则统一使用新域名，同时保留旧域名作为兼容入口。

## 当前状态

- `www.elephant111.org` 和裸域名 `elephant111.org` 已解析到
  `47.238.145.117`。
- OpenResty 已为 `.org` 提供 HTTPS；证书 SAN 同时覆盖裸域名和 `www`。
- 裸域名 HTTP/HTTPS 均 301 到 `https://www.elephant111.org`；`www` 登录页和
  `/api/v1/guest/domain/check` 已可用。
- 线上 Laravel `.env` 的 `APP_URL` 和数据库 `app_url` 仍为 `.com`，`.org`
  只是 `app_url_aliases` 中的备用地址。
- 动态域名分发已启用 `.org` 并赋予最高权重，但 Flutter 内置兜底、Android
  生产构建脚本及 Android VPN DNS/路由直连规则仍写死 `.com`。

## 方案选择

采用兼容切换，不做旧域名硬下线：

1. 将线上 `APP_URL` 与数据库 `app_url` 改为 `.org`。
2. 从 `app_url_aliases` 删除已成为主值的 `.org`，加入原主域名 `.com`；其他别名
   保持原顺序和内容。
3. 将活跃客户端 `clients/elephant-route-deprecated` 的默认 API 兜底、Android
   正式构建地址和 Android VPN 后端直连域名改为 `.org`。
4. 不修改动态域名服务中的其他入口和权重，不修改历史设计文档，也不把测试中
   用于模拟多域名选择的 `.com` 场景机械替换掉。

该方案让新生成的登录链接、邮件链接、订阅链接以及 Laravel 资源 URL 优先使用
`.org`，同时让尚未升级的客户端和历史 `.com` 链接继续通过安全模式校验。

## 变更边界

### 线上配置

- 变更服务器站点目录中的 `.env`，并在变更前创建带时间戳备份。
- 通过 Xboard 的 `admin_setting` 写接口原子更新 `app_url` 与
  `app_url_aliases`，由现有设置层清理 Redis 设置缓存。
- 清理 Laravel 配置缓存并重启 Web/Horizon 容器，使常驻进程同时读取新
  `APP_URL`；不修改数据库连接、业务数据、OpenResty vhost 或证书。

### 客户端源码

- `clients/elephant-route-deprecated/lib/utils/constants.dart`：默认
  `ApiConstants.baseUrl` 改为 `.org`。
- `clients/elephant-route-deprecated/build_prod.sh`：正式 Android 构建注入的
  `BASE_URL` 改为 `.org`。
- `clients/elephant-route-deprecated/android/app/src/main/kotlin/com/elephantroute/SingboxVpnService.kt`：
  DNS 本地解析和路由直连规则改为 `.org`，确保 VPN 启动后访问新主域名不绕进
  隧道造成自依赖。
- 增加聚焦契约测试，明确上述三个生产入口都指向 `.org`，并验证旧 `.com`
  不再出现在生产源码路径中。现有动态域名解析单元测试继续保留多域名样例。

## 数据流

新客户端启动时仍先访问 `https://www.elephant-ipcheck.com/api/domains` 并执行健康
检查；只有动态解析失败或被显式禁用时，才使用 `.org` 兜底。服务端生成订阅、
邮件登录、支付相关站点地址时优先读取数据库 `app_url`，Laravel 自身 URL 与存储
资源地址读取 `.env` 的 `APP_URL`，两处统一后不会产生混合域名。

## 失败处理与回滚

- 写入前记录 `.env`、`app_url` 和 `app_url_aliases` 原值。
- 线上任何配置检查或健康检查失败时，立即恢复 `.env` 备份和原数据库设置，再
  清缓存并重启相关容器。
- 客户端改动与线上切换相互兼容：即使源码尚未发布，动态域名和旧 `.com` 别名
  仍能维持已安装客户端；即使线上回滚，`.org` 仍是已验证可用的别名入口。
- 不删除 DNS、证书、vhost 或旧域名，因此回滚不依赖外部 DNS 传播。

## 验证标准

- 线上 `config('app.url')` 与 `admin_setting('app_url')` 都为
  `https://www.elephant111.org`。
- `app_url_aliases` 包含旧 `.com` 和既有三个备用域名，不重复包含 `.org`。
- `https://www.elephant111.org/` 302 到 `/app#/login`，`/app` 与
  `/api/v1/guest/domain/check` 返回 200；裸域名继续 301 到 `www`。
- 旧 `.com` 根路径仍通过安全模式并进入登录页，其他既有别名也保持可用。
- Web、Horizon、Redis、MariaDB 和 OpenResty 容器健康，Horizon 状态正常。
- 聚焦客户端契约测试、Flutter analyze 和相关 Flutter 测试通过；不在本次切换中
  构建或发布新的安装包。

