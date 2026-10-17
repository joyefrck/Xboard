# 节点自动化配置

「节点管理 → 自动化配置」按节点域名管理备用 VPS。相同域名共用配置和备用池，一次 DNS 修改影响全部关联节点；不同域名即使 IP 相同也分别管理。新策略默认关闭。

页面分为三个标签，默认打开「备用池」进行日常管理；「固定配置」保存 Cloudflare 连接与公共检测账号；「全部切换记录」汇总所有域名的记录并分页展示域名、操作、状态和 IP 变化。在备用池里点击某个域名的「切换记录」会弹窗显示该域名的记录，支持关闭按钮、Escape 和遮罩关闭。切换标签保留尚未保存的配置输入。

备用的关联关系由新增入口决定：在某个域名组中点击「为此域名新增备用」，填写的 VPS 就绑定到这个域名及全部关联节点。列表的「关联域名 / NodeID」和新增表单会显示具体关联。比如 `us.443ds443.com` 对应 NodeID 1、2、3，备用 VPS 也必须部署这三个相同的 NodeID 和端口；无需在面板另建备用节点 ID。切换保留原节点 ID、域名和订阅地址，只修改 DNS 的 IP。

## 使用流程

1. 在 Cloudflare 的 My Profile → API Tokens 创建限定到 `443ds443.com` 的 Token：Zone / DNS / Edit、Zone / Zone / Read。填写 Zone 域名和 Token，保存后验证连接。使用 API Token，不使用 Global API Key。Token 经 Laravel APP_KEY 加密，仅在服务器解密调用 Cloudflare，页面和接口不会回传。
2. 在「固定配置」中的「公共检测账号」按权限组分别搜索并选择一个已有 Xboard 订阅用户，例如 level2、level3 各配置一个。账号必须属于所选权限组，且周期套餐未到期并有余额，或拥有有效且有余额的独立流量包；周期套餐到期不影响仍有余额的不限时流量包，推荐专用账号。返回「备用池」展开各域名，点击「验证配置并匹配 DNS」，系统按节点允许的权限组选择账号，并检查实际节点使用权限。节点允许多个权限组时，按权限组 ID 升序选择首个有效且有权限的账号；同一域名的不同节点可以使用不同账号。无需按节点配置账号。
3. 只支持唯一、未代理的公网 IPv4 A 记录。同名 AAAA、CNAME、多条 A 或记录 ID 被替换都会阻止启用；修正 Cloudflare 配置后重新匹配。接口固定绑定 Zone ID 和 Record ID。
4. 添加已部署 V2bX 的备用 VPS，填写名称、公网 IPv4 和优先顺序，小值优先。备用必须配置相同 NodeID、全部关联端口、协议、证书以及 Reality 等密钥。先「测试备用」，通过后再「验证并开启」。开启时会再次验证当前 VPS 和备用，将受管记录 TTL 修改为 60 秒并回读。
5. 开启后每分钟检测，任何一个关联节点连续三轮失败即触发。每次直接连接 VPS IP，保留原域名 TLS/SNI 和协议身份，通过真实 sing-box 请求 HTTPS 204；两地址任一成功即可。备用全部关联节点连续两次通过才会选择。自动切换前复查当前 VPS，恢复则取消。
6. 切换后原 VPS 禁用并进入「待检查」，测试通过也不会自动重新加入。先暂停自动化，在「检查并加入」中明确确认。正常切换冷却 10 分钟；新 VPS 在切换后的即时代理测试失败时直接尝试下一台合格备用，不恢复已故障旧 VPS。

