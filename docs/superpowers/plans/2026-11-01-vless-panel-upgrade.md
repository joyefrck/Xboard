# Xboard VLESS 面板与 V2bX 联动升级方案

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在现有管理端补齐 VLESS 加密、uTLS、XHTTP 配置及订阅输出，与 V2bX 建立兼容的新旧接口契约。

**Architecture:** 保留当前数据库和管理端壳，仅替换 VLESS 协议表单片段并扩展 `protocol_settings`。V2bX 先支持完整 `decryption` 和旧分段格式，再发布默认关闭增强项的面板。原有 TCP/REALITY/Vision 节点不自动切换协议或轮换密钥。

**Tech Stack:** Laravel 12、PHP、现有 React/shadcn 管理端及可读扩展模块、V2bX/Go/Xray、mihomo。

**状态：** 独立工作区中已实施源码并开展本地验收；未提交、推送或部署。发布与生产验收仍待后续执行。

## 取舍与首批范围

推荐逐项移植：P0 独立修复，VLESS 另批验证。整体合并上游会牵涉管理端子模块、插件目录、机器管理、Horizon 和订单字段迁移；只改面板则不能保证服务端理解新参数。

首批实现：TCP/RAW、现有 WS/gRPC/HTTPUpgrade、Xray XHTTP；安全层 None/TLS/REALITY；Vision；明确的 uTLS；VLESS Encryption 完整参数导入；REALITY 目标地址与 SNI 分开设置；订阅能力过滤。

本批暂缓：机器模式、WebSocket、ECH、ML-DSA 验证、REALITY ML-KEM 专用开关、Mux/Brutal、完整管理端替换及客户端安装包发布。它们不是补齐本次链路的前提。

## 面板行为

| 区域 | 字段与行为 |
| --- | --- |
| 基础 | 保留名称、权限组、地址、连接/监听端口、倍率、父节点和路由等现有公共字段 |
| 传输 | TCP 显示为 `TCP / RAW`，存储仍为 `tcp`；选择 XHTTP 时出现 path、host、mode，extra 为受校验的高级 JSON |
| 安全 | TLS 显示 SNI 和证书校验；REALITY 显示 SNI、目标地址、目标端口、X25519 密钥、Short ID |
| uTLS | 开关及指纹选择；新增 REALITY 节点默认 chrome，存量缺字段节点保留旧行为，显式修改后才持久化新值 |
| VLESS Encryption | 默认关闭；开启后显示完整客户端 `encryption`、服务端 `decryption` 导入框，校验配对信息与参数格式 |
| 后端能力 | 启用增强项前登记 Xray 核心及已安装的配套 V2bX release，管理员根据实际 build-info 和测试结果确认；不伪装成自动探测 |
| 状态反馈 | 保存失败定位到字段并保留输入；密钥默认遮蔽，显示、复制或重新生成均为明确操作，重新生成不自动保存 |

- 本批经验证的 Vision 组合限定为 TCP + TLS/REALITY；XHTTP 首批使用空 flow。切换到不兼容组合时明确提示并要求选择可用 flow，不静默改协议。
- 公网 None 安全层必须启用可用的 VLESS Encryption；不能把裸 VLESS 作为新建默认。旧配置有此组合时在升级预检中列出，不自动改写或假定新核心兼容。
- 初次打开/取消/仅修改节点名称，不得生成或清空密钥，不得把高级默认值写入老节点。
- 面板的能力提示根据管理员为节点登记并核实的实际运行核心版本；它是启用流程的门槛，运行时仍必须做真实校验，不能把人工登记当成自动探测结果。

## 持久化与接口契约

继续使用现有 `protocol_settings` JSON，不新增数据库列，不改节点 ID/路由/权限语义。新增结构：

```json
{
  "network": "tcp",
  "network_settings": {},
  "tls": 2,
  "flow": "xtls-rprx-vision",
  "utls": {"enabled": true, "fingerprint": "chrome"},
  "encryption": {"enabled": false, "encryption": null, "decryption": null},
  "backend": {"core": "xray", "release": "core-v1.1.0"}
}
```

