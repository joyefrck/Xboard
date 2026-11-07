# 搬瓦工 VPS 管理

入口为「节点管理 → 搬瓦工VPS管理」。支持本周期流量、基础资源、运行指标、库存管理，以及单台重置 root 密码。

## 导入和查看

从搬瓦工账单门户导出实例 API CSV，完整粘贴到多行框，先识别再导入。表头要求 `VEID`、`API_KEY`、`IS_TERMINATED`，兼容官方 `VM_TYPE,HOSTNAME,PRIMARY_IP,IS_2FA_ENABLED` 等列、UTF-8 BOM、CRLF、引号和空 IP。每次最多 500 条、256 KiB。空行忽略，已终止条目跳过，重复 VEID 采用首条有效记录，格式错误只报告行号和原因。

按 VEID 新增/更新，加密保存密钥，保留现有备注。更换密钥会清除旧接口数据并立即重新同步。导出 IP 只用作待验证展示，不能在自动化中作为已确认匹配。页面不回传 API Key；粘贴内容不进入浏览器持久存储，全部导入成功后清空输入。

列表支持搜索、运行/关机/暂停/异常/待同步筛选、30 条分页、单台刷新、同步全部、修改备注、移除记录。移除只删除 Xboard 库存和密钥，不调用供应商删除接口。实例详情展示接口可用的内存/Swap/磁盘配额、负载、可用内存、磁盘映射占用、SSH 端口和 CPU/磁盘限速状态，缺失指标显示未提供。

基础信息与实时信息分别记录成功时间和失败原因。同步异常保留上次成功数据，超过 15 分钟标记待更新，页面每 10 秒读取库存快照。服务器列表提供两个独立开关：

- “自动同步”控制后台每五分钟向搬瓦工获取数据，默认开启，状态保存到服务端，全部管理员与 worker 共用。关闭后不再提交自动任务，未开始的自动任务跳过并清理自身排队标记，导入后的自动读取也暂停。已发出的请求可以完成，调用第二个自动接口前会再次检查。恢复后按原五分钟调度执行；单台“刷新”“同步全部”和明确的密码重置操作仍可使用。
- “自动刷新”只控制页面读取 Xboard 库存快照，在当前浏览器记住选择；恢复时立即读取最新结果。关闭它不会改变后台自动同步设置，搜索、筛选、分页和手动操作仍可使用。

后台开关从现有设置表直接读取，避免常驻 worker 使用旧值；保存采用独立设置锁和事务。任务携带自动/手动来源布尔标记，旧版本未携带来源的队列任务按自动任务处理。排队超过 10 分钟可手动重新提交；每台服务器两次同步尝试至少间隔 30 秒。

流量来自 `getServiceInfo` 的 `data_counter`、`plan_monthly_data`、`monthly_data_multiplier` 和 `data_next_reset`。按接口文档约定同时对额度与计数应用倍率，使用 GiB/TiB 二进制单位；百分比按原始计数/额度计算。进度条上限 100%，文字保留超额百分比；零/未知额度不构造虚假百分比。80% 起橙色，95% 起红色。

## 自动化显示

自动化读取库存缓存，不在页面请求或节点探测中调用搬瓦工。按最近同步成功的 IP 精确关联，支持标准化 IPv6 地址；同一 IP 出现在多条实例记录中显示冲突，不猜测绑定。每个当前 IP 下方显示整机流量进度，多 IP 共用同一 VPS 额度。未匹配、无流量、数据过期、同步失败分别显示提示。本功能不增加流量阈值切换策略。

## 重置 root 密码

仅单台操作：弹窗显示名称、IP、VEID，点击“确认重置 root 密码”提交，无需手动输入编号或原密码。页面自动携带当前 VEID，后端仍校验它与目标实例一致。请求前记录状态和时间，五分钟冷却，同一实例的同步、管理和密码操作共享分布式锁。固定 POST 调用 `resetRootPassword`，不自动重试；网络/供应商异常、缺失密码等情况显示结果未确认，需要在 KiwiVM 核实。进程中断后的 pending 也保持可见，不报告成功。

新密码只存在于此次 no-store 响应和临时弹窗，默认遮挡，可显示/隐藏及复制，关闭弹窗清除，不能从后续库存请求重新读取。数据库、队列载荷和应用操作日志不保存密码或明文密钥。现有请求日志中间件仅记录 POST 路径；异常输入禁止回填 CSV/API Key。

