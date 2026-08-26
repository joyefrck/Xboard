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

  function mount(targetWindow) {
    if (!targetWindow || !targetWindow.document || !targetWindow.document.body) {
      return function () {};
    }

    if (mountedWindows && mountedWindows.has(targetWindow)) {
      return mountedWindows.get(targetWindow);
    }

    var active = true;
    var update = function () {
      if (active) syncRoutePresentation(targetWindow);
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
    mount: mount
  };
});
