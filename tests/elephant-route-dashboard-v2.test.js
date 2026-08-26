const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const repoRoot = path.resolve(__dirname, '..');

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

test('Dashboard 2.0 assets are loaded by both theme views and mirrored for production', () => {
  const themeScript = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.js');
  const publicScript = readRepoFile('public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.js');
  const themeStyles = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.css');
  const publicStyles = readRepoFile('public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.css');
  const themeView = readRepoFile('theme/ElephantRoute/dashboard.blade.php');
  const publicView = readRepoFile('public/theme/ElephantRoute/dashboard.blade.php');

  assert.equal(publicScript, themeScript);
  assert.equal(publicStyles, themeStyles);
  assert.equal(publicView, themeView);

  const publicIgnore = readRepoFile('public/theme/.gitignore');
  assert.match(publicIgnore, /!ElephantRoute\/dashboard\.blade\.php/);
  assert.match(publicIgnore, /!ElephantRoute\/assets\/elephant-route-dashboard-v2\.css/);
  assert.match(publicIgnore, /!ElephantRoute\/assets\/elephant-route-dashboard-v2\.js/);
  assert.match(publicIgnore, /!ElephantRoute\/assets\/client-logo\.svg/);

  for (const viewPath of [
    'theme/ElephantRoute/dashboard.blade.php',
    'public/theme/ElephantRoute/dashboard.blade.php'
  ]) {
    const view = readRepoFile(viewPath);
    assert.match(view, /elephant-route-dashboard-v2\.css\?v=\{\{\$version\}\}-er20260826dashboardV2Aurora17/);
    assert.match(view, /elephant-route-dashboard-v2\.js\?v=\{\{\$version\}\}-er20260826dashboardV2Aurora17/);
  }
});

test('Dashboard 2.0 exposes deterministic official artifact and invitation helpers', () => {
  const helpers = require(path.join(repoRoot, 'theme/ElephantRoute/assets/elephant-route-dashboard-v2.js'));
  const payload = {
    platforms: [
      {
        platform: 'android',
        apps: [
          {
            app_key: 'third-party',
            packages: [{ artifact_id: 90, build_number: 99, published_at: 200 }]
          },
          {
            app_key: 'elephant-route-android',
            packages: [
              { artifact_id: 11, build_number: 11, published_at: 300 },
              { artifact_id: 12, build_number: 12, published_at: 100 }
            ]
          }
        ]
      },
      {
        platform: 'windows',
        apps: [{
          app_key: 'elephant-route-desktop',
          packages: [{ artifact_id: 21, build_number: 21, published_at: 400 }]
        }]
      }
    ]
  };

  assert.equal(helpers.selectLatestOfficialArtifact(payload, 'android').artifact_id, 12);
  assert.equal(helpers.selectLatestOfficialArtifact(payload, 'windows').artifact_id, 21);
  assert.equal(helpers.selectLatestOfficialArtifact(payload, 'macos'), null);
  assert.equal(
    helpers.buildInviteLink('https://example.com:7443', 'ABCD1234'),
    'https://example.com:7443/app#/register?code=ABCD1234'
  );
});

