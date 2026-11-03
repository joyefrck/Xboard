# Passwall REALITY 订阅修复

用户已确认保守方案：恢复 Passwall / Passwall2 的普通 TCP REALITY 订阅；Encryption、XHTTP 及其他未验证组合继续过滤。

本地实际 ClientController 链路复现：同一混合 REALITY 节点，`passwall`、`passwall2` 及版本化 UA 返回 422，而 `v2rayNG/2.2.2` 返回 200。官方两套 `subscribe.lua` 已解析标准 VLESS URI 的 REALITY、Chrome 指纹、密钥及 Vision 参数。

仅对以 Passwall / Passwall2 开头的原生 UA 放行 General 格式下无 HTTP 伪装的 TCP REALITY。不将插件版本当成内核版本；兼容无版本 UA。格式参数、复合 UA 中的兼容标识不能独立赋予此能力。保留标准 URI 的认证字段和 Chrome 指纹，禁止输出服务器私钥。

验证分两层：实际订阅控制器回归覆盖 UA、格式、过滤、IPv6、重复刷新和参数；固定官方源码中的 Lua processData 验证导入。路由器固件、持久化 UCI、透明代理和实际连接不在离线验证范围；旧 Xray 或其他内核仍可能无法完成混合 REALITY 握手。本次无生产发布。


验证结果：新增控制器回归 184 项通过，原代码上确认失败；官方 Lua 5.1 导入检查 64 项字段通过。既有 VLESS、Elephant、iOS、v2rayNG 和前端配置回归通过。PHP 回归已纳入 CI。

离线 Lua 复验：

```sh
python3 tests/passwall-reality-import.py /tmp/lua-5.1.5/src/lua output/passwall-reality-20261103/passwall-subscribe.lua output/passwall-reality-20261103/passwall2-subscribe.lua
```

来源快照（2026-11-03 下载，SHA256 在测试脚本中固定；未修改上游解析函数，仅替代 OpenWrt 环境辅助函数）：

- https://raw.githubusercontent.com/Openwrt-Passwall/openwrt-passwall/main/luci-app-passwall/root/usr/share/passwall/subscribe.lua
- https://raw.githubusercontent.com/Openwrt-Passwall/openwrt-passwall2/main/luci-app-passwall2/root/usr/share/passwall2/subscribe.lua

Lua 验证固定使用 Xray 类型，不能据此证明用户选用的 sing-box 或旧 Xray 可连接。Passwall2 自定义 curl UA 也不自动获得放行能力，应使用原生 Passwall2 UA。
