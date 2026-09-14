(function (root, factory) {
  var api = factory();

  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  }

  if (!root || !root.document) return;

  function start() {
    api.mount(root);
  }

  if (root.document.body) {
    start();
  } else {
    root.document.addEventListener('DOMContentLoaded', start, { once: true });
  }
})(typeof window !== 'undefined' ? window : null, function () {
  'use strict';

  var PAGE_ROUTES = Object.freeze({
    '/plan': 'plan',
    '/invite': 'invite',
    '/knowledge': 'knowledge',
    '/order': 'order',
    '/profile': 'profile',
    '/ticket': 'ticket',
    '/node': 'node'
  });

  var USER_SHELL_ROUTES = Object.freeze({
    '/dashboard': true,
    '/plan': true,
    '/invite': true,
    '/knowledge': true,
    '/order': true,
    '/profile': true,
    '/ticket': true,
    '/node': true
  });

  var mountedWindows = typeof WeakMap === 'function' ? new WeakMap() : null;

  function normalizeRoute(hash) {
    var route = String(hash || '').trim().replace(/^#/, '');
    var queryIndex = route.indexOf('?');
    if (queryIndex !== -1) route = route.slice(0, queryIndex);
    if (!route) return '/';
    if (route.charAt(0) !== '/') route = '/' + route;
    route = route.replace(/\/{2,}/g, '/').replace(/\/$/, '');
    return route || '/';
  }

  function resolvePageKey(hash) {
    var route = normalizeRoute(hash);
    if (route.indexOf('/plan/') === 0) return 'checkout';
    if (route.indexOf('/order/') === 0) return 'order-detail';
    return PAGE_ROUTES[route] || '';
  }

  function isUserShellRoute(hash) {
    var route = normalizeRoute(hash);
    return Boolean(
      USER_SHELL_ROUTES[route]
      || route.indexOf('/plan/') === 0
      || route.indexOf('/order/') === 0
    );
  }

  function applyRouteMarkers(targetWindow) {
    var body = targetWindow && targetWindow.document && targetWindow.document.body;
    if (!body) return;

    var hash = targetWindow.location && targetWindow.location.hash;
    var pageKey = resolvePageKey(hash);

    if (pageKey) {
      body.setAttribute('data-er-page', pageKey);
    } else {
      body.removeAttribute('data-er-page');
    }

    if (isUserShellRoute(hash)) {
      body.setAttribute('data-er-user-shell', 'true');
    } else {
      body.removeAttribute('data-er-user-shell');
    }
  }

  function normalizeInviteCopyLabels(targetWindow) {
    var targetDocument = targetWindow && targetWindow.document;
    var hash = targetWindow && targetWindow.location && targetWindow.location.hash;
    if (resolvePageKey(hash) !== 'invite' || !targetDocument || typeof targetDocument.querySelectorAll !== 'function') {
      return;
    }

    var buttons = targetDocument.querySelectorAll('button');
    Array.prototype.forEach.call(buttons, function (button) {
      if (String(button.textContent || '').trim() !== '复制链接') return;
      var content = typeof button.querySelector === 'function' && button.querySelector('.n-button__content');
      (content || button).textContent = '复制';
      if (typeof button.setAttribute === 'function') button.setAttribute('aria-label', '复制邀请码');
    });
  }

  function syncRoutePresentation(targetWindow) {
    applyRouteMarkers(targetWindow);
    normalizeInviteCopyLabels(targetWindow);
  }

  function createPurchaseNotice(targetWindow) {
    var doc = targetWindow.document;
    var previousRoute = '';
    var acknowledged = false;
    var dialog = null;

    function close() {
      if (!dialog) return;
      var current = dialog;
      dialog = null;
      current.close();
      current.remove();
      doc.body.removeAttribute('data-er-purchase-notice');
    }

    function returnToStore() {
      acknowledged = false;
      close();
      targetWindow.location.hash = '#/plan';
      sync();
    }

    function sync() {
      var route = normalizeRoute(targetWindow.location.hash);
      if (route === previousRoute) return;
      var previousPage = resolvePageKey(previousRoute);
      var page = resolvePageKey(route);
      previousRoute = route;
      close();

      if (page !== 'checkout' && page !== 'order-detail') {
        acknowledged = false;
        return;
      }
      // Creating an order after acknowledging its checkout is one purchase flow.
      if (acknowledged && previousPage === 'checkout' && page === 'order-detail') return;
      acknowledged = false;

      dialog = doc.createElement('dialog');
      dialog.className = 'er-purchase-notice';
      dialog.setAttribute('aria-labelledby', 'er-purchase-notice-title');
      dialog.setAttribute('aria-describedby', 'er-purchase-notice-description');
      dialog.innerHTML = '<div class="er-purchase-notice__eyebrow">购买前请阅读</div>'
        + '<h2 id="er-purchase-notice-title">服务支持与反馈须知</h2>'
        + '<div id="er-purchase-notice-description" class="er-purchase-notice__content">'
        + '<p>如您在购买、支付或节点使用过程中遇到问题，请通过<strong>站内工单</strong>或<strong>官方 Telegram 群</strong>联系我们，客服会及时跟进处理。</p>'
        + '<p>为便于核实和解决问题，请统一通过上述官方渠道反馈，勿通过其他渠道发起投诉或施压。</p>'
        + '<p class="er-purchase-notice__policy">违反此约定的行为一经核实，我们将<strong>停止提供服务并停用相关账号</strong>。感谢您的理解与配合。</p>'
        + '</div><div class="er-purchase-notice__actions">'
        + '<button type="button" data-er-notice-back>返回商店</button>'
        + '<button type="button" class="er-purchase-notice__continue" data-er-notice-continue>我已了解，继续</button>'
        + '</div>';
      dialog.querySelector('[data-er-notice-back]').addEventListener('click', returnToStore);
      dialog.querySelector('[data-er-notice-continue]').addEventListener('click', function () {
        acknowledged = true;
        close();
      });
      dialog.addEventListener('cancel', function (event) {
        event.preventDefault();
        returnToStore();
      });
      doc.body.appendChild(dialog);
      doc.body.setAttribute('data-er-purchase-notice', 'open');
      // A modal dialog uses the browser top layer and makes the background inert.
      dialog.showModal();
    }

    return { sync: sync, destroy: close };
  }

  function mount(targetWindow) {
    if (!targetWindow || !targetWindow.document || !targetWindow.document.body) {
      return function () {};
    }

    if (mountedWindows && mountedWindows.has(targetWindow)) {
      return mountedWindows.get(targetWindow);
    }

    var active = true;
    var purchaseNotice = createPurchaseNotice(targetWindow);
    var update = function () {
      if (!active) return;
      syncRoutePresentation(targetWindow);
      purchaseNotice.sync();
    };

    var observer = null;

    targetWindow.addEventListener('hashchange', update);
    targetWindow.addEventListener('popstate', update);
    if (typeof targetWindow.MutationObserver === 'function') {
      observer = new targetWindow.MutationObserver(update);
      observer.observe(targetWindow.document.documentElement || targetWindow.document.body, {
        childList: true,
        subtree: true
      });
    }
    update();

    var unmount = function () {
      if (!active) return;
      active = false;
      targetWindow.removeEventListener('hashchange', update);
      targetWindow.removeEventListener('popstate', update);
      if (observer) observer.disconnect();
      purchaseNotice.destroy();
      targetWindow.document.body.removeAttribute('data-er-page');
      targetWindow.document.body.removeAttribute('data-er-user-shell');
      if (mountedWindows) mountedWindows.delete(targetWindow);
    };

    if (mountedWindows) mountedWindows.set(targetWindow, unmount);
    return unmount;
  }

  return {
    PAGE_ROUTES: PAGE_ROUTES,
    normalizeRoute: normalizeRoute,
    resolvePageKey: resolvePageKey,
    isUserShellRoute: isUserShellRoute,
    applyRouteMarkers: applyRouteMarkers,
    normalizeInviteCopyLabels: normalizeInviteCopyLabels,
    syncRoutePresentation: syncRoutePresentation,
    createPurchaseNotice: createPurchaseNotice,
    mount: mount
  };
});
