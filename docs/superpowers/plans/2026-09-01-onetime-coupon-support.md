# 一次性套餐支持优惠券 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 让所有 `onetime` 一次性旧流量包套餐能够使用符合现有限制的优惠券。

**Architecture:** 删除订单服务独有的 `onetime + coupon_code` 拒绝分支，使预校验和实际下单都依赖现有 `CouponService`。不改变一次性流量包到账、流量叠加、独立流量包或前端流程。

**Tech Stack:** PHP 8 / Laravel、Node.js 内置测试运行器、现有源码契约测试。

---

### Task 1: 建立一次性优惠券回归测试

**Files:**
- Modify: `tests/stacked-traffic-package.test.js:95`
- Reference: `app/Services/OrderService.php:47-98`

- [x] **Step 1: 将现有拒绝断言改为支持断言**

将：

```js
assert.match(orderService, /if \(\$newPeriod === Plan::PERIOD_ONETIME && \$couponCode\) \{[\s\S]{0,120}throw new ApiException/);
```

改为：

```js
assert.doesNotMatch(orderService, /if \(\$newPeriod === Plan::PERIOD_ONETIME && \$couponCode\) \{[\s\S]{0,120}throw new ApiException/);
assert.match(orderService, /if \(\$couponCode\) \{[\s\S]{0,80}\$orderService->applyCoupon\(\$couponCode\);/);
```

第一条约束一次性套餐不再被提前拒绝，第二条约束所有携带优惠码的普通套餐订单（包括 `onetime`）仍进入共享优惠券应用逻辑。

- [x] **Step 2: 运行聚焦测试并确认红灯**

Run:

```bash
node --test tests/stacked-traffic-package.test.js
```

Expected: FAIL；失败位置是新增的 `doesNotMatch`，输出能匹配到 `OrderService` 中现存的 `onetime + coupon_code` 拒绝分支。

### Task 2: 删除一次性优惠券硬拒绝

**Files:**
- Modify: `app/Services/OrderService.php:62-64`
- Test: `tests/stacked-traffic-package.test.js`

- [x] **Step 1: 删除订单创建中的提前拒绝**

从 `OrderService::createFromRequest()` 删除：

```php
if ($newPeriod === Plan::PERIOD_ONETIME && $couponCode) {
    throw new ApiException(__('Coupon failed'));
}
```

保留后续共享逻辑：

```php
if ($couponCode) {
    $orderService->applyCoupon($couponCode);
}
```

- [x] **Step 2: 运行聚焦测试并确认绿灯**

Run:

```bash
node --test tests/stacked-traffic-package.test.js
```

Expected: PASS，所有该文件内测试通过，失败数为 0。

- [x] **Step 3: 检查 PHP 语法**

Run:

```bash
php -l app/Services/OrderService.php
```

Expected: `No syntax errors detected in app/Services/OrderService.php`。

- [x] **Step 4: 运行完整 Node 回归测试**

Run:

```bash
node --test tests/*.test.js
```

Expected: 全部测试通过，失败数为 0；若存在与本改动无关的环境型失败，记录完整失败项并单独报告，不把部分通过描述成全量通过。

- [x] **Step 5: 审计最终差异和范围**

Run:

```bash
git diff --check
git diff -- app/Services/OrderService.php tests/stacked-traffic-package.test.js docs/superpowers/specs/2026-09-01-onetime-coupon-support-design.md docs/superpowers/plans/2026-09-01-onetime-coupon-support.md
git status --short
```

Expected: 无空白错误；业务代码只删除一次性优惠券硬拒绝，测试只更新对应契约；设计和计划文档为新增文件；没有独立流量包、到账、消费或前端改动。

## Git 边界

本计划不包含 commit 或 push。只有用户另行明确授权后才执行 Git 提交或远端操作。
