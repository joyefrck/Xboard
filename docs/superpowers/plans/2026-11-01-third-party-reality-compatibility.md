# 第三方客户端 REALITY 订阅兼容修复

**Goal:** 恢复 Clash Verge 2.5.8 和 Shadowrocket 2.2.93/3488 的普通 TCP/REALITY/Vision 节点订阅，保持现有订阅链接、套餐权限和服务端协议。

**Architecture:** 将普通混合 KeyShare REALITY 的能力判断与 Encryption/XHTTP 分开。Clash Verge 显式识别品牌；Mihomo 普通 REALITY 最低验证版本为 1.19.32。Shadowrocket 普通 REALITY 最低版本为 2.2.93/3488，并输出其可识别的 `fingerprint=Chrome140`。增强协议维持原有过滤，未知或旧客户端不自动放行。

**Tech Stack:** Laravel/PHP、Mihomo、Xray/V2bX、GitHub Actions。

用户已授权直接实施和发布；后续测试全部在远端执行，本机不再启动网络测试或切换 VPN。使用当前分支，保留生产选择性热更新和所有无关修改。

- [ ] 新增实际订阅流水线回归：普通 REALITY 对指定版本可见；旧版本、未知身份、加密/XHTTP 仍过滤；输出不得包含私钥。
- [ ] 修改客户端身份、能力判断和 Shadowrocket 指纹输出。
- [ ] 在 CI 中用固定 SHA256 的 Mihomo 1.19.32 与 1.19.33 分别完成真实新版 REALITY HTTPS 验证。
- [ ] 通过远端安全/队列/协议门禁、双架构镜像构建和签名。
- [ ] 核对生产旧哈希，备份后仅替换本次目标文件，部署精确镜像并验收。
- [ ] 执行全部 11 个业务队列覆盖、三轮趋势采样、数分钟后新 SSH 会话复查及自然业务检查。
- [ ] 从远端核对真实生产订阅节点数量和字段，记录本机客户端连接验收未执行的边界。
