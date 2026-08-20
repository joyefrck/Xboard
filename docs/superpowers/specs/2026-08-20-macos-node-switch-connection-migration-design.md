# macOS 节点切换连接迁移设计

## 问题

macOS 客户端通过 Clash API 更新 `节点选择` selector 后，sing-box 只把后续新连接交给新节点，已经建立的 TCP、HTTP/2、WebSocket 和 UDP 会话仍可留在旧节点。浏览器刷新 IP 页面或 ChatGPT 继续复用长连接时，界面虽然已经选择香港，实际出口仍可能保持东京。

实机日志证明香港选择命令和断线重连后的节点恢复均能成功，因此故障不在订阅解析或 selector 标签；故障边界位于热切换后的存量连接迁移。当前 selector 没有 `interrupt_exist_connections`，而上游已知问题表明，在 selector 作为 `route.final` 时，即使启用该选项也不能可靠中断经路由建立的存量连接。

现有 UI 还以 `unawaited(provider.selectNode(node))` 发起切换后立即关闭节点页面。macOS 控制器只执行 selector `PUT`，既不读取 `now` 确认核心状态，也不清理旧节点连接。这会把“点击已受理”和“数据面已经迁移”混为同一状态。

## 目标

- 手动从东京切到香港后，selector 与后续业务连接都使用香港。
- 不重启整个 sing-box，不重新创建 TUN，不恢复再重设系统代理。
- 只关闭经过旧 selector 成员的连接，保留直连、测速内部连接和无关出站连接。
- 核心读取确认失败或旧连接清理失败时，UI 不得宣称切换成功，也不得持久化错误节点。
- 保持 macOS 1.6.5 的现有订阅、DNS、测速和自动选择行为。

## 非目标

- 不修改节点名称、服务器配置或出口地区判断。
- 不修补 sing-box 上游 selector 内部实现。
- 不改变 Windows、Android 的节点切换协议。
- 不为节点切换重启核心或切换 TUN/系统代理生命周期。
- 不关闭不经过旧 selector 成员的连接。

## 方案比较

### 方案一：每次切换重启 sing-box

可靠地清空全部连接，但会重建 TUN、造成明显断网和 ChatGPT 重新连接，并重新引入此前需要避免的生命周期抖动。本方案不采用。

### 方案二：仅增加 `interrupt_exist_connections: true`

改动最小，但 sing-box 在 selector 作为 `route.final` 的路径上存在已知存量连接不中断问题，无法作为本版本的可靠修复。本方案只可作为防御性配置，不能作为验收依据，因此不采用。

### 方案三：切换确认后精确关闭旧链路连接

这是选定方案。客户端在 selector 切换前读取旧成员和活动连接快照，执行 `PUT` 后读取 `now` 确认，再逐条关闭切换前经旧成员与 selector 建立的连接。关闭范围由连接 ID 精确限定，不调用会重置整个网络的全量连接删除接口。

## 组件设计

### `MacosClashController`

增加三个明确的控制边界：

- 读取 selector，返回其当前成员 `now`；
- 读取活动连接的最小快照，只保留连接 ID 与 `chains`；
- 按连接 ID 关闭指定连接。

连接 JSON 解析必须容忍缺少 `connections`、`id` 或 `chains` 的条目。无 ID 或 chains 不是字符串列表的条目不参与清理。HTTP 非成功状态、响应结构非法和网络异常统一转换为 `MacosClashControllerException`。

### `MacosVpnService`

`selectOutbound(groupTag, outboundTag)` 按以下顺序执行：

1. 从本地已消毒配置构造待持久化配置，但暂不写盘。
2. 读取 selector 当前成员 `oldOutbound`。
3. 当 `oldOutbound == outboundTag` 时直接确认成功，不关闭连接。
4. 读取一次活动连接快照，筛选 `chains` 同时包含 `groupTag` 和 `oldOutbound` 的连接 ID。
5. 执行 selector `PUT`。
6. 再次读取 selector；只有 `now == outboundTag` 才进入清理阶段。
7. 逐条关闭步骤 4 捕获的旧连接。关闭失败即判定本次迁移未完成，并记录已成功关闭数量与失败连接数量，但日志不得记录目的地址或用户凭据。
8. 全部清理成功后更新 `_lastSanitizedConfig` 并以原子文件替换方式持久化 `config.json`。

若第 5 步后读取确认失败，不尝试猜测是否成功，不更新内存配置或磁盘配置，并向调用者抛出错误。selector 可能已经切换，但 UI 会明确显示失败，下一次读取运行时状态可重新同步；不能用未经确认的状态覆盖本地记录。

### `NodeProvider` 与节点页面

`NodeProvider.selectNode` 继续作为唯一的选择与持久化入口。只有 `VpnManager.selectOutbound` 完整返回后，才通知并保存新节点；失败时恢复旧选择并保留现有错误信息。

节点页面改为等待 `selectNode`。切换期间禁止重复点击并保留页面；成功后关闭页面，失败则页面保持打开并显示现有错误提示。这样 UI 展示的选择状态与数据面迁移结果一致。

自动选择仍复用同一个 `VpnManager.selectOutbound`，因此自动切换也得到相同的存量连接迁移保证；并发选择继续由 `NodeProvider` 的 generation 与 selection tail 串行化。

## 错误处理与一致性

- selector 读取、切换或确认失败：不持久化目标节点。
- 活动连接读取失败：不执行切换，避免无法完成连接迁移。
- 单个旧连接在关闭前已经自然结束并返回“未找到”时视为幂等成功；其他关闭错误使操作失败。
- 配置持久化失败不会撤回已经完成的数据面迁移，但必须记录错误；运行时确认仍是当前会话权威，重新连接前需保留内存中的目标配置。
- 不在日志中输出连接目标、源地址、订阅内容或节点凭据。

## 测试

### 控制器测试

- selector `GET` 正确解析 `now`。
- 活动连接只解析合法 ID 与字符串 chains。
- 指定连接使用 URL 编码后的 ID 调用 `DELETE /connections/{id}`。
- HTTP 错误、结构错误与连接已经消失的幂等行为符合约定。

### VPN 服务测试

- 东京切香港时顺序为：读取 selector、读取连接、切换、读取确认、关闭东京旧连接、持久化。
- 只关闭 chains 同时包含旧东京和 `节点选择` 的连接。
- 相同节点选择不关闭连接。
- `now` 未变为目标节点时不关闭连接、不更新配置并抛错。
- 关闭旧连接失败时不报告迁移成功。

### Provider 与 UI 测试

- 节点页面只有在异步切换成功后才退出。
- 切换期间重复点击不会产生第二次请求。
- 失败时页面保持、旧节点恢复并显示错误。
- 较新的选择仍覆盖较早的延迟选择。

## 验收

自动验证包括 macOS 控制器与 VPN 服务定向测试、NodeProvider 与节点页面测试、全量 Flutter 测试、`flutter analyze` 和 macOS Release 构建。

实机验收使用活动连接快照确认：切换前业务连接 chains 包含东京；选择香港并返回成功后，旧东京连接消失；新的 IP 查询连接 chains 包含香港，出口 IP 不再是东京。切换过程允许业务连接进行一次短暂重连，但 sing-box PID、TUN 和系统代理不得重启。

## 回滚

改动限定于 macOS Clash 控制器、macOS VPN 服务、节点选择 UI 和对应测试。若出现回归，可回滚本修复提交；没有用户数据格式迁移，现有 `selected_node` 与配置文件仍兼容。
