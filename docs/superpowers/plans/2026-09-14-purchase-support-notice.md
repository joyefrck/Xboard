# 购买前服务支持提示实施计划

**目标：** 用户进入套餐确认页或订单详情页时先阅读全局提示，确认后继续。

**方案：** 在现有 ElephantRoute 页面脚本中挂载独立的原生 dialog。监听路由和页面渲染，覆盖 `/plan/:id` 与 `/order/:id`；同一次连续购买流程不重复，返回其他页面后重置。提示不创建订单、不支付、不执行账号处罚。

**文案：** 如您在购买、支付或节点使用过程中遇到问题，请通过站内工单或官方 Telegram 群联系我们，客服会及时跟进处理。为便于核实和解决问题，请统一通过上述官方渠道反馈，勿通过其他渠道发起投诉或施压。违反此约定的行为一经核实，我们将停止提供服务并停用相关账号。感谢您的理解与配合。

- [x] 在 `theme/ElephantRoute/assets/elephant-route-pages-v2.js` 增加提示生命周期：首次进入显示、确认、返回商店、Esc 返回商店、离开清理、重复挂载保护。
- [x] 在同名 CSS 中增加 Aurora 弹窗与全局背景样式；提供移动端滚动、原生焦点约束和 14px 正文。
- [x] 更新主题 Blade 的 CSS/JS 缓存版本；同步到 `public/theme/ElephantRoute`。
- [x] 增加行为回归测试：订阅入口、订单直达、确认不重复、退出后重入、Esc 取消及监听器清理。
- [x] 运行 `node --test tests/elephant-route-pages-v2.test.js tests/elephant-route-purchase-notice.test.js tests/elephant-route-dashboard-v2.test.js tests/traffic-package-purchase-confirmation.test.js` 和 `git diff --check`。
- [x] 用 Chrome 验证本地页面弹窗、确认后继续、重新进入、移动端排版。

验证说明：38 项相关测试通过。Chrome 在加载实际主题资源的隔离交互页完成桌面和 390×844 手机尺寸验证；本地用户后台未登录，尚未验证真实账号下单链路。未创建订单或支付。