- `reality_settings.dest` 是可选目标主机/IP；留空时兼容回退 `server_name`。目标端口仍放在 `server_port`。
- `backend.core/release` 是仅管理员维护的能力声明，不下发客户端；存量缺省为空。增强项要求登记本方案验收通过的 Xray/V2bX release，服务端校验不能被仅操作前端绕过。声明不证明程序实际已升级，启用前仍须核对运行 build-info。
- `server_name` 用于客户端 SNI；`private_key` 只下发服务端；`public_key` 和 Short ID 只按协议需要下发客户端。
- 加密开启时两端字符串必须同时存在，使用相同握手算法、外观模式和同一套认证密钥。不同身份认证方案不能混配。
- 保存校验模式、票据/0RTT 参数、密钥长度和成对关系；不能仅判断字符串非空。对暂不支持的字符串扩展返回字段错误，禁止截断后当成功保存。
- REALITY Short ID 限定偶数长度、最多 16 位十六进制；端口为 1–65535；X25519 密钥解码为 32 字节。对既有空 Short ID，保持原来允许空 ID 的语义并覆盖测试。
- 表单验证错误、日志、普通用户 API 不得回显任何服务端私钥。密钥生成通过可信本地实现/CLI，不通过 Web 请求执行用户提供的 shell 命令。

节点 API 示例（字段片段）：

```json
{
  "protocol": "vless",
  "network": "tcp",
  "networkSettings": {},
  "tls": 2,
  "flow": "xtls-rprx-vision",
  "decryption": "none"
}
```

- 普通节点显式 `decryption: "none"`；开启加密后发送完整服务端字符串。
- 新面板不发送旧 `encryption/encryption_settings` 分段字段，避免重复来源；旧面板继续由新 V2bX 兼容。
- 客户端订阅仅发送客户端字符串，不含 `decryption` 或 REALITY 私钥。

## Task 1：模型、保存校验与后端接口

**Files:**
- Modify: `app/Models/Server.php`
- Modify: `app/Http/Requests/Admin/ServerSave.php`
- Modify: `app/Http/Controllers/V1/Server/UniProxyController.php`
- Modify: `app/Http/Controllers/V2/Admin/Server/ManageController.php`
- Create: `app/Services/VlessConfigService.php`，集中规范化/组合校验/服务端与客户端映射，避免三处分别拼接。
- Create: `tests/vless-config.php`

- [ ] 先用隔离模型/请求验证器测试新字段 round-trip，以及空高级项的旧节点保存不变化。
- [ ] 加入上文数据结构、组合与密钥校验；接口调用和浏览器保存都必须经过相同后端校验。
- [ ] `UniProxyController` 的 VLESS 分支使用集中映射，增加 `decryption` 和可选目标地址，保留旧公共字段与 `tls_settings`。
- [ ] 覆盖复制节点及父节点行为，保留既有 ID、权限、路由和端口映射；不在普通保存动作中顺带修改其他协议。
- [ ] 运行 `php tests/vless-config.php`，断言用户投影永远没有服务端私钥。

## Task 2：局部升级管理端 VLESS 表单

**Files:**
- Create: `public/assets/admin/assets/vless-config.js`，可读模块。
- Modify: `public/assets/admin/assets/index.js`，仅 VLESS schema/表单接入点。
- Modify: `public/assets/admin/locales/zh-CN.js`、`en-US.js`、`ko-KR.js`
- Modify: `scripts/version-admin-assets.php`
- Modify: `resources/views/admin.blade.php`，仅由资源版本工具刷新引用。
- Create: `tests/vless-admin-form.test.js`

- [ ] 沿用 `node-automation.js` 的模块工厂模式，注入现有 React、表单库和 UI 控件，不增加第二套 React、不通过 DOM/网络拦截篡改保存请求。
- [ ] 可读模块导出 VLESS schema 与表单工厂，主 bundle 仅保留接线。公共节点字段继续由原编辑页管理。
- [ ] 默认值、协议切换、开关关闭再开启、错误回显、保存后重新打开都覆盖行为测试；关闭增强项不静默遗失尚未保存的输入。
- [ ] 更新资源版本工具的模块引用归一化规则和重写目标，将 `vless-config.js` 纳入同一内容版本，防止循环 hash 或旧缓存混用。
- [ ] 执行 `php scripts/version-admin-assets.php`，再执行 `php scripts/version-admin-assets.php --check`；第二次运行不得产生变化。
- [ ] 浏览器本地检查桌面与窄屏编辑、密钥遮蔽、表单验证、复制节点；复查节点自动化、黑名单、下载管理入口未回归。

## Task 3：订阅输出与客户端能力过滤