test('Mobile drawer is generated from the same Aurora navigation contract as desktop', () => {
  const helpers = require(path.join(repoRoot, 'theme/ElephantRoute/assets/elephant-route-dashboard-v2.js'));
  const script = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.js');
  const styles = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.css');

  assert.deepEqual(
    helpers.getNavigationItems().map(({ route, label }) => ({ route, label })),
    [
      { route: '#/dashboard', label: '仪表盘' },
      { route: '#/plan', label: '商店' },
      { route: '#/invite', label: '邀请赚钱' },
      { route: '#/knowledge', label: '操作说明' },
      { route: '#/ticket', label: '问题申诉' }
    ]
  );
  assert.match(script, /function ensureMobileDrawer\(\)/);
  assert.match(script, /drawer\.querySelector\('\.n-menu\.side-menu'\)/);
  assert.match(script, /nativeMenu\.dataset\.erV2MobileNativeHidden = 'true'/);
  assert.match(script, /NAV_ITEMS\.forEach\(function \(item\)/);
  assert.match(script, /logo\.src = '\/theme\/ElephantRoute\/assets\/client-logo\.svg'/);
  assert.match(styles, /\.n-drawer\.er-v2-mobile-drawer/);
  assert.match(styles, /\.er-v2-mobile-sidebar-link\.is-active/);
  assert.match(styles, /\.er-v2-mobile-sidebar-appeal \{[\s\S]*margin-bottom: 72px/);
});

test('Mobile dashboard locks horizontal movement while preserving vertical scrolling', () => {
  const styles = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.css');

  assert.match(styles, /\.er-v2-dashboard \{[\s\S]*overflow-x: hidden;[\s\S]*overflow-y: auto/);
  assert.match(styles, /@media \(max-width: 767px\) \{[\s\S]*body,[\s\S]*#app \{[\s\S]*overflow-x: hidden !important/);
  assert.match(styles, /touch-action: pan-y pinch-zoom/);
  assert.match(styles, /\.er-v2-dashboard \{[\s\S]*overflow-x: hidden !important;[\s\S]*overflow-y: auto !important/);
});

test('Dashboard 2.0 selects the latest three announcements deterministically', () => {
  const helpers = require(path.join(repoRoot, 'theme/ElephantRoute/assets/elephant-route-dashboard-v2.js'));
  const selected = helpers.selectLatestNotices([
    { id: 1, title: '较早', created_at: 100 },
    { id: 4, title: '最新', created_at: 400 },
    { id: 2, title: '第三', created_at: 200 },
    { id: 3, title: '第二', created_at: 300 }
  ]);

  assert.deepEqual(selected.map((notice) => notice.id), [4, 3, 2]);
});

test('Dashboard 2.0 detects only pending and processing orders as unfinished', () => {
  const helpers = require(path.join(repoRoot, 'theme/ElephantRoute/assets/elephant-route-dashboard-v2.js'));

  assert.equal(helpers.hasUnfinishedOrders([{ status: 0 }]), true);
  assert.equal(helpers.hasUnfinishedOrders([{ status: 1 }]), true);
  assert.equal(helpers.hasUnfinishedOrders([{ status: '0' }]), true);
  assert.equal(helpers.hasUnfinishedOrders([{ status: 2 }, { status: 3 }, { status: 4 }]), false);
  assert.equal(helpers.hasUnfinishedOrders([]), false);
  assert.equal(helpers.hasUnfinishedOrders(null), false);
});

test('Dashboard 2.0 formats the effective invitation commission from invite stats', () => {
  const helpers = require(path.join(repoRoot, 'theme/ElephantRoute/assets/elephant-route-dashboard-v2.js'));

  assert.equal(helpers.buildInviteCommissionLabel({ stat: [0, 0, 0, 10] }), '佣金 10%');
  assert.equal(helpers.buildInviteCommissionLabel({ stat: [0, 0, 0, 18] }), '佣金 18%');
  assert.equal(helpers.buildInviteCommissionLabel({ stat: [0, 0, 0, 0] }), '佣金 0%');
  assert.equal(helpers.buildInviteCommissionLabel({ stat: [] }), '');
  assert.equal(helpers.buildInviteCommissionLabel(null), '');
});

test('Dashboard 2.0 subscription view model keeps plan and traffic-package usage independent', () => {
  const helpers = require(path.join(repoRoot, 'theme/ElephantRoute/assets/elephant-route-dashboard-v2.js'));
  const now = 1_800_000_000;

  const active = helpers.buildSubscriptionViewModel({
    plan: { name: '大象 Pro' },
    has_active_plan: true,
    plan_transfer_enable: 400,
    plan_used_traffic: 100,
    traffic_package_total: 200,
    traffic_package_remaining: 150,
    expired_at: now + 86400,
    next_reset_at: now + 43200
  }, now);
  assert.equal(active.name, '大象 Pro');
  assert.equal(active.status, '使用中');
  assert.equal(active.planTotal, 400);
  assert.equal(active.planUsed, 100);
  assert.equal(active.planProgress, 25);
  assert.equal(active.packageTotal, 200);
  assert.equal(active.packageUsed, 50);
  assert.equal(active.packageProgress, 25);
  assert.match(active.resetLabel, /^\d{4}-\d{2}-\d{2}$/);
  assert.equal(active.trafficNote, '优先使用套餐流量，用完后继续使用流量包余额。套餐重置时间不受影响。');

  const permanent = helpers.buildSubscriptionViewModel({
    plan: { name: '长期套餐' },
    has_active_plan: true,
    plan_transfer_enable: 0,
    plan_used_traffic: 0,
    expired_at: null
  }, now);
  assert.equal(permanent.expiryLabel, '长期有效');
  assert.equal(permanent.hasTrafficPackage, false);
  assert.equal(permanent.trafficNote, '');

  const packageOnly = helpers.buildSubscriptionViewModel({
    has_active_plan: false,
    active_product_type: 'traffic_package',
    latest_traffic_package: { name: '100G 流量包' },
    traffic_package_total: 100,
    traffic_package_remaining: 80
  }, now);
  assert.equal(packageOnly.status, '使用中');
  assert.equal(packageOnly.planTotal, 0);
  assert.equal(packageOnly.packageUsed, 20);
  assert.equal(packageOnly.packageProgress, 20);
  assert.equal(packageOnly.trafficNote, '当前套餐已到期，仍可继续使用流量包余额。续费后套餐重置时间独立计算。');

  const expired = helpers.buildSubscriptionViewModel({
    plan: { name: '旧套餐' },
    has_active_plan: false,
    expired_at: now - 1
  }, now);
  assert.equal(expired.status, '已过期');

  const empty = helpers.buildSubscriptionViewModel({}, now);
  assert.equal(empty.status, '未订阅');
  assert.equal(empty.name, '暂无可用套餐');

  const abnormal = helpers.buildSubscriptionViewModel({
    has_active_plan: true,
    plan_transfer_enable: 100,
    plan_used_traffic: 500,
    traffic_package_total: 100,
    traffic_package_remaining: 200
  }, now);
  assert.equal(abnormal.planProgress, 100);
  assert.equal(abnormal.packageUsed, 0);
  assert.equal(abnormal.packageProgress, 0);
});

test('Dashboard 2.0 traffic-package CTA follows the purchasable package list', () => {
  const helpers = require(path.join(repoRoot, 'theme/ElephantRoute/assets/elephant-route-dashboard-v2.js'));

  assert.equal(helpers.hasPurchasableTrafficPackages([{ id: 1 }]), true);
  assert.equal(helpers.hasPurchasableTrafficPackages({ data: [{ id: 2 }] }), true);
  assert.equal(helpers.hasPurchasableTrafficPackages({ items: [{ id: 3 }] }), true);
  assert.equal(helpers.hasPurchasableTrafficPackages([]), false);
  assert.equal(helpers.hasPurchasableTrafficPackages(null), false);
});

test('Dashboard 2.0 renders split traffic fields, independent progress bars, and the legacy purchase CTA', () => {
  const script = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.js');
  const styles = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.css');

  for (const field of [
    'plan_used_traffic',
    'plan_transfer_enable',
    'next_reset_at',
    'effective_expired_at',
    'traffic_package_total',
    'traffic_package_remaining'
  ]) {
    assert.match(script, new RegExp(field));
  }

  assert.match(script, /data-field="plan-used"/);
  assert.match(script, /data-field="package-used"/);
  assert.match(script, /data-progress="plan"/);
  assert.match(script, /data-progress="package"/);
  assert.doesNotMatch(script, /er-v2-traffic-ring/);
  assert.doesNotMatch(script, /data-action="copy-subscription"/);
  assert.doesNotMatch(script, /data-action="renew"/);
  assert.match(script, /流量不够用？/);
  assert.match(script, /立即购买流量包/);
  assert.match(script, /优先使用套餐流量，用完后继续使用流量包余额。套餐重置时间不受影响。/);
  assert.match(script, /function focusTrafficPackagePurchasePage/);
  assert.match(script, /findTextElementOutsideV2\(\['按流量'\]\)/);
  assert.match(script, /scrollIntoView\(\{ behavior: 'smooth', block: 'start' \}\)/);

  assert.match(styles, /\.er-v2-traffic-progress-plan > span \{[\s\S]*var\(--er-v2-cyan\)[\s\S]*var\(--er-v2-blue\)/);
  assert.match(styles, /\.er-v2-traffic-progress-package > span \{[\s\S]*#fb6a62[\s\S]*#ed343f/);
  assert.doesNotMatch(styles, /\.er-v2-traffic-ring/);
  assert.match(styles, /\.er-v2-traffic-metrics span \{[\s\S]*font-size: 14px/);
  assert.match(styles, /\.er-v2-traffic-metrics strong \{[\s\S]*font-size: 16px/);
  assert.match(styles, /\.er-v2-traffic-cta button \{[\s\S]*font-size: 14px/);
  assert.match(styles, /\.er-v2-subscription-footer \{[\s\S]*margin-top: auto/);
  assert.match(styles, /grid-template-rows: 250px 148px 208px/);
});

test('Dashboard 2.0 Telegram view model covers disabled, unbound, bound, and missing group states', () => {
  const helpers = require(path.join(repoRoot, 'theme/ElephantRoute/assets/elephant-route-dashboard-v2.js'));

  const unavailable = helpers.buildTelegramViewModel(null, {});
  assert.equal(unavailable.bindVisible, false);
  assert.equal(unavailable.groupVisible, false);

  const disabled = helpers.buildTelegramViewModel({ is_telegram: 0, telegram_discuss_link: 'https://t.me/example' }, {});
  assert.equal(disabled.bindVisible, false);
  assert.equal(disabled.groupVisible, true);

  const unbound = helpers.buildTelegramViewModel({ is_telegram: 1 }, {});
  assert.equal(unbound.bindVisible, true);
  assert.equal(unbound.bindDisabled, false);
  assert.equal(unbound.groupVisible, false);

  const bound = helpers.buildTelegramViewModel({ is_telegram: 1, telegram_discuss_link: 'https://t.me/example' }, { telegram_id: 123 });
  assert.equal(bound.bindDisabled, true);
  assert.equal(bound.bindLabel, '已绑定');
  assert.equal(bound.groupVisible, true);
});

test('Dashboard 2.0 source contains the agreed navigation, data, modal, and fallback contracts', () => {
  const script = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.js');

  for (const endpoint of [
    '/api/v1/user/info',
    '/api/v1/user/getSubscribe',
    '/api/v1/user/traffic-package/fetch',
    '/api/v1/user/notice/fetch',
    '/api/v1/user/server/fetch',
    '/api/v1/user/comm/config',
    '/api/v1/user/invite/fetch',
    '/api/v1/user/order/fetch',
    '/api/v1/user/support/dify-context',
    '/api/v1/app-downloads'
  ]) {
    assert.match(script, new RegExp(endpoint.replaceAll('/', '\\/')));
  }

  assert.match(script, /Promise\.allSettled/);
  assert.match(script, /THEME_COLORS = \{[\s\S]*marsgreen: '#018B8D'/);
  assert.match(script, /--er-v2-accent/);
  assert.match(script, /KARING_APP_STORE_URL = 'https:\/\/apps\.apple\.com\/us\/app\/karing\/id6472431552'/);
  assert.match(script, /OFFICIAL_APP_KEYS = \{\s*android: 'elephant-route-android',\s*windows: 'elephant-route-desktop',\s*macos: 'elephant-route-desktop'/);
  assert.match(script, /\/api\/v1\/user\/app-downloads\/.*\/prepare/);
  assert.match(script, /VUE_NAIVE_ACCESS_TOKEN/);
  assert.match(script, /turnstile\.render/);
  assert.match(script, /navigator\.clipboard\.writeText/);
  assert.match(script, /document\.execCommand\('copy'\)/);
  assert.match(script, /\/api\/v1\/user\/invite\/save/);
  assert.match(script, /\/bind /);
  assert.match(script, /open_ai_support=1/);
  assert.match(script, /if \(state\.data\.dify\)[\s\S]*open_ai_support=1[\s\S]*navigate\('#\/ticket'\)/);

  for (const route of ['#/dashboard', '#/plan', '#/invite', '#/knowledge', '#/order', '#/ticket', '#/profile']) {
    assert.match(script, new RegExp(route.replace('/', '\\/')));
  }

  assert.match(script, /data-action="orders"/);
  assert.match(script, />订单记录<\/span>/);
  assert.match(script, /data-field="order-status"[^>]*hidden>有未完成订单<\/strong>/);
  assert.match(script, /renderOrders\(\)/);
  assert.match(script, /status=0/);
  assert.match(script, /status=1/);
});

test('Dashboard 2.0 styles preserve viewport density, light mode, responsiveness, and reduced motion', () => {
  const styles = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.css');
  const view = readRepoFile('theme/ElephantRoute/dashboard.blade.php');

  assert.match(styles, /--er-v2-primary/);
  assert.doesNotMatch(styles, /body\.dark|body\.dark-theme|\.dark #er-dashboard-v2|\.dark \.er-v2/);
  assert.match(styles, /--er-v2-font-title: 15px/);
  assert.match(styles, /--er-v2-font-body: 14px/);
  assert.match(styles, /--er-v2-font-meta: 10px/);
  assert.match(styles, /#er-dashboard-v2 \{[\s\S]*font-size: var\(--er-v2-font-body\)/);
  assert.match(styles, /header > div\[ml-auto\]\[flex\]\[items-center\] > i\.n-icon\.mr-5\.cursor-pointer:first-child/);
  assert.match(view, /localStorage\.setItem\('vueuse-color-scheme', 'light'\)/);
  assert.match(styles, /calc\(100dvh - var\(--er-v2-shell-top/);
  assert.match(styles, /grid-template-columns: minmax\(0, 2fr\) minmax\(240px, \.82fr\)/);
  assert.match(styles, /grid-template-columns: repeat\(4, minmax\(0, 1fr\)\)/);
  assert.match(styles, /@media \(max-width: 1279px\)/);
  assert.match(styles, /@media \(max-width: 767px\)/);
  assert.match(styles, /@media \(prefers-reduced-motion: reduce\)/);
  assert.doesNotMatch(styles, /\.er-v2-sidebar-watermark/);
  assert.match(styles, /\.er-v2-sidebar-appeal/);
  assert.match(styles, /:focus-visible/);
  assert.match(styles, /\.er-v2-dashboard \[hidden\] \{[\s\S]*display: none !important/);
});

test('Dashboard 2.0 download section has one title and keeps its platform row clear of the bottom edge', () => {
  const script = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.js');
  const styles = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.css');

  assert.match(script, /<h2 id="er-v2-download-title">软件下载<\/h2>/);
  assert.doesNotMatch(script, /<h2 id="er-v2-download-title">客户端下载<\/h2>/);
  assert.doesNotMatch(script, /<p class="er-v2-kicker">软件下载<\/p><h2 id="er-v2-download-title">/);
  assert.match(styles, /\.er-v2-download-heading \{[\s\S]*margin-bottom: 8px/);
});

test('Dashboard 2.0 bottom panels use one heading line and balanced action typography', () => {
  const script = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.js');
  const styles = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.css');

  for (const removedKicker of ['服务动态', '常用操作', '推广返利']) {
    assert.doesNotMatch(script, new RegExp('<p class="er-v2-kicker">' + removedKicker + '<\\/p>'));
  }
  assert.doesNotMatch(script, />官方群公告</);
  for (const heading of ['官方公告', '快捷入口', '邀请好友赚钱']) {
    assert.match(script, new RegExp('>' + heading + '<'));
  }

  assert.match(script, /class="er-v2-shortcut-label">节点状态/);
  assert.match(styles, /\.er-v2-shortcut-label \{[\s\S]*font-size: 14px/);
  assert.match(styles, /#er-dashboard-v2 \.er-v2-copy-button \{[\s\S]*font-size: 11px/);
  assert.match(styles, /\.er-v2-metric span \{[\s\S]*font-size: 14px/);
});

test('Dashboard 2.0 appeal entry has a solid icon and deliberate middle-lower spacing', () => {
  const script = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.js');
  const styles = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.css');

  assert.match(script, /appeal: '<path/);
  assert.match(script, /createElement\('span', 'er-v2-sidebar-appeal-icon'\)/);
  assert.match(script, /solidIcon\('appeal'\)/);
  assert.match(styles, /\.er-v2-sidebar-appeal \{[\s\S]*margin-top: clamp\(88px, 15vh, 132px\)/);
  assert.match(styles, /\.er-v2-sidebar-appeal-icon/);
});

test('Dashboard 2.0 keeps routine typography at 14px and emphasizes metric values', () => {
  const styles = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.css');

  assert.match(styles, /--er-v2-font-body: 14px/);
  assert.match(styles, /\.er-v2-metric span \{[^}]*font-size: 14px;[^}]*font-weight: 500/);
  assert.match(styles, /\.er-v2-metric strong \{[^}]*font-size: 16px;[^}]*font-weight: 760/);
  assert.match(styles, /\.er-v2-button,[\s\S]*\.er-v2-sidebar-link,[\s\S]*font-size: var\(--er-v2-font-body\)/);
});

test('Dashboard brand uses the transparent client source logo and mirrors it for production', () => {
  const sourceLogo = readRepoFile('clients/elephant-route-deprecated/assets/images/logo.svg');
  const themeLogo = readRepoFile('theme/ElephantRoute/assets/client-logo.svg');
  const publicLogo = readRepoFile('public/theme/ElephantRoute/assets/client-logo.svg');
  const themeView = readRepoFile('theme/ElephantRoute/dashboard.blade.php');
  const brandStyles = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard.css');

  assert.equal(themeLogo, sourceLogo);
  assert.equal(publicLogo, sourceLogo);
  assert.match(themeView, /logo\.src = '\/theme\/ElephantRoute\/assets\/client-logo\.svg'/);
  assert.match(themeView, /elephant-route-dashboard\.css\?v=\{\{\$version\}\}-er20260826clientLogoTypography1/);
  assert.match(brandStyles, /\.er-sidebar-brand-logo \{[\s\S]*background: transparent !important/);
});

test('Legacy dashboard mutation exits when Dashboard 2.0 owns the route', () => {
  const legacy = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard.js');

  assert.match(legacy, /function isDashboardV2Mounted\(\)/);
  assert.match(legacy, /document\.getElementById\('er-dashboard-v2'\)/);
  assert.match(legacy, /if \(isDashboardV2Mounted\(\)\) \{[\s\S]*removeSubscribeActions\(\);[\s\S]*return true;/);
  assert.match(legacy, /if \(!isDashboardV2Mounted\(\)\) \{[\s\S]*removeDownloadEntry\(\);[\s\S]*applySubscribeActions\(\);/);
});

test('Dashboard 2.0 mount discovery excludes sidebar labels and targets the main dashboard scroller', () => {
  const script = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.js');
  const styles = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.css');

  assert.match(script, /closest\('\.n-layout-sider, \.n-menu, #er-v2-sidebar'\)/);
  assert.match(script, /findTextElementOutsideV2\(\['我的订阅'\]\)/);
  assert.match(script, /anchor\.closest\('\.cus-scroll-y, \.n-layout-content, main'\)/);
  assert.match(script, /if \(!source \|\| source\.closest\('\.n-layout-sider, \.n-menu'\)\) return null/);
  assert.match(script, /if \(state\.retryTimer !== null\) return/);
  assert.match(script, /state\.retryTimer = null;[\s\S]*ensureSidebar\(\)/);
  assert.match(styles, /\.er-v2-dashboard \{[\s\S]*height: calc\(100dvh - var\(--er-v2-shell-top\)\)[\s\S]*overflow-y: auto/);
});

test('Dashboard 2.0 moves its greeting into the system topbar and removes the duplicate profile action', () => {
  const script = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.js');
  const styles = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.css');

  assert.match(script, /var TOPBAR_TITLE_ID = 'er-v2-topbar-title'/);
  assert.match(script, /function ensureTopbarGreeting\(\)/);
  assert.match(script, /breadcrumb\.dataset\.erV2TopbarHidden = 'true'/);
  assert.match(script, /function restoreTopbarGreeting\(\)/);
  assert.match(script, /delete breadcrumb\.dataset\.erV2TopbarHidden/);
  assert.match(script, /data-er-v2-topbar-field="greeting"/);
  assert.doesNotMatch(script, /DASHBOARD 2\.0/);
  assert.doesNotMatch(script, /er-v2-topbar-eyebrow/);
  assert.doesNotMatch(script, /class="er-v2-account"/);
  assert.doesNotMatch(script, /<span>个人中心<\/span>/);
  assert.match(styles, /\[data-er-v2-topbar-hidden="true"\][\s\S]*display: none !important/);
  assert.match(styles, /\.er-v2-topbar-title/);
});

test('Dashboard bottom cards use distinct non-interactive announcement and shortcut patterns', () => {
  const script = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.js');
  const styles = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.css');
  const noticePattern = script.match(/noticePattern: '([^']+)'/);
  const shortcutPattern = script.match(/shortcutPattern: '([^']+)'/);

  assert.ok(noticePattern);
  assert.ok(shortcutPattern);
  assert.notEqual(noticePattern[1], shortcutPattern[1]);
  assert.match(script, /class="er-v2-card-watermark er-v2-card-watermark-notice" aria-hidden="true">' \+ solidIcon\('noticePattern'\)/);
  assert.match(script, /class="er-v2-card-watermark er-v2-card-watermark-shortcut" aria-hidden="true">' \+ solidIcon\('shortcutPattern'\)/);
  assert.match(styles, /\.er-v2-card-watermark \{[^}]*pointer-events: none;[^}]*z-index: 0/);
  assert.match(styles, /\.er-v2-card-watermark-notice \{[^}]*color: rgba\(0, 171, 188, 0\.045\)/);
  assert.match(styles, /\.er-v2-card-watermark-shortcut \{[^}]*color: rgba\(103, 82, 224, 0\.04\)/);
  assert.match(styles, /\.er-v2-notices > :not\(\.er-v2-card-watermark\),[\s\S]*\.er-v2-shortcuts > :not\(\.er-v2-card-watermark\) \{[^}]*z-index: 1/);
});

test('Dashboard invitation heading renders a dynamic 14px commission badge', () => {
  const script = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.js');
  const styles = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.css');

  assert.match(script, /class="er-v2-commission-rate" data-field="commission-rate" hidden/);
  assert.match(script, /var commissionLabel = buildInviteCommissionLabel\(invite\)/);
  assert.match(script, /commissionBadge\.hidden = !commissionLabel/);
  assert.doesNotMatch(script, /佣金 10%/);
  assert.match(styles, /\.er-v2-commission-rate \{[^}]*font-size: var\(--er-v2-font-body\)/);
});

test('Dashboard 2.0 uses aurora glass tokens and solid SVG icon tiles without unicode placeholders', () => {
  const script = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.js');
  const styles = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.css');

  assert.match(script, /var SOLID_ICONS = \{/);
  assert.match(script, /function solidIcon\(name, className\)/);
  assert.match(script, /<svg[^>]+aria-hidden="true"/);
  assert.doesNotMatch(script, /icon: '⌂'|icon: '◇'|icon: '↗'|icon: '\?'/);
  assert.doesNotMatch(script, /er-v2-platform-icon">[●▦◆]/);
  assert.match(styles, /--er-v2-page: #edf1f6/);
  assert.match(styles, /backdrop-filter: blur\(24px\) saturate\(140%\)/);
  assert.match(styles, /\.er-v2-platform-icon\.is-android/);
  assert.match(styles, /\.er-v2-platform-icon\.is-windows/);
  assert.match(styles, /\.er-v2-platform-icon\.is-macos/);
  assert.match(styles, /\.er-v2-platform-icon\.is-ios/);
});

test('Dashboard 2.0 fills glass surfaces without white outline seams', () => {
  const styles = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.css');

  assert.match(styles, /--er-v2-glass-line: rgba\(72, 90, 132, 0\.12\)/);
  assert.match(styles, /\.er-v2-panel::after \{[\s\S]*display: none/);
  assert.match(styles, /\.er-v2-metric,[\s\S]*border-color: rgba\(72, 90, 132, 0\.1\);[\s\S]*box-shadow: none/);
  assert.match(styles, /\.er-v2-button-primary \{[\s\S]*border: 0/);
  assert.match(styles, /\.er-v2-sidebar-link\.is-active \{[\s\S]*border-color: rgba\(76, 102, 231, 0\.14\)/);
  assert.doesNotMatch(styles, /\.er-v2-sidebar-link\.is-active \{[^}]*inset/);
  assert.doesNotMatch(styles, /\.er-v2-sidebar-icon,[\s\S]*\.er-v2-telegram-mark \{[^}]*inset/);
});

test('Dashboard announcements render only title and date with a two-line title limit', () => {
  const script = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.js');
  const styles = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.css');

  assert.match(script, /var notices = selectLatestNotices\(state\.data\.notices\)/);
  assert.doesNotMatch(script, /stripMarkup\(notice\.content \|\| ''\)\.slice/);
  assert.match(styles, /\.er-v2-notice-item strong \{[^}]*-webkit-line-clamp: 2;[^}]*white-space: normal/);
});

test('Dashboard modal is portaled to the document body and removed on unmount', () => {
  const script = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.js');

  assert.match(script, /function getModal\(\)/);
  assert.match(script, /document\.body\.appendChild\(modal\)/);
  assert.match(script, /closeModal\(\);[\s\S]*if \(modal\) modal\.remove\(\)/);
  assert.doesNotMatch(script, /state\.root(?: &&)?\.querySelector\('\[data-modal-body\]'\)/);
});

test('Dashboard cards retain bottom safety spacing and decorative sidebar branding is absent', () => {
  const script = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.js');
  const styles = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard-v2.css');

  assert.doesNotMatch(script, /er-v2-sidebar-watermark/);
  assert.doesNotMatch(styles, /er-v2-sidebar::before/);
  assert.doesNotMatch(styles, /background: url\('\/theme\/ElephantRoute\/assets\/logo\.png'\)/);
  assert.doesNotMatch(script, /data-action="invite-page"/);
  assert.match(styles, /\.er-v2-downloads \{[^}]*padding-bottom: 18px/);
  assert.match(styles, /\.er-v2-shortcuts \{[^}]*padding-bottom: 20px/);
});
