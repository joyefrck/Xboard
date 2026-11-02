# iOS REALITY Subscriptions Implementation Plan

> **For agentic workers:** Use inline execution for this approved repair, task by task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** 修复已验证 iOS Stash 与 Quantumult X 的新版 REALITY 订阅，明确报告 Karing 的兼容性限制。

**Architecture:** 保留真实 ClientController → AbstractProtocol → 各客户端渲染器的流程。在能力过滤处按客户端版本和平台判断，在 Stash 渲染器补齐混合握手参数；空节点诊断集中于订阅入口，所有状态仅限当前请求。

**Tech Stack:** PHP 8.2+、Laravel 12、Symfony YAML、隔离 PHP/Node 测试。

---

## Task 1: 重现实际订阅错误

**Files:** Create `tests/ios-reality-subscription.php`.

- [x] 为 Request、协议注册器、ResponseFactory 和流量摘要建立无数据库的真实控制器测试环境。
- [x] 构造三个随机密钥、固定测试 UUID 的节点：传统 REALITY、新版 TCP REALITY、增强 XHTTP/Encryption。
- [x] 添加以下首个失败断言，并运行 `php tests/ios-reality-subscription.php`，确认旧代码未返回新版节点：

```php
$response = iosSubscription('Stash/3.6.5', [$modern, $legacy]);
$parsed = Yaml::parse($response->getContent());
check(array_column($parsed['proxies'], 'name') === ['modern', 'legacy'], 'verified Stash receives modern REALITY');
```

- [x] 覆盖 Quantumult X 四种别名、1.8.1 边界；Karing 1.2.25.2802 的复合 UA、纯新版与混合节点；Stash 指纹/KeyShare 和无兼容节点诊断。

## Task 2: 修复能力与原生输出

**Files:** Modify `app/Services/VlessConfigService.php`, `app/Protocols/Stash.php`, and client context/constructor only where necessary.

- [x] 在普通新版 REALITY 分支添加 TCP 条件的 Stash/Quantumult X 能力判断；增强协议保持原有门禁。
- [x] 在 Stash 的 REALITY 分支加入与统一配置服务一致的指纹及参数：

```php
if (VlessConfigService::needsHybridReality($protocol_settings)) {
    $array['reality-opts']['support-x25519mlkem768'] = true;
}
```

- [x] 以 `VlessConfigService::clientFingerprint($protocol_settings)` 优先于随机指纹，避免新版 REALITY 随订阅刷新随机变化。
- [x] 运行隔离测试，确认 Stash 与 Quantumult X 原生字段完整、旧版本与增强配置仍被过滤。

## Task 3: 身份识别和空节点错误

**Files:** Modify `app/Http/Controllers/V1/Client/ClientController.php`, `app/Support/AbstractProtocol.php`; add a focused identity helper only if it reduces coupling.

- [x] 规范 Quantumult X 别名并保留客户端独立版本，补充实际复合 UA 断言；保留版本化显式 flag 的既有优先级与格式覆盖语义。
- [x] 由协议实例公开输出服务器数量及脱敏过滤统计，不暴露 UUID、token、私钥或完整请求 URL。
- [x] 只在输入原有服务器、能力过滤后为零的场景返回明确错误；无权限、用户指定筛选与渲染器不支持等原因区分记录。
- [x] 断言同一进程后续请求不继承前一请求的错误或计数，且混合订阅中的兼容节点正常返回。

## Task 4: 回归与交付

**Files:** Modify `.github/workflows/security-checks.yml`; add compatibility notes to `docs`.

- [x] 将 `php tests/ios-reality-subscription.php` 加入现有隔离检查。
- [x] 运行 `php tests/vless-config.php`、`php tests/vless-elephant-subscription.php`、`php tests/node-automation-reality.php`。
- [x] 运行 `node --test tests/stash-subscription-compatibility.test.js tests/quantumultx-subscription-compatibility.test.js tests/karing-subscription-compatibility.test.js tests/vless-admin-form.test.js`。
- [x] 运行 `php scripts/check-horizon-provisioning.php --environment=local` 和相关队列测试；对修改 PHP 文件执行 lint 与 `git diff --check`。
- [x] 检查最终 diff 和文档，提交仅包含本次实现、测试和方案的变更。生产发布及原链接真机验收按授权和完整发布门禁执行，不能把现有对照项当作已修复生产链接的证据。


## 执行结果

- 新增 iOS 实际订阅流程测试：171 项检查通过。旧实现先在 Stash 新版节点断言失败，版本后缀边界也先失败再修复。
- VLESS 配置及三方订阅、流量耗尽显示、REALITY 自动检测回归通过；客户端格式与管理表单 Node 测试 14 项、队列及自动化相关 Node 测试 19 项通过。
- Horizon 本地配置检查覆盖 12 个业务队列，无缺失队列或无效 worker lease；PHP lint、工作流 YAML 解析与 diff 检查通过。
- 生产原链接的导入、刷新、HTTPS 访问及负向控制尚未执行。本次交付为本地代码修复，不代表生产部署或 Karing 握手兼容问题已解决。
