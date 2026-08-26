(function (global) {
  'use strict';

  var ROOT_ID = 'er-dashboard-v2';
  var SIDEBAR_ID = 'er-v2-sidebar';
  var MOBILE_SIDEBAR_ID = 'er-v2-mobile-sidebar';
  var TOPBAR_TITLE_ID = 'er-v2-topbar-title';
  var ACCESS_TOKEN_STORAGE_KEY = 'VUE_NAIVE_ACCESS_TOKEN';
  var DOWNLOAD_PAGE_URL = '/download/index.html';
  var KARING_APP_STORE_URL = 'https://apps.apple.com/us/app/karing/id6472431552';
  var TURNSTILE_SCRIPT_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
  var OFFICIAL_APP_KEYS = {
    android: 'elephant-route-android',
    windows: 'elephant-route-desktop',
    macos: 'elephant-route-desktop'
  };
  var SOLID_ICONS = {
    dashboard: '<path d="M12 2.7 2.5 10.5l1.9 2.3L6 11.5V21h5v-6h2v6h5v-9.5l1.6 1.3 1.9-2.3L12 2.7Z"/>',
    store: '<path d="M7 2h10l1 4h4v3l-2 13H4L2 9V6h4l1-4Zm2.5 4h5L14 4h-4l-.5 2ZM6.7 19h10.6L19 9H5l1.7 10Z"/>',
    invite: '<path d="M12 2 5.5 8.5l2.2 2.2L10.5 8v8.5h3V8l2.8 2.7 2.2-2.2L12 2ZM4 14v8h16v-8h-3v5H7v-5H4Z"/>',
    help: '<path fill-rule="evenodd" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 16.7a1.35 1.35 0 1 1 0-2.7 1.35 1.35 0 0 1 0 2.7Zm1.3-5.3v.9h-2.7v-1.8c0-.8.5-1.4 1.4-1.9.9-.5 1.4-1 1.4-1.8 0-.9-.6-1.5-1.6-1.5-1.1 0-1.8.7-2 1.9l-2.6-.6C7.6 6 9.5 4.5 12 4.5c2.7 0 4.6 1.6 4.6 4 0 1.8-1 3-2.7 4-.4.3-.6.5-.6.9Z"/>',
    telegram: '<path d="M21.6 2.4 2.8 9.6c-1.3.5-1.3 1.3-.2 1.7l4.8 1.5 1.8 5.5c.2.7.1 1 .8 1 .5 0 .7-.2 1-.5l2.3-2.2 4.8 3.5c.9.5 1.5.3 1.7-.8L23 4c.3-1.4-.5-2-1.4-1.6ZM9.3 12.5 18.7 6c.5-.3.9-.1.6.2l-7.7 7-.3 3.5-2-4.2Z"/>',
    android: '<path d="M7.4 7 5.7 4.1l1-.6 1.8 3.1A10 10 0 0 1 12 6c1.2 0 2.4.2 3.5.6l1.8-3.1 1 .6L16.6 7A7 7 0 0 1 20 12H4a7 7 0 0 1 3.4-5ZM8 10.5a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm8 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2ZM4 13h16v7a2 2 0 0 1-2 2h-1v-5h-2v5H9v-5H7v5H6a2 2 0 0 1-2-2v-7Z"/>',
    windows: '<path d="M2 4.5 11 3v8H2V4.5Zm11-1.8L22 1v10h-9V2.7ZM2 13h9v8l-9-1.5V13Zm11 0h9v10l-9-1.7V13Z"/>',
    macos: '<path d="M17.1 12.7c0-2.4 2-3.6 2.1-3.6a4.6 4.6 0 0 0-3.6-1.9c-1.5-.2-3 1-3.8 1-.8 0-2-1-3.3-1-1.7 0-3.3 1-4.2 2.5-1.8 3-.5 7.5 1.2 10 1 1.2 2 2.6 3.3 2.5 1.3 0 1.8-.8 3.4-.8s2 .8 3.4.8c1.4 0 2.3-1.2 3.1-2.5 1-1.4 1.4-2.8 1.4-2.9-.1 0-3-1-3-4.1ZM14.6 5.6A4.5 4.5 0 0 0 15.7 2a4.6 4.6 0 0 0-3 1.5 4.3 4.3 0 0 0-1 3.4 3.8 3.8 0 0 0 2.9-1.3Z"/>',
    ios: '<path fill-rule="evenodd" d="M8 5a7 7 0 0 0 0 14h3v-3H8a4 4 0 1 1 0-8h3V5H8Zm8 0h-3v3h3a4 4 0 1 1 0 8h-3v3h3a7 7 0 1 0 0-14Zm-8.5 5.5h9v3h-9v-3Z"/>',
    download: '<path d="M10.5 3h3v10.1l3.2-3.2 2.1 2.2-6.8 6.8-6.8-6.8 2.1-2.2 3.2 3.2V3ZM4 19h16v3H4v-3Z"/>',
    external: '<path d="M13 3h8v8h-3V8.1l-7.4 7.4-2.1-2.1L15.9 6H13V3ZM4 5h6v3H7v9h9v-3h3v6H4V5Z"/>',
    signal: '<path fill-rule="evenodd" d="M12 2a10 10 0 0 0-3 19.5v-3.2a7 7 0 1 1 6 0v3.2A10 10 0 0 0 12 2Zm0 7a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z"/>',
    contact: '<path d="M19 3H5a3 3 0 0 0-3 3v9a3 3 0 0 0 3 3h3l4 4 4-4h3a3 3 0 0 0 3-3V6a3 3 0 0 0-3-3ZM9 12H6V9h3v3Zm4.5 0h-3V9h3v3Zm4.5 0h-3V9h3v3Z"/>',
    knowledge: '<path d="M5 2h13a2 2 0 0 1 2 2v17H7a3 3 0 0 1-3-3V3a1 1 0 0 1 1-1Zm2 3v11.2c.3-.1.7-.2 1-.2h9V5H7Zm1 14h9v-1H8a1 1 0 1 0 0 2h9v-1H8Z"/>',
    orders: '<path d="M5 2h14a2 2 0 0 1 2 2v18l-3-1.8-3 1.8-3-1.8L9 22l-3-1.8L3 22V4a2 2 0 0 1 2-2Zm2 5v2h10V7H7Zm0 4v2h10v-2H7Zm0 4v2h7v-2H7Z"/>',
    appeal: '<path fill-rule="evenodd" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm-1.4 5h2.8v6.7h-2.8V7Zm1.4 11a1.55 1.55 0 1 1 0-3.1 1.55 1.55 0 0 1 0 3.1Z"/>',
    noticePattern: '<path d="M2.5 9.5h4v5h-4v-5Zm6.2-3.2a8.1 8.1 0 0 1 8.1 8.1h-3.1a5 5 0 0 0-5-5V6.3Zm0-4.3A12.4 12.4 0 0 1 21 14.4h-3.1A9.3 9.3 0 0 0 8.7 5.1V2Zm0 9.1a3.3 3.3 0 0 1 3.3 3.3H8.7v-3.3Z"/>',
    shortcutPattern: '<path d="M3 3h7v7H3V3Zm11 0h7v7h-7V3ZM3 14h7v7H3v-7Zm11 0h7v7h-7v-7Zm-4-8h4v2h-4V6Zm-4 4h2v4H6v-4Zm10 0h2v4h-2v-4Zm-6 6h4v2h-4v-2Z"/>',
    arrowRight: '<path d="m13.2 4 8 8-8 8-2.1-2.1 4.4-4.4H3v-3h12.5l-4.4-4.4L13.2 4Z"/>'
  };

  function solidIcon(name, className) {
    return '<svg class="er-v2-solid-icon ' + (className || '') + '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + (SOLID_ICONS[name] || SOLID_ICONS.dashboard) + '</svg>';
  }
  var THEME_COLORS = {
    default: '#316C72',
    blue: '#0665d0',
    black: '#343a40',
    darkblue: '#004175',
    titianred: '#D34947',
    kleinblue: '#002FA7',
    chinared: '#C8161D',
    hermesorange: '#EB5C20',
    marsgreen: '#018B8D'
  };
  var ENDPOINTS = {
    user: '/api/v1/user/info',
    subscribe: '/api/v1/user/getSubscribe',
    trafficPackages: '/api/v1/user/traffic-package/fetch',
    notices: '/api/v1/user/notice/fetch',
    servers: '/api/v1/user/server/fetch',
    comm: '/api/v1/user/comm/config',
    invite: '/api/v1/user/invite/fetch',
    inviteSave: '/api/v1/user/invite/save',
    orders: '/api/v1/user/order/fetch',
    downloads: '/api/v1/app-downloads',
    telegramBot: '/api/v1/user/telegram/getBotInfo',
    dify: '/api/v1/user/support/dify-context'
  };
  var NAV_ITEMS = [
    { route: '#/dashboard', label: '仪表盘', iconName: 'dashboard' },
    { route: '#/plan', label: '商店', iconName: 'store' },
    { route: '#/invite', label: '邀请赚钱', iconName: 'invite' },
    { route: '#/knowledge', label: '操作说明', iconName: 'help' }
  ];

  var state = {
    sourceRoot: null,
    root: null,
    topbarTitle: null,
    topbarBreadcrumb: null,
    data: {},
    errors: {},
    accessToken: '',
    observer: null,
    retryTimer: null,
    turnstileScriptPromise: null,
    turnstileWidgetId: null,
    modalLastFocus: null,
    loadGeneration: 0
  };

  function numberOr(value, fallback) {
    var number = Number(value);
    return Number.isFinite(number) ? number : fallback;
  }

  function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
  }

  function selectLatestOfficialArtifact(payload, platform) {
    var platforms = payload && Array.isArray(payload.platforms) ? payload.platforms : [];
    var platformEntry = platforms.find(function (item) {
      return String(item && item.platform || '').toLowerCase() === String(platform || '').toLowerCase();
    });
    var officialKey = OFFICIAL_APP_KEYS[String(platform || '').toLowerCase()];
    if (!platformEntry || !officialKey || !Array.isArray(platformEntry.apps)) return null;

    var app = platformEntry.apps.find(function (item) {
      return item && item.app_key === officialKey;
    });
    if (!app || !Array.isArray(app.packages) || app.packages.length === 0) return null;

    return app.packages.slice().sort(function (left, right) {
      var buildDifference = numberOr(right && right.build_number, 0) - numberOr(left && left.build_number, 0);
      if (buildDifference !== 0) return buildDifference;
      return numberOr(right && right.published_at, 0) - numberOr(left && left.published_at, 0);
    })[0] || null;
  }

  function noticeSortValue(notice) {
    var raw = notice && notice.created_at;
    var numeric = Number(raw);
    if (Number.isFinite(numeric)) return numeric;
    var parsed = Date.parse(String(raw || ''));
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function selectLatestNotices(notices) {
    if (!Array.isArray(notices)) return [];
    return notices.slice().sort(function (left, right) {
      var dateDifference = noticeSortValue(right) - noticeSortValue(left);
      if (dateDifference !== 0) return dateDifference;
      return numberOr(right && right.id, 0) - numberOr(left && left.id, 0);
    }).slice(0, 3);
  }

  function buildInviteLink(origin, code) {
    return String(origin || '').replace(/\/$/, '') + '/app#/register?code=' + encodeURIComponent(String(code || ''));
  }

  function buildInviteCommissionLabel(invite) {
    var stat = invite && Array.isArray(invite.stat) ? invite.stat : [];
    var rawRate = stat[3];
    if (stat.length < 4 || rawRate === null || rawRate === undefined || String(rawRate).trim() === '') return '';
    var rate = Number(rawRate);
    if (!Number.isFinite(rate) || rate < 0) return '';
    return '佣金 ' + String(rate) + '%';
  }

  function getTrafficPackageItems(payload) {
    if (Array.isArray(payload)) return payload;
    if (payload && Array.isArray(payload.data)) return payload.data;
    if (payload && Array.isArray(payload.items)) return payload.items;
    return [];
  }

  function hasPurchasableTrafficPackages(payload) {
    return getTrafficPackageItems(payload).length > 0;
  }

  function getOrderItems(payload) {
    if (Array.isArray(payload)) return payload;
    if (payload && Array.isArray(payload.data)) return payload.data;
    return [];
  }

  function hasUnfinishedOrders(payload) {
    return getOrderItems(payload).some(function (order) {
      var status = numberOr(order && order.status, -1);
      return status === 0 || status === 1;
    });
  }

  function buildSubscriptionViewModel(info, nowSeconds) {
    info = info || {};
    nowSeconds = numberOr(nowSeconds, Math.floor(Date.now() / 1000));
    var hasActivePlan = Boolean(info.has_active_plan || info.active_product_type === 'plan');
    var planTotal = Math.max(0, numberOr(
      info.plan_transfer_enable,
      hasActivePlan ? numberOr(info.transfer_enable, 0) : 0
    ));
    var planUsed = Math.max(0, numberOr(
      info.plan_used_traffic,
      hasActivePlan ? numberOr(info.u, 0) + numberOr(info.d, 0) : 0
    ));
    var packageTotal = Math.max(0, numberOr(info.traffic_package_total, 0));
    var packageRemaining = Math.max(0, numberOr(info.traffic_package_remaining, 0));
    var packageUsed = Math.max(0, packageTotal - packageRemaining);
    var hasTrafficPackage = packageTotal > 0 || packageRemaining > 0;
    var active = Boolean(hasActivePlan || packageRemaining > 0 || info.active_product_type === 'traffic_package');
    var expiredAt = info.effective_expired_at !== undefined ? info.effective_expired_at : info.expired_at;
    var expiredValue = numberOr(expiredAt, 0);
    var hasNamedProduct = Boolean(info.active_product_name || (info.plan && info.plan.name));
    var expired = !active && hasNamedProduct && expiredValue > 0 && expiredValue <= nowSeconds;
    var name = info.active_product_name
      || (info.plan && info.plan.name)
      || (info.latest_traffic_package && info.latest_traffic_package.name)
      || (expired ? '原套餐已到期' : '暂无可用套餐');
    var planProgress = planTotal > 0 ? clamp(Math.round((planUsed / planTotal) * 100), 0, 100) : 0;
    var packageProgress = packageTotal > 0 ? clamp(Math.round((packageUsed / packageTotal) * 100), 0, 100) : 0;
    var expiryLabel = hasActivePlan && (!expiredAt || expiredValue <= 0)
      ? '长期有效'
      : (expiredValue > 0 ? formatDate(expiredValue) : '—');
    var trafficNote = hasActivePlan && hasTrafficPackage
      ? '优先使用套餐流量，用完后继续使用流量包余额。套餐重置时间不受影响。'
      : (!hasActivePlan && packageRemaining > 0
        ? '当前套餐已到期，仍可继续使用流量包余额。续费后套餐重置时间独立计算。'
        : '');

    return {
      name: name,
      status: active ? '使用中' : (expired ? '已过期' : '未订阅'),
      statusTone: active ? 'success' : (expired ? 'danger' : 'muted'),
      hasActivePlan: hasActivePlan,
      hasTrafficPackage: hasTrafficPackage,
      planTotal: planTotal,
      planUsed: planUsed,
      planProgress: planProgress,
      packageTotal: packageTotal,
      packageUsed: packageUsed,
      packageRemaining: packageRemaining,
      packageProgress: packageProgress,
      expiryLabel: expiryLabel,
      resetLabel: hasActivePlan ? formatDate(info.next_reset_at) : '—',
      trafficNote: trafficNote,
      canSubscribe: Boolean(info.subscribe_url),
      hasProduct: active || expired || hasNamedProduct
    };
  }

  function buildTelegramViewModel(comm, user) {
    user = user || {};
    if (!comm) {
      return {
        bindVisible: false,
        bindDisabled: true,
        bindLabel: '绑定 Bot',
        groupVisible: false,
        description: 'Telegram 配置暂不可用'
      };
    }

    var enabled = Boolean(Number(comm.is_telegram));
    var bound = Boolean(user.telegram_id);
    return {
      bindVisible: enabled,
      bindDisabled: bound,
      bindLabel: bound ? '已绑定' : '绑定 Bot',
      groupVisible: Boolean(comm.telegram_discuss_link),
      description: bound
        ? 'Bot 已绑定，可接收订阅与服务提醒。'
        : enabled
          ? '绑定 Bot 获取提醒，加入官方群了解节点动态。'
          : 'Bot 服务暂未启用，可通过官方群联系我们。'
    };
  }

  var testApi = {
    selectLatestOfficialArtifact: selectLatestOfficialArtifact,
    selectLatestNotices: selectLatestNotices,
    buildInviteLink: buildInviteLink,
    buildInviteCommissionLabel: buildInviteCommissionLabel,
    hasPurchasableTrafficPackages: hasPurchasableTrafficPackages,
    hasUnfinishedOrders: hasUnfinishedOrders,
    buildSubscriptionViewModel: buildSubscriptionViewModel,
    buildTelegramViewModel: buildTelegramViewModel,
    getNavigationItems: function () {
      return NAV_ITEMS.concat([{ route: '#/ticket', label: '问题申诉', iconName: 'appeal' }]).map(function (item) {
        return Object.assign({}, item);
      });
    }
  };

  if (typeof module !== 'undefined' && module.exports) module.exports = testApi;
  if (!global || !global.document) return;
  global.__ELEPHANT_ROUTE_DASHBOARD_V2_TEST__ = testApi;

  function getRoute() {
    var hash = global.location.hash || '';
    return (hash.replace(/^#/, '').split('?')[0] || '/').replace(/\/$/, '') || '/';
  }

  function isDashboardRoute() {
    var route = getRoute();
    return route === '/' || route === '/dashboard' || route.indexOf('/dashboard/') === 0;
  }

  function getStoredAccessToken() {
    var stored = global.localStorage.getItem(ACCESS_TOKEN_STORAGE_KEY);
    if (!stored) return '';
    try {
      var parsed = JSON.parse(stored);
      if (parsed && parsed.expire && parsed.expire <= Date.now()) return '';
      return parsed && parsed.value ? parsed.value : stored;
    } catch (error) {
      return stored;
    }
  }

  function getResponseData(payload) {
    if (payload && Object.prototype.hasOwnProperty.call(payload, 'data')) return payload.data;
    return payload;
  }

  function requestJson(path, options) {
    options = options || {};
    var headers = Object.assign({ Accept: 'application/json' }, options.headers || {});
    var token = state.accessToken || getStoredAccessToken();
    if (options.auth !== false && token) headers.Authorization = token;

    return global.fetch(path, {
      method: options.method || 'GET',
      headers: headers,
      body: options.body,
      credentials: 'same-origin'
    }).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (payload) {
        if (!response.ok || (payload && payload.code && Number(payload.code) !== 0)) {
          var message = payload && payload.message ? payload.message : '请求失败，请稍后重试';
          var error = new Error(message);
          error.status = response.status;
          throw error;
        }
        return payload;
      });
    });
  }

  function formatTraffic(bytes) {
    var value = Math.max(0, numberOr(bytes, 0));
    var units = ['B', 'KB', 'MB', 'GB', 'TB'];
    var unitIndex = 0;
    while (value >= 1024 && unitIndex < units.length - 1) {
      value /= 1024;
      unitIndex += 1;
    }
    var digits = unitIndex === 0 ? 0 : (value >= 100 ? 0 : value >= 10 ? 1 : 2);
    return value.toFixed(digits).replace(/\.0+$/, '') + ' ' + units[unitIndex];
  }

  function formatDate(timestamp) {
    var value = numberOr(timestamp, 0);
    if (!value) return '—';
    var date = new Date(value < 1000000000000 ? value * 1000 : value);
    if (Number.isNaN(date.getTime())) return '—';
    return new Intl.DateTimeFormat('zh-CN', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit'
    }).format(date).replaceAll('/', '-');
  }

  function formatNoticeDate(timestamp) {
    var value = numberOr(timestamp, 0);
    if (!value) return '';
    var date = new Date(value < 1000000000000 ? value * 1000 : value);
    if (Number.isNaN(date.getTime())) return '';
    return new Intl.DateTimeFormat('zh-CN', { month: '2-digit', day: '2-digit' }).format(date).replaceAll('/', '-');
  }

  function stripMarkup(value) {
    var container = document.createElement('div');
    container.innerHTML = String(value || '');
    return (container.textContent || '').replace(/[\t ]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim();
  }

  function copyTextWithExecCommand(text) {
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    var copied = false;
    try {
      copied = document.execCommand('copy');
    } finally {
      textarea.remove();
    }
    return copied ? Promise.resolve() : Promise.reject(new Error('copy failed'));
  }

  function copyText(text, successMessage) {
    var copy = global.navigator.clipboard && global.navigator.clipboard.writeText
      ? global.navigator.clipboard.writeText(text).catch(function () { return copyTextWithExecCommand(text); })
      : copyTextWithExecCommand(text);
    return copy.then(function () {
      notify('success', successMessage || '复制成功');
    }).catch(function () {
      notify('error', '复制失败，请手动复制');
    });
  }

  function notify(type, message) {
    if (global.$message && typeof global.$message[type] === 'function') {
      global.$message[type](message);
      return;
    }
    var existing = document.getElementById('er-v2-toast');
    if (existing) existing.remove();
    var toast = document.createElement('div');
    toast.id = 'er-v2-toast';
    toast.className = 'er-v2-toast er-v2-toast-' + type;
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
    toast.textContent = message;
    document.body.appendChild(toast);
    global.setTimeout(function () { toast.remove(); }, 2600);
  }

  function navigate(hash) {
    if (global.location.hash === hash) {
      global.dispatchEvent(new HashChangeEvent('hashchange'));
      return;
    }
    global.location.hash = hash;
  }

  function findTrafficPackagePlanAnchor() {
    return findTextElementOutsideV2(['选择最适合您的计划'])
      || findTextElementOutsideV2(['选择订阅'])
      || findTextElementOutsideV2(['按流量']);
  }

  function focusTrafficPackagePurchasePage() {
    if (getRoute() !== '/plan') return false;
    var trafficTabText = findTextElementOutsideV2(['按流量']);
    if (!trafficTabText) return false;
    var trafficTab = trafficTabText.closest('button, a, [role="tab"], [role="button"], .n-tabs-tab, .n-radio-button, .n-segmented-item, label');
    if (trafficTab && typeof trafficTab.click === 'function') trafficTab.click();
    var anchor = findTrafficPackagePlanAnchor();
    if (anchor && typeof anchor.scrollIntoView === 'function') {
      anchor.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    return true;
  }

  function scheduleTrafficPackagePurchaseFocus() {
    var tries = 0;
    var timer = global.setInterval(function () {
      tries += 1;
      if (focusTrafficPackagePurchasePage() || tries >= 24) global.clearInterval(timer);
    }, 120);
  }

  function goToTrafficPackagePurchase() {
    navigate('#/plan');
    scheduleTrafficPackagePurchaseFocus();
  }

  function createElement(tagName, className, text) {
    var node = document.createElement(tagName);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
  }

  function applyThemeColor() {
    var key = global.settings && global.settings.theme ? global.settings.theme.color : 'default';
    var primary = THEME_COLORS[key] || THEME_COLORS.default;
    var inherited = global.getComputedStyle(document.documentElement)
      .getPropertyValue('--er-subscribe-action-primary')
      .trim();
    if (inherited) primary = inherited;
    var normalized = primary.replace('#', '');
    var rgb = normalized.length === 6
      ? [parseInt(normalized.slice(0, 2), 16), parseInt(normalized.slice(2, 4), 16), parseInt(normalized.slice(4, 6), 16)].join(', ')
      : '49, 108, 114';
    document.documentElement.style.setProperty('--er-v2-primary', primary);
    document.documentElement.style.setProperty('--er-v2-accent', primary);
    document.documentElement.style.setProperty('--er-v2-accent-rgb', rgb);
  }

  function setText(selector, value) {
    if (!state.root) return;
    var node = state.root.querySelector(selector);
    if (node) node.textContent = value === undefined || value === null || value === '' ? '—' : String(value);
  }

  function setButtonState(selector, disabled, label) {
    if (!state.root) return;
    var button = state.root.querySelector(selector);
    if (!button) return;
    button.disabled = Boolean(disabled);
    if (label) button.textContent = label;
  }

  function buildSidebar() {
    var sidebar = createElement('nav', 'er-v2-sidebar');
    sidebar.id = SIDEBAR_ID;
    sidebar.setAttribute('aria-label', '用户中心主导航');

    var list = createElement('div', 'er-v2-sidebar-list');
    NAV_ITEMS.forEach(function (item) {
      var link = createElement('a', 'er-v2-sidebar-link');
      link.href = item.route;
      link.dataset.route = item.route.slice(1);
      var icon = createElement('span', 'er-v2-sidebar-icon');
      icon.innerHTML = solidIcon(item.iconName);
      icon.setAttribute('aria-hidden', 'true');
      link.appendChild(icon);
      link.appendChild(createElement('span', '', item.label));
      list.appendChild(link);
    });
    sidebar.appendChild(list);

    var appeal = createElement('a', 'er-v2-sidebar-appeal');
    appeal.href = '#/ticket';
    appeal.dataset.route = '/ticket';
    var appealIcon = createElement('span', 'er-v2-sidebar-appeal-icon');
    appealIcon.innerHTML = solidIcon('appeal');
    appealIcon.setAttribute('aria-hidden', 'true');
    var appealCopy = createElement('span', 'er-v2-sidebar-appeal-copy');
    appealCopy.appendChild(createElement('strong', '', '问题申诉'));
    appealCopy.appendChild(createElement('small', '', '提交后将在 3 小时内回复'));
    appeal.appendChild(appealIcon);
    appeal.appendChild(appealCopy);
    sidebar.appendChild(appeal);
    return sidebar;
  }

  function createSidebarLink(item, className) {
    var link = createElement('a', className);
    link.href = item.route;
    link.dataset.route = item.route.slice(1);
    var icon = createElement('span', className + '-icon');
    icon.innerHTML = solidIcon(item.iconName);
    icon.setAttribute('aria-hidden', 'true');
    link.appendChild(icon);
    link.appendChild(createElement('span', className + '-label', item.label));
    return link;
  }

  function buildMobileSidebar(drawer, nativeCloseButton) {
    var sidebar = createElement('nav', 'er-v2-mobile-sidebar');
    sidebar.id = MOBILE_SIDEBAR_ID;
    sidebar.setAttribute('aria-label', '移动端用户中心主导航');

    var brand = createElement('div', 'er-v2-mobile-sidebar-brand');
    var logo = createElement('img', 'er-v2-mobile-sidebar-logo');
    logo.src = '/theme/ElephantRoute/assets/client-logo.svg';
    logo.alt = '';
    brand.appendChild(logo);
    brand.appendChild(createElement('strong', '', '大象网络'));

    var closeButton = createElement('button', 'er-v2-mobile-sidebar-close');
    closeButton.type = 'button';
    closeButton.setAttribute('aria-label', '关闭菜单');
    closeButton.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6.2 4.8 13 13-1.4 1.4-13-13 1.4-1.4Zm11.6 0 1.4 1.4-13 13-1.4-1.4 13-13Z"/></svg>';
    closeButton.addEventListener('click', function () {
      if (nativeCloseButton && typeof nativeCloseButton.click === 'function') nativeCloseButton.click();
    });
    brand.appendChild(closeButton);
    sidebar.appendChild(brand);

    var list = createElement('div', 'er-v2-mobile-sidebar-list');
    NAV_ITEMS.forEach(function (item) {
      var link = createSidebarLink(item, 'er-v2-mobile-sidebar-link');
      link.addEventListener('click', function (event) {
        event.preventDefault();
        navigate(item.route);
        if (nativeCloseButton && typeof nativeCloseButton.click === 'function') nativeCloseButton.click();
      });
      list.appendChild(link);
    });
    sidebar.appendChild(list);

    var appeal = createElement('a', 'er-v2-mobile-sidebar-appeal');
    appeal.href = '#/ticket';
    appeal.dataset.route = '/ticket';
    var appealIcon = createElement('span', 'er-v2-mobile-sidebar-appeal-icon');
    appealIcon.innerHTML = solidIcon('appeal');
    appealIcon.setAttribute('aria-hidden', 'true');
    var appealCopy = createElement('span', 'er-v2-mobile-sidebar-appeal-copy');
    appealCopy.appendChild(createElement('strong', '', '问题申诉'));
    appealCopy.appendChild(createElement('small', '', '提交后将在 3 小时内回复'));
    appeal.appendChild(appealIcon);
    appeal.appendChild(appealCopy);
    appeal.addEventListener('click', function (event) {
      event.preventDefault();
      navigate('#/ticket');
      if (nativeCloseButton && typeof nativeCloseButton.click === 'function') nativeCloseButton.click();
    });
    sidebar.appendChild(appeal);
    return sidebar;
  }

  function restoreNativeMobileDrawer(drawer) {
    drawer.classList.remove('er-v2-mobile-drawer');
    var container = drawer.closest('.n-drawer-container');
    if (container) container.classList.remove('er-v2-mobile-drawer-container');
    drawer.querySelectorAll('[data-er-v2-mobile-native-hidden="true"]').forEach(function (node) {
      delete node.dataset.erV2MobileNativeHidden;
      node.removeAttribute('aria-hidden');
    });
    var content = drawer.querySelector('.er-v2-mobile-drawer-content');
    if (content) content.classList.remove('er-v2-mobile-drawer-content');
    var customSidebar = drawer.querySelector('#' + MOBILE_SIDEBAR_ID);
    if (customSidebar) customSidebar.remove();
  }

  function ensureMobileDrawer() {
    var isMobile = !global.matchMedia || global.matchMedia('(max-width: 767px)').matches;
    var matched = false;
    document.querySelectorAll('.n-drawer').forEach(function (drawer) {
      var nativeMenu = drawer.querySelector('.n-menu.side-menu');
      if (!nativeMenu) return;
      if (!isMobile) {
        restoreNativeMobileDrawer(drawer);
        return;
      }

      matched = true;
      var content = nativeMenu.parentElement;
      var nativeHeader = nativeMenu.previousElementSibling;
      var nativeCloseButton = nativeHeader ? nativeHeader.querySelector('button') : drawer.querySelector('button');
      drawer.classList.add('er-v2-mobile-drawer');
      var container = drawer.closest('.n-drawer-container');
      if (container) container.classList.add('er-v2-mobile-drawer-container');
      if (content) content.classList.add('er-v2-mobile-drawer-content');
      if (nativeHeader) {
        nativeHeader.dataset.erV2MobileNativeHidden = 'true';
        nativeHeader.setAttribute('aria-hidden', 'true');
      }
      nativeMenu.dataset.erV2MobileNativeHidden = 'true';
      nativeMenu.setAttribute('aria-hidden', 'true');

      if (!drawer.querySelector('#' + MOBILE_SIDEBAR_ID)) {
        var mobileSidebar = buildMobileSidebar(drawer, nativeCloseButton);
        content.appendChild(mobileSidebar);
      }
    });
    if (matched) updateSidebarActiveState();
    return matched;
  }

  function ensureSidebar() {
    var sider = document.querySelector('.n-layout-sider');
    if (!sider) return false;
    applyThemeColor();
    sider.classList.add('er-v2-sidebar-active');
    var menu = sider.querySelector('.n-menu');
    if (menu) {
      menu.dataset.erV2SidebarHidden = 'true';
      menu.setAttribute('aria-hidden', 'true');
    }

    var existing = document.getElementById(SIDEBAR_ID);
    if (!existing) {
      var target = sider.querySelector('.n-scrollbar-content') || sider.querySelector('.n-scrollbar-container') || sider;
      existing = buildSidebar();
      target.appendChild(existing);
    }
    updateSidebarActiveState();
    return true;
  }

  function updateSidebarActiveState() {
    var route = getRoute();
    [SIDEBAR_ID, MOBILE_SIDEBAR_ID].forEach(function (sidebarId) {
      var sidebar = document.getElementById(sidebarId);
      if (!sidebar) return;
      sidebar.querySelectorAll('[data-route]').forEach(function (link) {
        var target = link.dataset.route;
        var active = route === target || (target !== '/dashboard' && route.indexOf(target + '/') === 0);
        link.classList.toggle('is-active', active);
        if (active) link.setAttribute('aria-current', 'page');
        else link.removeAttribute('aria-current');
      });
    });
  }

  function findTextElementOutsideV2(labels) {
    var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
      acceptNode: function (node) {
        if (!node.nodeValue || !node.parentElement || node.parentElement.closest('#' + ROOT_ID)) return NodeFilter.FILTER_REJECT;
        if (node.parentElement.closest('.n-layout-sider, .n-menu, #er-v2-sidebar')) return NodeFilter.FILTER_REJECT;
        return labels.some(function (label) { return node.nodeValue.indexOf(label) !== -1; })
          ? NodeFilter.FILTER_ACCEPT
          : NodeFilter.FILTER_REJECT;
      }
    });
    var match = walker.nextNode();
    return match ? match.parentElement : null;
  }

  function findDashboardMountPoint() {
    var anchor = findTextElementOutsideV2(['我的订阅'])
      || findTextElementOutsideV2(['公告', '购买订阅']);
    if (!anchor) return null;
    var source = anchor.closest('.cus-scroll-y, .n-layout-content, main');
    if (!source) {
      var card = anchor.closest('.n-card') || anchor;
      source = card.closest('section') || card.parentElement;
    }
    if (!source || source.closest('.n-layout-sider, .n-menu')) return null;
    return source.parentElement ? { source: source, host: source.parentElement } : null;
  }

  function findTopbarBreadcrumb() {
    return Array.prototype.find.call(document.querySelectorAll('.n-breadcrumb'), function (breadcrumb) {
      return !breadcrumb.closest('.n-layout-sider, #' + ROOT_ID + ', #' + SIDEBAR_ID);
    }) || null;
  }

  function ensureTopbarGreeting() {
    var breadcrumb = findTopbarBreadcrumb();
    if (!breadcrumb || !breadcrumb.parentElement) return false;
    var host = breadcrumb.parentElement;
    var existing = document.getElementById(TOPBAR_TITLE_ID);
    if (existing && existing.parentElement !== host) existing.remove();
    if (!existing || existing.parentElement !== host) {
      existing = document.createElement('div');
      existing.id = TOPBAR_TITLE_ID;
      existing.className = 'er-v2-topbar-title';
      existing.setAttribute('aria-label', '仪表盘问候');
      existing.innerHTML = '<strong><span data-er-v2-topbar-field="greeting">欢迎回来</span>，<span data-er-v2-topbar-field="email">用户</span></strong>';
      host.appendChild(existing);
    }
    breadcrumb.dataset.erV2TopbarHidden = 'true';
    breadcrumb.hidden = true;
    breadcrumb.setAttribute('aria-hidden', 'true');
    state.topbarTitle = existing;
    state.topbarBreadcrumb = breadcrumb;
    renderGreeting();
    renderUser();
    return true;
  }

  function restoreTopbarGreeting() {
    var title = document.getElementById(TOPBAR_TITLE_ID);
    if (title) title.remove();
    document.querySelectorAll('[data-er-v2-topbar-hidden="true"]').forEach(function (breadcrumb) {
      breadcrumb.hidden = false;
      breadcrumb.removeAttribute('aria-hidden');
      delete breadcrumb.dataset.erV2TopbarHidden;
    });
    state.topbarTitle = null;
    state.topbarBreadcrumb = null;
  }

  function dashboardTemplate() {
    return [
      '<div class="er-v2-glow er-v2-glow-one" aria-hidden="true"></div>',
      '<div class="er-v2-glow er-v2-glow-two" aria-hidden="true"></div>',
      '<div class="er-v2-workspace">',
      '  <section class="er-v2-top-grid">',
      '    <article class="er-v2-panel er-v2-subscription er-v2-entrance" aria-labelledby="er-v2-subscription-title">',
      '      <div class="er-v2-section-heading"><div><p class="er-v2-kicker">当前订阅</p><h2 id="er-v2-subscription-title" data-field="plan-name">正在加载</h2></div><span class="er-v2-status" data-field="plan-status">加载中</span></div>',
      '      <div class="er-v2-traffic-layout">',
      '        <div class="er-v2-traffic-groups">',
      '          <section class="er-v2-traffic-group er-v2-traffic-group-plan" aria-labelledby="er-v2-plan-traffic-title">',
      '            <div class="er-v2-traffic-group-title"><h3 id="er-v2-plan-traffic-title">套餐流量</h3></div>',
      '            <div class="er-v2-traffic-metrics er-v2-traffic-metrics-plan">',
      '              <div><span>已用流量</span><strong data-field="plan-used">—</strong></div>',
      '              <div><span>总流量</span><strong data-field="plan-total">—</strong></div>',
      '              <div class="er-v2-date-metric"><span>流量重置日期</span><strong data-field="plan-reset">—</strong></div>',
      '              <div class="er-v2-date-metric"><span>套餐到期日期</span><strong data-field="plan-expiry">—</strong></div>',
      '            </div>',
      '            <div class="er-v2-traffic-progress er-v2-traffic-progress-plan" role="progressbar" aria-label="套餐流量使用进度" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span data-progress="plan"></span></div>',
      '          </section>',
      '          <section class="er-v2-traffic-group er-v2-traffic-group-package" aria-labelledby="er-v2-package-traffic-title">',
      '            <div class="er-v2-traffic-group-title"><h3 id="er-v2-package-traffic-title">流量包</h3><span data-field="package-state">未购买</span></div>',
      '            <div class="er-v2-traffic-metrics er-v2-traffic-metrics-package">',
      '              <div><span>已用流量</span><strong data-field="package-used">—</strong></div>',
      '              <div><span>总流量</span><strong data-field="package-total">—</strong></div>',
      '            </div>',
      '            <div class="er-v2-traffic-progress er-v2-traffic-progress-package" role="progressbar" aria-label="流量包使用进度" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span data-progress="package"></span></div>',
      '          </section>',
      '        </div>',
      '      </div>',
      '      <p class="er-v2-traffic-note" data-field="traffic-note" hidden></p>',
      '      <div class="er-v2-subscription-footer"><div class="er-v2-actions"><button type="button" class="er-v2-button er-v2-button-primary" data-action="import-subscription">一键导入订阅</button></div><div class="er-v2-traffic-cta" data-region="traffic-package-cta" hidden><span>流量不够用？</span><button type="button" data-action="buy-traffic-package">立即购买流量包' + solidIcon('arrowRight') + '</button></div></div>',
      '      <p class="er-v2-inline-error" data-error="subscribe" hidden></p>',
      '    </article>',
      '    <article class="er-v2-panel er-v2-telegram er-v2-entrance" aria-labelledby="er-v2-telegram-title">',
      '      <div><div class="er-v2-telegram-mark" aria-hidden="true">' + solidIcon('telegram') + '</div><p class="er-v2-kicker">连接与提醒</p><h2 id="er-v2-telegram-title">Telegram 服务</h2><p class="er-v2-description" data-field="telegram-description">正在检查服务状态</p></div>',
      '      <div class="er-v2-actions"><button type="button" class="er-v2-button er-v2-button-primary" data-action="bind-telegram">绑定 Bot</button><button type="button" class="er-v2-button" data-action="join-telegram">加入群组</button></div>',
      '      <p class="er-v2-inline-error" data-error="telegram" hidden></p>',
      '    </article>',
      '  </section>',
      '  <section class="er-v2-panel er-v2-downloads er-v2-entrance" aria-labelledby="er-v2-download-title">',
      '    <div class="er-v2-section-heading er-v2-download-heading"><div><h2 id="er-v2-download-title">软件下载</h2><p class="er-v2-description">选择系统即可下载当前正式版本</p></div><button type="button" class="er-v2-text-action" data-action="all-downloads">全部版本' + solidIcon('arrowRight', 'er-v2-text-action-icon') + '</button></div>',
      '    <div class="er-v2-download-grid">',
      '      <button type="button" class="er-v2-download-button" data-download-platform="android"><span class="er-v2-platform-icon is-android">' + solidIcon('android') + '</span><span><strong>Android</strong><small data-download-state="android">加载中</small></span><span class="er-v2-download-arrow">' + solidIcon('download') + '</span></button>',
      '      <button type="button" class="er-v2-download-button" data-download-platform="windows"><span class="er-v2-platform-icon is-windows">' + solidIcon('windows') + '</span><span><strong>Windows</strong><small data-download-state="windows">加载中</small></span><span class="er-v2-download-arrow">' + solidIcon('download') + '</span></button>',
      '      <button type="button" class="er-v2-download-button" data-download-platform="macos"><span class="er-v2-platform-icon is-macos">' + solidIcon('macos') + '</span><span><strong>macOS</strong><small data-download-state="macos">加载中</small></span><span class="er-v2-download-arrow">' + solidIcon('download') + '</span></button>',
      '      <button type="button" class="er-v2-download-button" data-download-platform="ios"><span class="er-v2-platform-icon is-ios">' + solidIcon('ios') + '</span><span><strong>iOS · Karing</strong><small>App Store</small></span><span class="er-v2-download-arrow">' + solidIcon('external') + '</span></button>',
      '    </div>',
      '    <p class="er-v2-inline-error" data-error="downloads" hidden></p>',
      '  </section>',
      '  <section class="er-v2-bottom-grid">',
      '    <article class="er-v2-panel er-v2-notices er-v2-entrance" aria-labelledby="er-v2-notices-title"><div class="er-v2-card-watermark er-v2-card-watermark-notice" aria-hidden="true">' + solidIcon('noticePattern') + '</div><div class="er-v2-section-heading"><div><h2 id="er-v2-notices-title">官方公告</h2></div></div><div class="er-v2-notice-list" data-region="notices"><p class="er-v2-empty">正在加载公告</p></div></article>',
      '    <article class="er-v2-panel er-v2-shortcuts er-v2-entrance" aria-labelledby="er-v2-shortcuts-title"><div class="er-v2-card-watermark er-v2-card-watermark-shortcut" aria-hidden="true">' + solidIcon('shortcutPattern') + '</div><div class="er-v2-section-heading"><div><h2 id="er-v2-shortcuts-title">快捷入口</h2></div></div><div class="er-v2-shortcut-list"><button type="button" data-action="nodes"><span class="er-v2-shortcut-icon">' + solidIcon('signal') + '</span><span class="er-v2-shortcut-label">节点状态</span><strong data-field="node-status">正在检查</strong></button><button type="button" data-action="orders"><span class="er-v2-shortcut-icon">' + solidIcon('orders') + '</span><span class="er-v2-shortcut-label">订单记录</span><strong data-field="order-status" data-tone="danger" hidden>有未完成订单</strong></button><button type="button" data-action="contact"><span class="er-v2-shortcut-icon">' + solidIcon('contact') + '</span><span class="er-v2-shortcut-label">联系我们</span></button><button type="button" data-action="knowledge"><span class="er-v2-shortcut-icon">' + solidIcon('knowledge') + '</span><span class="er-v2-shortcut-label">使用教程</span></button></div><p class="er-v2-inline-error" data-error="servers" hidden></p></article>',
      '    <article class="er-v2-panel er-v2-invite er-v2-entrance" aria-labelledby="er-v2-invite-title"><div class="er-v2-invite-watermark" aria-hidden="true">' + solidIcon('invite') + '</div><div class="er-v2-section-heading"><div><h2 id="er-v2-invite-title">邀请好友赚钱</h2></div><span class="er-v2-commission-rate" data-field="commission-rate" hidden></span></div><div class="er-v2-invite-content" data-region="invite"><p class="er-v2-empty">正在加载邀请信息</p></div><p class="er-v2-inline-error" data-error="invite" hidden></p></article>',
      '  </section>',
      '</div>',
      '<div class="er-v2-modal" id="er-v2-modal" aria-hidden="true"><div class="er-v2-modal-backdrop" data-action="close-modal"></div><section class="er-v2-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="er-v2-modal-title"><header><div><p class="er-v2-kicker" data-modal-kicker>大象网络</p><h2 id="er-v2-modal-title">提示</h2></div><button type="button" class="er-v2-modal-close" data-action="close-modal" aria-label="关闭">×</button></header><div class="er-v2-modal-body" data-modal-body></div><footer class="er-v2-modal-footer" data-modal-footer></footer></section></div>'
    ].join('');
  }

  function createDashboardRoot() {
    var root = document.createElement('section');
    root.id = ROOT_ID;
    root.className = 'er-v2-dashboard';
    root.setAttribute('aria-label', '仪表盘');
    root.innerHTML = dashboardTemplate();
    bindDashboardEvents(root);
    bindModalEvents(root.querySelector('#er-v2-modal'));
    return root;
  }

  function bindModalEvents(modal) {
    if (!modal) return;
    modal.addEventListener('click', function (event) {
      var actionTarget = event.target.closest('[data-action]');
      if (actionTarget) handleAction(actionTarget.dataset.action, actionTarget);
    });
    modal.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') closeModal();
    });
  }

  function portalModal(root) {
    var modal = root && root.querySelector('#er-v2-modal');
    if (modal) document.body.appendChild(modal);
  }

  function getModal() {
    return document.getElementById('er-v2-modal');
  }

  function getModalBody() {
    var modal = getModal();
    return modal ? modal.querySelector('[data-modal-body]') : null;
  }

  function bindDashboardEvents(root) {
    root.addEventListener('click', function (event) {
      var actionTarget = event.target.closest('[data-action]');
      if (actionTarget) {
        handleAction(actionTarget.dataset.action, actionTarget);
        return;
      }
      var downloadTarget = event.target.closest('[data-download-platform]');
      if (downloadTarget && !downloadTarget.disabled) startPlatformDownload(downloadTarget.dataset.downloadPlatform);
    });
    root.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') closeModal();
    });
  }

  function handleAction(action, target) {
    if (action === 'profile') navigate('#/profile');
    if (action === 'buy-traffic-package') goToTrafficPackagePurchase();
    if (action === 'nodes') navigate('#/node');
    if (action === 'orders') navigate('#/order');
    if (action === 'knowledge') navigate('#/knowledge');
    if (action === 'all-downloads') global.open(DOWNLOAD_PAGE_URL, '_blank', 'noopener,noreferrer');
    if (action === 'import-subscription') openLegacySubscriptionImport();
    if (action === 'bind-telegram') openTelegramBinding();
    if (action === 'join-telegram') joinTelegramGroup();
    if (action === 'contact') openContact();
    if (action === 'generate-invite') generateInviteCode(target);
    if (action === 'copy-invite-code') copyInviteCode();
    if (action === 'copy-invite-link') copyInviteLink();
    if (action === 'close-modal') closeModal();
  }

  function mountDashboard() {
    if (!isDashboardRoute()) {
      unmountDashboard();
      return false;
    }
    var existing = document.getElementById(ROOT_ID);
    if (existing) {
      state.root = existing;
      ensureTopbarGreeting();
      updateShellTop();
      return true;
    }
    var mountPoint = findDashboardMountPoint();
    if (!mountPoint) return false;

    state.sourceRoot = mountPoint.source;
    state.sourceRoot.dataset.erV2SourceHidden = 'true';
    state.sourceRoot.hidden = true;
    state.root = createDashboardRoot();
    mountPoint.host.insertBefore(state.root, state.sourceRoot);
    portalModal(state.root);
    document.body.classList.add('er-dashboard-v2-mounted');
    ensureTopbarGreeting();
    updateShellTop();
    global.setTimeout(updateShellTop, 180);
    state.accessToken = getStoredAccessToken();
    renderGreeting();
    loadDashboardData();
    return true;
  }

  function updateShellTop() {
    if (!state.root) return;
    var top = Math.max(0, Math.round(state.root.getBoundingClientRect().top));
    state.root.style.setProperty('--er-v2-shell-top', top + 'px');
  }

  function unmountDashboard() {
    var modal = getModal();
    closeModal();
    if (modal) modal.remove();
    var root = document.getElementById(ROOT_ID);
    if (root) root.remove();
    if (state.sourceRoot && document.body.contains(state.sourceRoot)) {
      state.sourceRoot.hidden = false;
      delete state.sourceRoot.dataset.erV2SourceHidden;
    }
    state.root = null;
    state.sourceRoot = null;
    restoreTopbarGreeting();
    state.loadGeneration += 1;
    document.body.classList.remove('er-dashboard-v2-mounted');
  }

  function renderGreeting() {
    var hour = new Date().getHours();
    var greeting = hour < 6 ? '夜深了' : hour < 12 ? '上午好' : hour < 18 ? '下午好' : '晚上好';
    if (state.topbarTitle) {
      var node = state.topbarTitle.querySelector('[data-er-v2-topbar-field="greeting"]');
      if (node) node.textContent = greeting;
    }
  }

  function setRegionError(name, error) {
    state.errors[name] = error;
    if (!state.root) return;
    var node = state.root.querySelector('[data-error="' + name + '"]');
    if (node) {
      node.hidden = false;
      node.textContent = error && error.message ? error.message : '暂时无法加载';
    }
  }

  function clearRegionError(name) {
    if (!state.root) return;
    var node = state.root.querySelector('[data-error="' + name + '"]');
    if (node) node.hidden = true;
  }

  function loadDashboardData() {
    var generation = ++state.loadGeneration;
    var requests = [
      ['user', requestJson(ENDPOINTS.user)],
      ['subscribe', requestJson(ENDPOINTS.subscribe)],
      ['trafficPackages', requestJson(ENDPOINTS.trafficPackages)],
      ['notices', requestJson(ENDPOINTS.notices)],
      ['servers', requestJson(ENDPOINTS.servers)],
      ['comm', requestJson(ENDPOINTS.comm)],
      ['invite', requestJson(ENDPOINTS.invite)],
      ['orders', requestUnfinishedOrders()],
      ['downloads', requestJson(ENDPOINTS.downloads, { auth: false })],
      ['dify', requestJson(ENDPOINTS.dify)]
    ];

    Promise.allSettled(requests.map(function (item) { return item[1]; })).then(function (results) {
      if (generation !== state.loadGeneration || !state.root) return;
      results.forEach(function (result, index) {
        var name = requests[index][0];
        if (result.status === 'fulfilled') {
          state.data[name] = getResponseData(result.value);
          clearRegionError(name);
        } else {
          state.data[name] = null;
          setRegionError(name, result.reason);
        }
      });
      renderUser();
      renderSubscription();
      renderTelegram();
      renderDownloads();
      renderNotices();
      renderServers();
      renderOrders();
      renderInvite();
    });
  }

  function requestUnfinishedOrders() {
    return Promise.all([
      requestJson(ENDPOINTS.orders + '?status=0'),
      requestJson(ENDPOINTS.orders + '?status=1')
    ]).then(function (responses) {
      return responses.reduce(function (orders, response) {
        return orders.concat(getOrderItems(getResponseData(response)));
      }, []);
    });
  }

  function renderUser() {
    var user = state.data.user || {};
    if (state.topbarTitle) {
      var node = state.topbarTitle.querySelector('[data-er-v2-topbar-field="email"]');
      if (node) node.textContent = user.email || '用户';
    }
  }

  function renderTrafficPackageCta() {
    if (!state.root) return;
    var cta = state.root.querySelector('[data-region="traffic-package-cta"]');
    if (!cta) return;
    cta.hidden = !hasPurchasableTrafficPackages(state.data.trafficPackages);
  }

  function renderTrafficProgress(view) {
    if (!state.root) return;
    var planProgress = state.root.querySelector('.er-v2-traffic-progress-plan');
    var packageProgress = state.root.querySelector('.er-v2-traffic-progress-package');
    if (planProgress) {
      planProgress.setAttribute('aria-valuenow', String(view.planProgress));
      planProgress.querySelector('span').style.width = view.planProgress + '%';
    }
    if (packageProgress) {
      packageProgress.setAttribute('aria-valuenow', String(view.packageProgress));
      packageProgress.querySelector('span').style.width = view.packageProgress + '%';
    }
  }

  function renderSubscription() {
    var info = state.data.subscribe;
    if (!info) {
      setText('[data-field="plan-name"]', '订阅信息暂不可用');
      setText('[data-field="plan-status"]', '加载失败');
      setText('[data-field="plan-used"]', '—');
      setText('[data-field="plan-total"]', '—');
      setText('[data-field="plan-reset"]', '—');
      setText('[data-field="plan-expiry"]', '—');
      setText('[data-field="package-used"]', '—');
      setText('[data-field="package-total"]', '—');
      setText('[data-field="package-state"]', '暂不可用');
      renderTrafficProgress({ planProgress: 0, packageProgress: 0 });
      var failedNote = state.root.querySelector('[data-field="traffic-note"]');
      if (failedNote) failedNote.hidden = true;
      setButtonState('[data-action="import-subscription"]', true);
      renderTrafficPackageCta();
      return;
    }
    var view = buildSubscriptionViewModel(info);
    setText('[data-field="plan-name"]', view.name);
    setText('[data-field="plan-status"]', view.status);
    setText('[data-field="plan-used"]', view.hasActivePlan ? formatTraffic(view.planUsed) : '—');
    setText('[data-field="plan-total"]', view.hasActivePlan ? formatTraffic(view.planTotal) : '—');
    setText('[data-field="plan-reset"]', view.resetLabel);
    setText('[data-field="plan-expiry"]', view.expiryLabel);
    setText('[data-field="package-used"]', view.hasTrafficPackage ? formatTraffic(view.packageUsed) : '—');
    setText('[data-field="package-total"]', view.hasTrafficPackage ? formatTraffic(view.packageTotal) : '—');
    setText('[data-field="package-state"]', view.hasTrafficPackage
      ? (view.packageRemaining > 0 ? '可用' : '已用完')
      : '未购买');
    var status = state.root.querySelector('[data-field="plan-status"]');
    if (status) status.dataset.tone = view.statusTone;
    var note = state.root.querySelector('[data-field="traffic-note"]');
    if (note) {
      note.textContent = view.trafficNote;
      note.hidden = !view.trafficNote;
    }
    renderTrafficProgress(view);
    renderTrafficPackageCta();
    setButtonState('[data-action="import-subscription"]', !view.canSubscribe || !view.hasProduct);
  }

  function renderTelegram() {
    var comm = state.data.comm;
    var user = state.data.user || {};
    var bindButton = state.root.querySelector('[data-action="bind-telegram"]');
    var groupButton = state.root.querySelector('[data-action="join-telegram"]');
    var view = buildTelegramViewModel(comm, user);
    if (bindButton) {
      bindButton.hidden = !view.bindVisible;
      bindButton.disabled = view.bindDisabled;
      bindButton.textContent = view.bindLabel;
    }
    if (groupButton) groupButton.hidden = !view.groupVisible;
    setText('[data-field="telegram-description"]', view.description);
  }

  function renderDownloads() {
    var data = state.data.downloads;
    ['android', 'windows', 'macos'].forEach(function (platform) {
      var button = state.root.querySelector('[data-download-platform="' + platform + '"]');
      var label = state.root.querySelector('[data-download-state="' + platform + '"]');
      var artifact = data ? selectLatestOfficialArtifact(data, platform) : null;
      if (button) button.disabled = !artifact;
      if (label) label.textContent = artifact ? 'v' + artifact.version : (data ? '暂未发布' : '加载失败');
    });
  }

  function renderNotices() {
    var region = state.root.querySelector('[data-region="notices"]');
    if (!region) return;
    region.innerHTML = '';
    var notices = selectLatestNotices(state.data.notices);
    if (notices.length === 0) {
      region.appendChild(createElement('p', 'er-v2-empty', state.errors.notices ? '公告暂不可用' : '暂无新公告'));
      return;
    }
    notices.forEach(function (notice, index) {
      var button = createElement('button', 'er-v2-notice-item');
      button.type = 'button';
      button.dataset.noticeIndex = String(index);
      var text = createElement('span');
      text.appendChild(createElement('strong', '', notice.title || '公告'));
      button.appendChild(text);
      button.appendChild(createElement('time', '', formatNoticeDate(notice.created_at)));
      button.addEventListener('click', function () { openNotice(notice); });
      region.appendChild(button);
    });
  }

  function renderServers() {
    var label = state.root.querySelector('[data-field="node-status"]');
    if (!label) return;
    var servers = state.data.servers;
    if (!Array.isArray(servers)) {
      label.textContent = '状态暂不可用';
      label.dataset.tone = 'muted';
      return;
    }
    if (servers.length === 0) {
      label.textContent = '暂无可用节点';
      label.dataset.tone = 'muted';
      return;
    }
    var online = servers.filter(function (server) { return Boolean(server && server.is_online); }).length;
    label.textContent = online + ' / ' + servers.length + ' 在线';
    label.dataset.tone = online > 0 ? 'success' : 'danger';
  }

  function renderOrders() {
    var label = state.root.querySelector('[data-field="order-status"]');
    if (!label) return;
    label.hidden = Boolean(state.errors.orders) || !hasUnfinishedOrders(state.data.orders);
  }

  function renderInvite() {
    var region = state.root.querySelector('[data-region="invite"]');
    if (!region) return;
    region.innerHTML = '';
    var invite = state.data.invite;
    var commissionBadge = state.root.querySelector('[data-field="commission-rate"]');
    var commissionLabel = buildInviteCommissionLabel(invite);
    if (commissionBadge) {
      commissionBadge.textContent = commissionLabel;
      commissionBadge.hidden = !commissionLabel;
    }
    var codes = invite && Array.isArray(invite.codes) ? invite.codes : [];
    var code = codes[0] && codes[0].code ? String(codes[0].code) : '';
    if (!code) {
      region.appendChild(createElement('p', 'er-v2-description', state.errors.invite ? '邀请信息暂不可用' : '生成邀请码后即可开始推广返利。'));
      if (!state.errors.invite) {
        var generate = createElement('button', 'er-v2-button er-v2-button-primary', '生成邀请码');
        generate.type = 'button';
        generate.dataset.action = 'generate-invite';
        region.appendChild(generate);
      }
      return;
    }

    var inviteLink = buildInviteLink(global.location.origin, code);
    state.data.activeInviteCode = code;
    state.data.activeInviteLink = inviteLink;
    var codeRow = createInviteValue('邀请码', code, 'copy-invite-code', '复制邀请码');
    var linkRow = createInviteValue('专属邀请链接', inviteLink, 'copy-invite-link', '复制链接');
    region.appendChild(codeRow);
    region.appendChild(linkRow);
  }

  function createInviteValue(label, value, action, actionLabel) {
    var row = createElement('div', 'er-v2-invite-value');
    var text = createElement('div');
    text.appendChild(createElement('span', '', label));
    text.appendChild(createElement('strong', '', value));
    var button = createElement('button', 'er-v2-copy-button', actionLabel);
    button.type = 'button';
    button.dataset.action = action;
    row.appendChild(text);
    row.appendChild(button);
    return row;
  }

  function copySubscription() {
    var subscribe = state.data.subscribe;
    if (!subscribe || !subscribe.subscribe_url) {
      notify('error', '订阅链接暂不可用');
      return;
    }
    copyText(subscribe.subscribe_url, '订阅链接已复制');
  }

  function openLegacySubscriptionImport() {
    var target = findTextElementOutsideV2(['一键订阅']);
    var clickable = target && target.closest('button, a, [role="button"], .cursor-pointer, .n-list-item, .n-card');
    if (clickable && typeof clickable.click === 'function') {
      clickable.click();
      return;
    }
    copySubscription();
    notify('info', '订阅链接已复制，请在客户端中导入');
  }

  function joinTelegramGroup() {
    var comm = state.data.comm || {};
    if (!comm.telegram_discuss_link) {
      notify('error', '群组链接暂未配置');
      return;
    }
    global.open(comm.telegram_discuss_link, '_blank', 'noopener,noreferrer');
  }

  function openTelegramBinding() {
    var user = state.data.user || {};
    if (user.telegram_id) {
      notify('success', 'Telegram Bot 已绑定');
      return;
    }
    if (!(state.data.comm && Number(state.data.comm.is_telegram))) {
      notify('error', 'Telegram Bot 暂未启用');
      return;
    }

    openModal('绑定 Telegram', '两步完成绑定', function (body, footer) {
      body.appendChild(createElement('p', 'er-v2-modal-loading', '正在加载 Bot 信息…'));
      addModalCloseButton(footer);
    });
    Promise.all([requestJson(ENDPOINTS.telegramBot), requestJson(ENDPOINTS.subscribe)]).then(function (payloads) {
      if (!state.root) return;
      var bot = getResponseData(payloads[0]) || {};
      var subscribe = getResponseData(payloads[1]) || {};
      var body = getModalBody();
      if (!body) return;
      body.innerHTML = '';
      var stepOne = createElement('div', 'er-v2-modal-step');
      stepOne.appendChild(createElement('span', 'er-v2-step-number', '01'));
      var oneText = createElement('div');
      oneText.appendChild(createElement('strong', '', '打开 Telegram Bot'));
      var botLink = createElement('a', '', '@' + (bot.username || 'Telegram Bot'));
      botLink.href = 'https://t.me/' + encodeURIComponent(bot.username || '');
      botLink.target = '_blank';
      botLink.rel = 'noopener noreferrer';
      oneText.appendChild(botLink);
      stepOne.appendChild(oneText);

      var command = '/bind ' + (subscribe.subscribe_url || '');
      var stepTwo = createElement('div', 'er-v2-modal-step');
      stepTwo.appendChild(createElement('span', 'er-v2-step-number', '02'));
      var twoText = createElement('div');
      twoText.appendChild(createElement('strong', '', '向 Bot 发送绑定命令'));
      var code = createElement('button', 'er-v2-bind-command', command);
      code.type = 'button';
      code.addEventListener('click', function () { copyText(command, '绑定命令已复制'); });
      twoText.appendChild(code);
      stepTwo.appendChild(twoText);
      body.appendChild(stepOne);
      body.appendChild(stepTwo);
    }).catch(function (error) {
      var body = getModalBody();
      if (body) body.textContent = error.message || '绑定信息加载失败';
    });
  }

  function openContact() {
    var button = document.getElementById('dify-chatbot-bubble-button');
    if (button && typeof button.click === 'function') {
      button.click();
      return;
    }
    if (state.data.dify) {
      global.location.assign('/support/ai?open_ai_support=1');
      return;
    }
    navigate('#/ticket');
  }

  function generateInviteCode(button) {
    if (button) button.disabled = true;
    requestJson(ENDPOINTS.inviteSave).then(function (payload) {
      if (getResponseData(payload) !== true) throw new Error('邀请码生成失败');
      return requestJson(ENDPOINTS.invite);
    }).then(function (payload) {
      state.data.invite = getResponseData(payload);
      renderInvite();
      notify('success', '邀请码已生成');
    }).catch(function (error) {
      notify('error', error.message || '邀请码生成失败');
      if (button) button.disabled = false;
    });
  }

  function copyInviteCode() {
    if (state.data.activeInviteCode) copyText(state.data.activeInviteCode, '邀请码已复制');
  }

  function copyInviteLink() {
    if (state.data.activeInviteLink) copyText(state.data.activeInviteLink, '邀请链接已复制');
  }

  function startPlatformDownload(platform) {
    if (platform === 'ios') {
      global.open(KARING_APP_STORE_URL, '_blank', 'noopener,noreferrer');
      return;
    }
    var downloads = state.data.downloads;
    var artifact = downloads ? selectLatestOfficialArtifact(downloads, platform) : null;
    if (!artifact) {
      notify('error', '当前平台暂未发布官方安装包');
      return;
    }
    if (!getStoredAccessToken()) {
      notify('info', '请先登录后下载');
      var downloadPath = '/download/index.html';
      var appRedirect = '/dashboard?download_redirect=' + encodeURIComponent(downloadPath);
      global.location.href = '/app#/login?redirect=' + encodeURIComponent(appRedirect);
      return;
    }
    var turnstile = downloads.turnstile || { enabled: true, site_key: '' };
    if (!turnstile.enabled) {
      prepareDownload(artifact.artifact_id, '');
      return;
    }
    openDownloadVerification(artifact.artifact_id, turnstile);
  }

  function ensureTurnstileScript() {
    if (global.turnstile && global.turnstile.render) return Promise.resolve();
    if (state.turnstileScriptPromise) return state.turnstileScriptPromise;
    state.turnstileScriptPromise = new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      var timer = global.setTimeout(function () { reject(new Error('安全验证加载超时')); }, 30000);
      script.async = true;
      script.defer = true;
      script.src = TURNSTILE_SCRIPT_SRC;
      script.onload = function () {
        global.clearTimeout(timer);
        if (global.turnstile && global.turnstile.render) resolve();
        else reject(new Error('安全验证不可用'));
      };
      script.onerror = function () {
        global.clearTimeout(timer);
        reject(new Error('安全验证加载失败'));
      };
      document.head.appendChild(script);
    });
    return state.turnstileScriptPromise;
  }

  function openDownloadVerification(artifactId, config) {
    if (!config.site_key) {
      notify('error', '下载验证尚未正确配置');
      return;
    }
    openModal('下载安全验证', '验证完成后自动开始下载', function (body) {
      var widget = createElement('div', 'er-v2-turnstile');
      widget.id = 'er-v2-turnstile-widget';
      body.appendChild(widget);
      body.appendChild(createElement('p', 'er-v2-modal-help', '正在加载 Cloudflare 安全校验…'));
    });
    ensureTurnstileScript().then(function () {
      var widget = document.getElementById('er-v2-turnstile-widget');
      if (!widget) return;
      state.turnstileWidgetId = global.turnstile.render(widget, {
        sitekey: config.site_key,
        callback: function (token) { prepareDownload(artifactId, token); },
        'expired-callback': function () { notify('info', '验证已过期，请重新完成校验'); },
        'error-callback': function () { notify('error', '安全验证失败，请重试'); }
      });
      var help = widget.parentElement.querySelector('.er-v2-modal-help');
      if (help) help.textContent = '完成验证后将生成短期下载链接。';
    }).catch(function (error) {
      var body = getModalBody();
      if (body) body.textContent = error.message || '安全验证加载失败';
    });
  }

  function prepareDownload(artifactId, turnstileToken) {
    var path = '/api/v1/user/app-downloads/{artifact}/prepare'
      .replace('{artifact}', encodeURIComponent(String(artifactId)));
    requestJson(path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ turnstile_token: turnstileToken })
    }).then(function (payload) {
      var data = getResponseData(payload) || {};
      if (!data.download_url) throw new Error('下载链接生成失败');
      closeModal();
      global.location.assign(data.download_url);
    }).catch(function (error) {
      if (error.status === 401 || error.status === 403) {
        closeModal();
        var downloadPath = '/download/index.html';
        var appRedirect = '/dashboard?download_redirect=' + encodeURIComponent(downloadPath);
        global.location.href = '/app#/login?redirect=' + encodeURIComponent(appRedirect);
        return;
      }
      notify('error', error.message || '下载准备失败');
      if (error.status !== 429 && global.turnstile && state.turnstileWidgetId !== null && global.turnstile.reset) {
        global.turnstile.reset(state.turnstileWidgetId);
      }
    });
  }

  function openNotice(notice) {
    openModal(notice.title || '公告详情', formatNoticeDate(notice.created_at), function (body, footer) {
      var content = createElement('div', 'er-v2-notice-content');
      content.textContent = stripMarkup(notice.content || '暂无详情');
      body.appendChild(content);
      addModalCloseButton(footer);
    });
  }

  function openModal(title, kicker, render) {
    if (!state.root) return;
    var modal = getModal();
    if (!modal) return;
    var body = modal.querySelector('[data-modal-body]');
    var footer = modal.querySelector('[data-modal-footer]');
    body.innerHTML = '';
    footer.innerHTML = '';
    state.turnstileWidgetId = null;
    var titleNode = modal.querySelector('#er-v2-modal-title');
    var kickerNode = modal.querySelector('[data-modal-kicker]');
    if (titleNode) titleNode.textContent = title || '提示';
    if (kickerNode) kickerNode.textContent = kicker || '大象网络';
    if (render) render(body, footer);
    state.modalLastFocus = document.activeElement;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('er-v2-modal-open');
    var closeButton = modal.querySelector('.er-v2-modal-close');
    if (closeButton) closeButton.focus();
  }

  function addModalCloseButton(footer) {
    var button = createElement('button', 'er-v2-button er-v2-button-primary', '我知道了');
    button.type = 'button';
    button.dataset.action = 'close-modal';
    footer.appendChild(button);
  }

  function closeModal() {
    var modal = getModal();
    document.body.classList.remove('er-v2-modal-open');
    if (!modal || modal.getAttribute('aria-hidden') === 'true') return;
    modal.setAttribute('aria-hidden', 'true');
    if (global.turnstile && state.turnstileWidgetId !== null && global.turnstile.remove) {
      global.turnstile.remove(state.turnstileWidgetId);
    }
    state.turnstileWidgetId = null;
    if (state.modalLastFocus && typeof state.modalLastFocus.focus === 'function') state.modalLastFocus.focus();
  }

  function scheduleRefresh() {
    if (state.retryTimer !== null) return;
    function retry() {
      state.retryTimer = null;
      ensureSidebar();
      ensureMobileDrawer();
      if (isDashboardRoute()) {
        if (!mountDashboard()) state.retryTimer = global.setTimeout(retry, 180);
      } else {
        unmountDashboard();
      }
      updateSidebarActiveState();
    }
    state.retryTimer = global.setTimeout(retry, 0);
  }

  document.addEventListener('DOMContentLoaded', scheduleRefresh);
  global.addEventListener('hashchange', scheduleRefresh);
  global.addEventListener('popstate', scheduleRefresh);
  global.addEventListener('resize', scheduleRefresh);
  state.observer = new MutationObserver(function () { scheduleRefresh(); });
  state.observer.observe(document.documentElement, { childList: true, subtree: true });
  scheduleRefresh();
})(typeof window !== 'undefined' ? window : globalThis);
