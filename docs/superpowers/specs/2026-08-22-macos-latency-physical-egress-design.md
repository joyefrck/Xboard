# macOS 主核心测速 Worker 修复设计

## 背景与实测根因

macOS 1.6.5 当前启动第二个 sing-box 进程并行测速。日志与截图逐项对应：东京节点的复用连接 HTTP 204 往返为 108–121ms。

初步假设是临时核心自动选择了 `utun`。为验证该假设，临时核心被强制配置为系统默认物理接口 `en0`，但真机结果仍为 120ms，证明仅设置 `default_interface` 不能在 macOS 已启用 TUN 时消除这条额外路径，该假设被否定。

同一时刻、同一东京 03 节点、同一 `https://www.gstatic.com/generate_204`，通过当前主核心 loopback 代理连续请求两次，复用连接后的结果为 66ms。计时公式与连接复用正确，差异来自独立临时核心的网络路径。Android 的低延迟实现把四个测速 worker 放在正在运行的主核心内；其底层 socket 与主 VPN 共用正确的物理出口，不需要启动第二个核心。

## 目标

- macOS 使用与 Android 相同的“主核心内四 worker”结构，测速不再经过独立临时核心路径。
- 不切换用户可见的“节点选择” selector，不关闭用户现有连接。
- 保留两个连续 HTTP 204 请求、连接复用、取较小成功值、四 worker 并发和逐节点结果回调。
- 不使用除二、比例系数或 UI 显示补偿。
- macOS 应用版本继续使用 1.6.5（build 10605）。

## 方案比较

### 方案 A：主核心内置独立测速 Worker（采用）

连接前动态分配四个 loopback 端口，在主配置内加入四个私有 mixed inbound 和四个私有 selector。测速时每个 worker 只切换自己的 selector，curl 通过对应 loopback 端口连续请求两次。用户当前 selector 和业务连接不变。

### 方案 B：独立核心绑定物理接口（不作为主方案）

该方案已经真机验证：强制 `en0` 后东京 03 仍为 120ms，不能达到目标。现有接口解析与配置约束可保留为独立核心回退的防护，但不能宣称解决延迟口径。

### 方案 C：按比例缩小显示值（不采用）

网络拓扑和节点协议不同，不存在可靠的固定倍率。该方案会伪造结果，也不会改变真实测速路径。

## 组件与数据流

1. `MacosLatencyConfigBuilder.addLiveWorkers` 在已清洗的主配置中注入 loopback-only worker，不删除 TUN、现有 inbounds、outbounds 或规则。
2. `MacosVpnService.start` 启动主核心前动态分配四个端口，保存 worker 端口与可测节点集合；注入失败时仍允许主连接启动，但标记 worker 不可用。
3. `MacosLiveLatencySession` 使用主 Clash API `127.0.0.1:9090` 切换每个私有 selector，然后调用现有 `MacosCurlConnectionProbe` 测对应端口。
4. `MacosVpnService.testConnectionLatencies` 优先使用 live session；只有 worker 不可用或订阅节点集合不匹配时，才进入现有回退路径。
5. 停止测速只取消 live session 拥有的 curl 进程，不停止主核心、不切换主 selector。

## 配置约束

- worker 端口必须在 1–65535、互不重复，并只监听 `127.0.0.1`。
- 可测节点只包含具体代理 outbound；排除 selector、urltest、direct、block 和 dns。
- worker 路由规则放在原有规则之前，仅匹配各自私有 inbound。
- 重复构建配置时先移除旧 worker，避免 reconnect 后重复注入。

## 错误处理

- 端口分配或配置注入失败：记录不含订阅内容的错误类型，主 VPN 正常启动，测速使用现有回退。
- selector 更新、curl 超时或 HTTP 错误：沿用现有 typed result，并继续其他节点。
- 用户取消测速：终止本次 session 的 curl 子进程，保留主核心和用户连接。
- 物理接口解析约束继续保留给独立核心回退，避免回退配置自动选择 `utun`，但它不是主要准确性保证。

## 测试与验收

- 配置测试覆盖四组 worker 注入、原规则保留、重复构建去重、非法端口和具体节点过滤。
- live session 测试覆盖最多四并发、先切 selector 后探测、增量结果、取消与剩余节点失败结果。
- service 测试覆盖 worker 就绪状态、节点集合校验、不可用回退与停止生命周期。
- 运行 Flutter 静态分析、相关测试和完整测试。
- 构建 macOS 1.6.5 arm64 DMG，并验证架构、版本、签名、挂载和 SHA-256。
- 真机重新连接后，确认日志使用 `macOS live-worker latency`，并对同一东京节点复测；目标是接近主核心实测的 66ms，而不是独立核心的 108–121ms。

