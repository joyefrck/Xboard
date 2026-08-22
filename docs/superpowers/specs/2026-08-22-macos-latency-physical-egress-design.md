# macOS 测速物理出口修复设计

## 背景与根因

macOS 在加速已开启时启动独立 sing-box 进程并行测速。当前临时配置继承 `route.auto_detect_interface: true`。主 TUN 已安装分流路由后，临时进程把 `utun` 识别为默认出口，被测节点的底层连接再次进入主核心，形成“临时测速节点 -> 当前已选节点”的双层代理。日志中的东京节点稳定显示 108–121ms，而物理默认路由仍指向 `en0`，测速目标路由则指向 `utun7`，与接近两倍的现象一致。

## 目标

- macOS 独立测速核心的底层连接只经过被测节点一次，口径与 Android 同核心 worker 一致。
- 保留两个连续 HTTP 204 请求、连接复用、取较小成功值、四 worker 并发和现有失败回退机制。
- 不使用除二、比例系数或 UI 显示补偿。
- macOS 应用版本继续使用 1.6.5（build 10605）。

## 方案比较

### 方案 A：绑定系统默认物理接口（采用）

启动临时测速核心前，通过 `/sbin/route -n get -inet default` 读取默认路由的 `interface`。将接口传给临时配置构建器，写入：

```json
{
  "route": {
    "auto_detect_interface": false,
    "default_interface": "en0"
  }
}
```

接口名按设备实时获取，不硬编码 `en0`。这样 Wi-Fi、有线网卡或 USB 网卡切换后，下一次测速会使用新的默认物理接口。

### 方案 B：把测速 worker 注入主核心（不采用）

主核心的底层 socket 已正确绕过 TUN，但运行中重建配置会扩大节点切换、连接迁移和断开流程的风险，也会让测速生命周期与主连接耦合。

### 方案 C：按比例缩小显示值（不采用）

网络拓扑和节点协议不同，不存在可靠的固定倍率。该方案会伪造结果，且不能解决双层代理消耗。

## 组件与数据流

1. `MacosPhysicalInterfaceResolver` 单独负责执行并解析默认路由命令；只接受非空、非 `utun`、格式合法的接口名。
2. `MacosLatencySession` 启动时解析物理接口；解析失败时抛出受控异常，不启动可能双层代理的独立核心。`MacosVpnService` 已有的异常路径随后交给 live-core fallback，避免返回失真值。
3. `MacosLatencySession` 把已确认的 `defaultInterface` 传入 `MacosLatencyConfigBuilder`。
4. `MacosLatencyConfigBuilder` 覆盖临时配置的接口自动检测，并设置物理默认接口。
5. curl 测速仍通过临时核心的四个 loopback mixed inbounds，连续请求同一 HTTP 204 地址两次并取较小成功值。

## 错误处理

- 路由命令不存在、超时、退出码非 0、输出缺少接口、接口为 `utun*` 或名称非法时，返回“物理接口不可用”。
- 物理接口不可用时记录不含地址和订阅信息的警告，并使用现有主核心 Clash API 回退。
- 配置校验失败、临时核心启动失败和节点探测失败继续沿用现有错误分类与逐节点回退。

## 测试与验收

- 单元测试覆盖默认路由输出解析、空输出、`utun` 拒绝、非法名称拒绝和命令失败。
- 配置测试断言 `auto_detect_interface` 被关闭，`default_interface` 被写入，并且其他测速路由规则不变。
- Session 测试断言物理接口能传递到临时核心，解析失败时不创建临时核心；Service 现有回退测试继续覆盖 isolated session 不可用后的 fallback。
- 运行 Flutter 分析、相关测试和完整测试。
- 构建 macOS 1.6.5 arm64 DMG，并验证架构、版本、签名、挂载和 SHA-256。
- 在已连接 TUN 的真机环境再次测速，确认临时配置绑定物理接口，东京等节点不再稳定呈现双层代理倍率。