**Files:**
- Modify: `app/Protocols/ClashMeta.php`
- Modify: `app/Protocols/General.php`
- Modify: `app/Protocols/Shadowrocket.php`
- Modify: `app/Support/AbstractProtocol.php`、`app/Http/Controllers/V1/Client/ClientController.php`，接入最终能力过滤和客户端数据投影。
- Modify: `app/Services/NodeAutomation/ProbeProtocol.php`、`app/Http/Controllers/V2/Admin/Server/AutomationController.php`，防止增强协议被不支持的探针误判。
- Modify: `app/Protocols/SingBox.php`、`Stash.php`、`QuantumultX.php`、`Loon.php`、`Surge.php`、`Surfboard.php`，仅能力判断/受影响构建路径。
- Create: `tests/vless-subscription-contract.php`
- Create: `tests/vless-subscription-contract.test.js`

- [ ] Mihomo 输出 `encryption`、`client-fingerprint` 和 `xhttp-opts`；通用 URI 正确编码完整加密字符串与节点名。
- [ ] 旧节点无新配置时保留现有随机指纹行为；显式 uTLS 配置才覆盖它。
- [ ] 对尚未实测支持 VLESS Encryption/XHTTP 的客户端，排除增强节点或在直接指定导出时明确拒绝；禁止把加密字段丢掉再发送一个不可用节点。
- [ ] 最终能力检查在现有订阅 hook 执行后、协议输出前再次生效；未知客户端不因为缺少版本号而绕过增强功能限制。客户端投影先移除服务端密钥，普通节点列表继续使用 NodeResource 的现有字段白名单。
- [ ] 通用 URI 的增强节点用于明确支持该格式的客户端；不能把格式能输出等同于所有客户端都支持。
- [ ] 当前自动化探针是 sing-box 1.14.2。首批不把加密/XHTTP 增强节点加入自动切换组；表单保存/组绑定检查这一约束，运行时探针也明确拒绝。能力不支持作为配置错误，不计为节点网络故障、不触发 DNS 切换。普通 REALITY/Vision 的自动化继续按原规则运行。
- [ ] 保持本站强制 DIRECT 等现有路由、空组处理、流量包有效流量和到期信息，不能用上游整个订阅类覆盖。
- [ ] 用一个旧节点和一个增强节点验证不同客户端得到正确集合，旧节点始终保留，服务端私钥在所有输出中不存在。

## Task 4：联调与启用顺序

- [ ] 先完成并单独验收 P0。
- [ ] 新 V2bX 双架构构建和旧配置回归通过后，先在一个测试节点升级；测试仍使用旧面板参数。
- [ ] 发布面板的新增字段和订阅能力。存量节点增强项缺省关闭，节点 API 的 `none` 对旧 V2bX 不改变既有协议。
- [ ] 复制一个节点、使用独立测试端口和测试权限组，分别验证 TCP/REALITY/Vision、TCP/REALITY/VLESS Encryption、XHTTP/REALITY/空 flow。
- [ ] 每组检查真实握手、HTTPS、上传下载、UDP/XUDP（在宣称支持时）、流量归属、用户撤销及已配置 SOCKS 出口。
- [ ] 对增强协议到达当前 sing-box 探针的情况，断言不会剥掉加密参数后继续探测，也不会触发故障转移。
- [ ] 能力表只列已经运行验证的客户端版本；其余保持禁用/未验收状态。当前 ElephantNetwork 源码有加密实现，仍需验证实际安装包及真实订阅输出。
- [ ] 修改、停用、回滚一个增强测试节点，验证其他节点持续可用。增强节点的协议变化会使原参数失效，因此在独立测试节点验证，不能承诺旧客户端自动兼容。
- [ ] 所有 Xboard 生产发布执行 `xboard-release-checks`；V2bX 生产更新遵循既有签名清单、离线预检、备份、后台替换、监听验证和失败恢复流程。

## 回滚约束

先把测试节点恢复到已验证的旧协议参数并使客户端重新获取正确配置，再考虑回退 V2bX。旧 V2bX 会忽略不认识的完整 `decryption`，所以加密节点仍启用时禁止直接回退。面板回滚须先导出协议 JSON，防止旧表单保存时剥离新字段。恢复节点配置后也可能短暂重连，不能描述为零中断。

## 完成判据

P0 独立完成；面板保存/重开字段不丢失；旧协议无语义变化；新旧节点 API 契约回归通过；增强节点在指定 V2bX 与客户端组合上真实连接成功；失败配置不会静默降级或使正常旧节点永久失去监听。

