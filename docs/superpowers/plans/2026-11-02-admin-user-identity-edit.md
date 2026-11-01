# 用户编辑身份字段保护实施计划

**目标：** 防止后台用户编辑抽屉的邮箱、邀请人和密码被默认填入或误保存。

**实现：** 延续仓库已有的编译后 React 管理端资源维护方式。每个身份字段使用独立的启用状态，前端提交双重过滤，后端正确处理邀请人字段的省略与清空。

1. 新增 `tests/admin-user-identity-edit.test.js`，隔离运行真实抽屉组件，覆盖默认锁定、独立解锁/取消、密码可见切换、重新打开/换用户和提交过滤。先运行并确认旧代码失败。
2. 新增 `tests/admin-user-identity-edit.php`，使用内存 SQLite 调用真实控制器，验证普通保存保留邮箱/密码/邀请关系、显式更改或清空邀请人。以 `php tests/admin-user-identity-edit.php` 运行并确认旧代码失败。
3. 修改 `public/assets/admin/assets/index.js` 的 `Ji` 抽屉和就近的字段组件；同步三个 locale 的按钮标签。修改 `UserUpdate.php` 的邀请人验证及 `UserController.php` 的邀请人条件更新。
4. 运行 `php scripts/version-admin-assets.php` 生成一致的资源版本。
5. 运行新增测试、现有用户/流量包/私人套餐/群资格测试、管理端缓存检查、PHP/JS 语法检查和 `git diff --check`。本地浏览器模拟后台 API，检查桌面/窄屏和密码眼睛，不提交真实业务请求。
6. 用户已在本地验证后授权发布生产：提交并推送当前分支，等待对应 CI 和镜像成功，备份并按已核实的生产挂载范围部署；完成队列三轮趋势、新 SSH 会话复查和公网验收，保留其他未跟踪文件及生产业务数据。