## 部署与运行

此功能新增 `v2_bandwagon_vps` 表。正式发布仍须按 Xboard 全量发布检查执行，备份数据库、保持 APP_KEY 稳定，再运行迁移、刷新配置和重启进程。本文不代表已部署。

调度 `bandwagon:sync` 每五分钟提交任务，使用现有 Redis 连接的独立 `bandwagon` 队列，`XboardBandwagon` 固定 2 个 worker，timeout 55 秒，小于 Redis retry_after 90 秒。供应商请求单次连接超时 5 秒、总超时 22 秒；每次同步至多两次请求，实例锁 65 秒。队列只存实例 ID 和随机任务 token，旧 token 或已移除实例任务不执行供应商调用。调度、worker 和队列实际消费均须在发布时验收；不可只检查配置文件。

```sh
php artisan migrate --force
php artisan bandwagon:sync
```

供应商接口固定 `https://api.64clouds.com/v1/`，仅允许 `getServiceInfo`、`getLiveServiceInfo`、`resetRootPassword`，使用 POST、TLS 验证、禁止重定向，不开放任意 endpoint。

## 验证记录（2026-11-02）

- `php tests/bandwagon-vps.php`：内存 SQLite、fake HTTP，CSV 分类/边界、密钥加密与隐藏、队列去重、密钥轮换清理、IPv4/IPv6/冲突匹配、失败保留数据、密码确认/冷却/未知结果、单次密码响应和迁移回滚。
- `node --test tests/bandwagon-vps.test.js tests/node-automation.test.js tests/node-automation-status.test.js tests/admin-asset-cache.test.js tests/admin-user-identity-edit.test.js tests/horizon-provisioning.test.js tests/horizon-fixed-workers.test.js`：覆盖新增及关联模块。
- `php scripts/version-admin-assets.php --check` 和 `git diff --check`。
- `ADMIN_PREVIEW_PORT=7316 node tests/node-automation-preview.cjs`：可丢弃内存预览，CSV 使用实际 PHP 解析器；Playwright 检查真实后台壳中的导入、备注、详情、状态筛选、搜索、移除、密码遮挡/显示/复制/关闭清除、自动化流量条、1600px 桌面与 390px 手机布局。截图位于本地 `output/playwright/bandwagon-*.png`。

尚未进行真实 KiwiVM 凭据联调、真实 root 密码重置或生产部署。全部密码与凭据测试均使用模拟数据。

## 页面自动刷新开关验证（2026-11-07）

- 现有搬瓦工、后台资源缓存及节点自动化回归共 21 项通过；`php scripts/version-admin-assets.php --check` 与 `git diff --check` 通过。
- Playwright 使用本地合成库存验证：关闭后等待 10.5 秒无轮询请求；重新开启立即读取一次，并在 10 秒后继续轮询；重新载入后保留暂停状态。
- 暂停期间搜索及手动同步正常；Space/Enter 切换正常；导航离开后定时器清理；localStorage 读写被拒绝时仍可操作。
- 检查 1440 像素桌面及 390 像素手机截图，手机无横向溢出。截图位于 `output/playwright/bandwagon-auto-refresh-{desktop,mobile}.png`。
- 以上为本地隔离验证，未部署生产，未调用真实搬瓦工接口。

## 后台自动同步开关验证（2026-11-07）

- `php tests/bandwagon-vps.php`：100 项隔离 SQLite/fake HTTP 检查通过，覆盖设置默认/持久化、旧任务、关闭后自动任务与导入跳过、手动 API 仍执行、接口之间关闭、并发保存、非法输入及恢复调度。全程未调用真实搬瓦工接口。
- 相关搬瓦工、后台资源缓存、Horizon 与节点自动化 Node 回归 30 项通过；PHP 语法、真实 provisioning、静态资源版本与差异检查通过。
- Playwright 验证两个开关相互独立、后台设置重新载入后保留、关闭时手动同步可用、模拟保存失败后不改变开关、键盘切换，以及 1440px 桌面和 390px 手机明暗布局。模拟失败产生的一条 HTTP 422 日志为预期，未出现脚本异常。截图位于本地 `output/playwright/bandwagon-auto-sync-*.png`。