## 2026-11-01 实施记录（独立工作区）

已实施相应源码修改，未提交、推送或部署。实现集中在本工作区，原主工作区和生产配置保持原状。
P0 使用隔离 Composer/Capsule 和专用临时 MariaDB，两个 PHP 进程调用真实订单/支付/礼品卡服务；不加载应用 .env。
VLESS 验证包含模型、保存校验、订阅投影、旧配置保留、探针拒绝与桌面/窄屏浏览器保存。

本批面板只导入可验证配对的 X25519 长期认证材料；VLESS Encryption 的混合 ML-KEM/X25519 密钥交换保持启用。
Mihomo XHTTP 共享 extra 字段转换为对应 xhttp-opts；xmux 转 reuse-settings。downloadSettings、h2Params 暂不接入，显式拒绝。
ECH 暂缓，真实安装包、生产节点和双架构 CI 的验证与本地源码检查分开报告。

发布仍按 P0、V2bX、VLESS 面板、独立测试节点顺序执行；本次代码修改不代表已发版。

### 本地验收结果与实际边界

- 32 项模型、保存校验、客户端投影、旧协议、探针及 REALITY 兼容检查通过；自动化状态机 40 项检查通过。
- 桌面/390px 窄屏使用真实管理端资源与隔离假 API 验证，保存/开关保留参数及密钥遮蔽通过。
- 新 Xray REALITY 强制混合 KeyShare：对登记为 xray/core-v1.1.0 或更新的节点，要求 chrome 并下发
  Mihomo support-x25519mlkem768。旧节点未登记和旧 release 不改；新 profile 不对未验客户端降级输出。
- 固定官方 Mihomo 1.19.33 与配套 V2bX 的 Linux arm64 回环 HTTPS 联调通过，包括旧配置、新加密、
  XHTTP/TLS、XHTTP/REALITY、非法候选保留监听/重试、流量归属和用户撤销。
- 广泛 Node 回归中的原有邀请页断言写死了过期缓存标签；已改为最低功能版本校验，并保留源/发布文件及压缩包一致性校验，不修改页面资产。
- 客户端正式安装包、双架构原生 CI、签名包及生产业务验收尚未执行；本次没有提交、推送或部署。

最终隔离回归：313 项 Node 检查全部通过；安全/队列/管理端专项门禁 32 项全部通过。

### 大象客户端订阅兼容补充

- Xboard 对实际 `Elephant Network/v2.1.0 clash-verge Platform/...` 请求单独解析品牌与版本，能力名为 `elephantnetwork`。
  `flag=meta` 只决定输出格式，不覆盖 UA 身份；缺省 flag 也能匹配 ClashMeta。2.1.0 以下、缺少版本、预发布及其他未知客户端继续过滤增强节点。
- Dart 实际请求验证确认 `PackageInfoExtension.ua` 使用品牌常量，而非各平台的 PackageInfo.appName；不需要为此次功能修改或重新打包客户端。
- `tests/vless-config.php` 累计 63 项通过；新增 `tests/vless-elephant-subscription.php` 的 11 组真实控制器和订阅输出检查通过，已加入隔离 CI。
  覆盖四平台标识、显式/缺省格式、私钥清理、混合 KeyShare、XHTTP extra、流量信息、节点组和强制 DIRECT 规则。
- 使用 Flutter 3.44.4 执行真实订阅下载代码，三种平台应用名称均生成统一品牌 UA；通过 HTTP 下载实际 Xboard YAML，并交给已安装原始内核校验，3 项通过。
- macOS 已安装的大象 2.1.0+20101 原始内核与当前 V2bX 工作区构建完成六组 HTTPS 联调。
  配置来自真实 Xboard 控制器/订阅输出，包含 TCP/TLS 加密、TCP 无 TLS 加密、REALITY/Vision 旧加密语义与新加密、XHTTP/TLS、XHTTP/REALITY；非法配置保留监听并重试、流量归属、最后用户撤销通过。
- 下载的 macOS 2.1.0+22101 包签名磁盘校验通过，但独立执行被本机 Gatekeeper 拒绝；没有绕过系统策略。
  此包、其他平台原生安装包的运行和 GUI 刷新点击验收仍未完成，不能将已安装 20101 内核结果等同于这些验收。
- 测试脚本和日志在 `output/elephant-vless/`；客户端源码、正在使用的客户端配置与原运行进程保持原状。本次仍未提交、推送或部署。