Cloudflare 自动 TTL 为 300 秒。改成 60 秒不会立即清除已有客户端缓存；界面保留「等待 DNS 生效」，正常的新 IP 代理测试不会因缓存未更新消耗备用。现有连接还需要客户端重连。[Cloudflare TTL](https://developers.cloudflare.com/dns/manage-dns-records/reference/ttl/)

## 检测和故障边界

- 默认检测地址：`https://www.gstatic.com/generate_204`、`https://cp.cloudflare.com/generate_204`，每请求超时 8 秒。先检查直连可用地址，失败代理检测后再次复核检测地址，目标异常不累计节点失败。
- 账号无效、节点参数变化、探针不兼容、进程异常均属于配置/检测异常，不计 VPS 故障。节点参数或 DNS IP、TTL、记录结构被外部修改时会暂停策略，需要重新配置确认。
- 支持现有订阅构建器中的 Shadowsocks、VMess、VLESS、Trojan、Hysteria、TUIC v5、AnyTLS、SOCKS、HTTP。固定端口必需。实际启用还须通过 sing-box 配置检查和真实代理测试；未支持的协议无法开启。
- 第一版不能读取 VPS 服务商剩余流量，也不能发现仍正常联网的超额收费。测试请求本身消耗少量公共检测账号流量，且每个域名最多 16 个关联节点、10 台备用。备用 V2bX 在线不等于可用，是否通过真实代理测试才是切换依据。
- 更换或清除任一权限组的公共检测账号前，先暂停所有策略并等待后台任务完成。公共账号独立于 Cloudflare 连接保存；更换后清除旧检测结果和备用测试认证，保留 DNS 绑定及原 VPS 的待检查状态，逐组重新验证配置与备用。所有候选账号均失效、改组或权限不足时作为配置异常处理，不计 VPS 故障，绝不会回退到旧全局或逐节点账号。
- 修改配置或全局连接前先暂停；进行中的 DNS 修改必须回读完成。DNS 结果未知时保留操作意图、禁止新切换。DNS 已确认但账号失效时结束验证并允许修复配置。暂停不会撤销已提交的 DNS 修改。

## 安装与运行

镜像构建安装固定 sing-box **1.14.2**，脚本验证仓库中固定 SHA-256 后再解压安装。支持 Linux amd64/arm64 musl；开发调试另支持 Darwin arm64。手动部署在所有运行检测队列的容器/主机执行：

```sh
sh scripts/install-node-automation-probe.sh
php artisan migrate --force
php artisan config:clear
php artisan octane:reload
php artisan horizon:terminate
```

先备份数据库，再执行迁移。升级公共检测账号时先暂停所有自动化并解决未完成的 DNS 操作；迁移会检查这一条件。历史逐节点配置会先合并无歧义账号；按权限组升级时只将旧公共账号迁到其实际所属且仍存在的权限组，其他组由管理员明确配置。迁移清空旧全局字段，旧检测结果与备用认证失效，需要重新验证。Horizon 应由现有进程管理器自动拉起；确认 `XboardNodeAutomation` 独立 supervisor 的 2 个 worker 已运行。新连接 `node_automation` 的 retry_after 为 660 秒，worker timeout 为 540 秒；业务队列参数不受影响。Docker 镜像已安装 curl/coreutils，非镜像部署还需 PHP curl、pcntl、Redis 和 `/usr/bin/timeout`。探针只监听 `127.0.0.1`，独立 watchdog 限制单进程 25 秒，临时配置权限 0600 并及时清理。

可选路径环境变量：`NODE_AUTOMATION_BINARY`、`NODE_AUTOMATION_TIMEOUT_BINARY`。共享 Redis 必须可用，分布式锁依赖面板的缓存配置。APP_KEY 必须保持稳定并随数据库备份妥善保管。

继续使用面板已有每分钟 Laravel 调度入口，不重复添加 cron。检查：

```sh
php artisan schedule:list
php artisan horizon:status
php artisan node-automation:check
```

若当前环境没有调度入口，配置每分钟运行 `php /www/artisan schedule:run`。`schedule:list` 显示命令只证明注册成功，还需检查实际 cron/调度进程和 worker 持续消费；部署后不要只看队列进程存在。暂停策略仍会恢复未确认 DNS 操作。

## 生产启用验收

先备份数据库和受管 DNS 原记录（Zone、Record ID、IP、TTL、代理状态），再进行真实备用测试。使用专门测试域名与代理服务演练，不耗尽真实 VPS 流量来制造故障。

逐项确认：短暂故障恢复后计数清零；三轮失败才切换；端口开放但代理失效、错误认证、UDP/QUIC 故障可识别；共用域名任一节点失败能触发而备用必须全部通过；无合格备用保持 DNS；权限/限流错误不产生虚假成功；响应丢失和 worker 重启只回读、不重复 PATCH；外部 DNS 修改暂停。

最后逐组启用，分别验收每分钟调度、独立队列消费、Cloudflare 回读、权威解析、新 IP 代理连接和实际客户端重连恢复。受客户端缓存影响，API 返回成功不能单独证明恢复。回退代码前先暂停全部策略并解决未确认 DNS 操作；不自动恢复旧 IP。数据库新增表和历史记录可保留。

## 本地测试

```sh
node --test tests/node-automation.test.js
php tests/node-automation-probe.php
php scripts/version-admin-assets.php --check
```

状态机/API 使用内存 SQLite，Cloudflare HTTP 使用 mock，均不加载 `.env` 或修改真实 DNS。真实探针演练用临时本地 Shadowsocks、Hysteria2 和两个 HTTPS 204 服务，保持 TLS 校验并仅为测试临时证书提供信任，覆盖停服、开放端口无代理、错误认证、未信任证书和 UDP 不可用。需要已安装探针及 openssl。`NODE_AUTOMATION_TEST_BINARY` 可指定测试二进制路径。

后台验收可运行 `node tests/node-automation-preview.cjs`，打开 `http://127.0.0.1:7315/preview#/server/automation`；仅用于 UI 验收，数据均为内存示例，停止进程即清空，不连接真实数据库或 Cloudflare。

参考：[Token 配置](https://developers.cloudflare.com/fundamentals/api/get-started/create-token/)、[DNS PATCH 接口](https://developers.cloudflare.com/api/resources/dns/subresources/records/methods/edit/)、[sing-box 1.14.2](https://github.com/SagerNet/sing-box/releases/tag/v1.14.2)。
