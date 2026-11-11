# Account session security implementation plan

执行方式：当前会话内顺序实施，不创建子代理、不提交不相关改动、不自动部署生产。

**Goal:** 实现已确认的个人中心会话管理和邮箱验证可信 IP 改密规则。

**Architecture:** Sanctum 令牌增加展示元数据，SessionSecurityService 统一会话输出、来源规范化与撤销；PasswordSecurityService 管理挑战、30 天可信 IP 和密码更新。所有令牌签发、密码更新和会话撤销使用用户行锁。独立主题脚本增强现有个人中心，保持 theme/public 一致。

**Tech Stack:** Laravel 12 / Sanctum 4、数据库事务、Redis Cache locks、既有 SendEmailJob、原生 JavaScript/CSS、SQLite 隔离测试、Playwright 本地合成账号验收。

## Task 1: 会话与改密后端

- [x] 新增迁移，为 personal_access_tokens 增加登录／活动来源和客户端元数据，新增 v2_user_trusted_ips。
- [x] 新建 app/Services/Auth/SessionSecurityService.php：安全字段输出、仅本人撤销、客户端标签和 IP 规范化。
- [x] 新建 app/Services/Auth/PasswordSecurityService.php：原密码验证、验证码作用域、限流、一次性消费、可信 IP 过期及事务改密。
- [x] 更新 AuthService、LoginService、UserController、User 中间件与路由；后台改密同步撤销会话。
- [x] 新建 tests/account-session-security.php，运行 `php tests/account-session-security.php`。覆盖跨账号、过期、旧令牌、旧登录快照、验证码错误与重放、邮箱变化、来源变化及可信 IP 到期。

## Task 2: 日志脱敏

- [x] 新建认证数据脱敏工具，更新数据库日志器及异常 dontFlash 字段。
- [x] 在隔离测试中用固定假密码／验证码／令牌验证嵌套数据、上下文和 URI 不泄露秘密。

## Task 3: 个人中心

- [x] 新建 elephant-route-security.js/css；只在个人中心挂载设备卡片，拦截现有改密表单并使用后端验证响应打开邮箱弹窗。
- [x] 更新两个主题 Blade 的新资产版本；新增字段全部转义，弹窗保留焦点／键盘操作和移动端尺寸。
- [x] 扩展 tests/profile-preview.cjs，提供仅内存会话和验证码 API，不访问生产。
- [x] 新建 Node/jsdom 行为测试，验证列表、退出、空态、验证弹窗与路由卸载。

## Task 4: 验证与交付

- [x] 运行后端隔离测试、相关账号回归测试、主题测试和 `git diff --check`。
- [x] 本地真实浏览器检查桌面／手机布局和改密流程，验证退出单条、全部及当前会话的最终结果。
- [x] 复核并发、日志、代理信任边界及旧会话兼容性；记录实际结果和未验证项。
- [x] 交付变更清单和本地验收证据。生产发布另按 xboard-release-checks 完整执行，不把本地完成报告成已上线。
