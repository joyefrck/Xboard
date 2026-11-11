(function (root, factory) {
  var api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (!root || !root.document) return;
  if (root.document.body) api.mount(root);
  else root.document.addEventListener('DOMContentLoaded', function () { api.mount(root); }, { once: true });
})(typeof window !== 'undefined' ? window : null, function () {
  'use strict';
  var mounts = new WeakMap();

  function token(win) {
    try {
      var stored = JSON.parse(win.localStorage.getItem('VUE_NAIVE_ACCESS_TOKEN') || 'null');
      var value = stored && typeof stored === 'object' ? stored.value : stored;
      return typeof value === 'string' ? value : '';
    } catch (_) { return ''; }
  }

  function date(value) {
    if (!value) return '暂无记录';
    var parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? '暂无记录' : parsed.toLocaleString('zh-CN', { hour12: false });
  }

  function mount(win) {
    if (mounts.has(win)) return mounts.get(win);
    var doc = win.document;
    var card = null;
    var activeDialog = null;
    var rows = [];
    var loading = false;
    var listError = '';
    var loadedToken = '';
    var generation = 0;
    var submitting = false;
    var destroyed = false;
    var inProfile = false;

    function el(tag, className, text) {
      var node = doc.createElement(tag);
      if (className) node.className = className;
      if (text !== undefined) node.textContent = text;
      return node;
    }
    function button(text, className, handler) {
      var node = el('button', 'er-security-button ' + (className || ''), text);
      node.type = 'button';
      if (handler) node.addEventListener('click', handler);
      return node;
    }
    function statusMessage(node, text, failed) {
      node.textContent = text;
      node.classList.toggle('is-error', Boolean(failed));
    }
    function logout() {
      win.localStorage.removeItem('VUE_NAIVE_ACCESS_TOKEN');
      win.location.replace(win.location.pathname + '#/login');
    }
    async function request(path, data) {
      var auth = token(win);
      if (!auth) { logout(); throw new Error('请重新登录'); }
      var response = await win.fetch('/api/v1/user/' + path, {
        method: data === undefined ? 'GET' : 'POST', credentials: 'omit', cache: 'no-store',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: auth },
        body: data === undefined ? undefined : JSON.stringify(data)
      });
      var payload;
      try { payload = await response.json(); } catch (_) { throw new Error('服务器返回异常，请稍后重试'); }
      if (token(win) !== auth) throw new Error('登录状态已变化，请刷新页面');
      if (!response.ok || payload.status === 'fail') {
        var error = new Error(typeof payload.message === 'string' ? payload.message : '操作失败，请稍后重试');
        error.status = response.status;
        error.details = payload.error || {};
        if (response.status === 401 || response.status === 403) logout();
        throw error;
      }
      return payload.data;
    }

    function dialog(title, description, restoreFocus) {
      if (activeDialog) activeDialog.finish(false);
      var previousFocus = restoreFocus || doc.activeElement;
      var modal = el('dialog', 'er-security-dialog');
      var heading = el('h2', '', title);
      heading.id = 'er-security-dialog-heading';
      modal.setAttribute('aria-labelledby', heading.id);
      var intro = el('p', 'er-security-description', description);
      var body = el('div', 'er-security-dialog-body');
      var status = el('p', 'er-security-status');
      status.setAttribute('role', 'status');
      var actions = el('div', 'er-security-dialog-actions');
      modal.append(heading, intro, body, status, actions);
      doc.body.appendChild(modal);
      var resolve;
      var result = new Promise(function (done) { resolve = done; });
      var cleanup = function () {};
      var finished = false;
      var instance = { node: modal, body: body, status: status, actions: actions, result: result, busy: false,
        setCleanup: function (fn) { cleanup = fn; },
        finish: function (value) {
          if (finished) return;
          finished = true;
          cleanup();
          if (modal.open) modal.close();
          modal.remove();
          if (activeDialog === instance) activeDialog = null;
          if (previousFocus && previousFocus.isConnected && previousFocus.focus) previousFocus.focus();
          resolve(value);
        }
      };
      modal.addEventListener('cancel', function (event) { event.preventDefault(); if (!instance.busy) instance.finish(false); });
      activeDialog = instance;
      modal.showModal();
      return instance;
    }

    async function revoke(row) {
      if (loading) return;
      var all = !row;
      var box = dialog(all ? '退出全部设备' : '退出这个设备', all
        ? '全部网页和 App 登录会话将失效，包含当前设备。你需要重新登录。'
        : (row.is_current ? '当前设备将退出并返回登录页。' : '这个会话将失效，对应设备下次请求时需要重新登录。'));
      var cancel = button('取消', '', function () { box.finish(false); });
      var confirm = button(all ? '退出全部设备' : '确认退出', 'er-security-danger', async function () {
        box.busy = true;
        confirm.disabled = cancel.disabled = true;
        statusMessage(box.status, '正在退出…');
        try {
          await request(all ? 'removeAllActiveSessions' : 'removeActiveSession', all ? {} : { session_id: row.id });
          box.finish(true);
          if (all || row.is_current) logout();
          else await load();
        } catch (error) {
          box.busy = false;
          statusMessage(box.status, error.message, true);
          confirm.disabled = cancel.disabled = false;
        }
      });
      box.actions.append(cancel, confirm);
      cancel.focus();
    }

    function render() {
      if (!card || !card.isConnected) return;
      card.replaceChildren();
      var header = el('header', 'er-session-header');
      var title = el('div');
      title.append(el('h2', '', '登录设备'), el('p', 'er-security-description', '管理网页和 App 登录会话，发现陌生设备请及时退出。'));
      var controls = el('div', 'er-session-controls');
      var refresh = button('刷新', '', load);
      var all = button('退出全部设备', 'er-security-danger', function () { revoke(null); });
      refresh.disabled = loading;
      all.disabled = loading || !rows.length;
      controls.append(refresh, all);
      header.append(title, controls);
      card.appendChild(header);
      if (loading || listError || !rows.length) {
        var state = el('p', 'er-session-empty', loading ? '正在加载登录设备…' : (listError || '暂无有效登录会话'));
        state.setAttribute('role', 'status');
        card.appendChild(state);
      } else {
        var list = el('div', 'er-session-list');
        list.setAttribute('role', 'list');
        rows.forEach(function (row) {
          var item = el('div', 'er-session-row');
          item.setAttribute('role', 'listitem');
          var identity = el('div', 'er-session-identity');
          var kind = row.client_type === 'app' ? 'App' : (row.client_type === 'browser' ? '网页' : '会话');
          var icon = el('span', 'er-session-kind', kind);
          var name = el('div', 'er-session-name');
          name.appendChild(el('strong', '', row.client_label || '未知客户端'));
          if (row.is_current) name.appendChild(el('span', 'er-session-current', '当前设备'));
          var device = el('div', 'er-session-device');
          device.append(name, el('span', 'er-security-description', row.client_os || '设备信息未知'));
          identity.append(icon, device);
          var source = el('div', 'er-session-details');
          source.append(el('span', '', '登录 IP：' + (row.login_ip || '历史记录未知')),
            el('span', '', '最近 IP：' + (row.last_ip || '暂无记录')));
          var activity = el('div', 'er-session-details');
          activity.append(el('span', '', '登录：' + date(row.created_at)), el('span', '', '最近活动：' + date(row.last_used_at)));
          var exit = button('退出', '', function () { revoke(row); });
          exit.setAttribute('aria-label', '退出 ' + (row.client_label || '未知客户端') + (row.is_current ? ' 当前设备' : ''));
          item.append(identity, source, activity, exit);
          list.appendChild(item);
        });
        card.appendChild(list);
      }
      card.appendChild(el('p', 'er-session-note', '按登录会话列出，同一设备可能有多条记录；最近活动不代表实时在线。退出登录不会重置订阅。'));
    }

    async function load() {
      var stamp = generation;
      var auth = token(win);
      if (loading || !inProfile || !auth) return;
      loading = true;
      listError = '';
      render();
      try {
        var data = await request('getActiveSession');
        if (stamp !== generation || token(win) !== auth) return;
        rows = Array.isArray(data) ? data : [];
      } catch (error) {
        if (stamp !== generation) return;
        listError = error.message;
      } finally {
        if (stamp === generation) { loading = false; render(); }
      }
    }

    function clearPasswords(form) {
      form.querySelectorAll('input').forEach(function (input) {
        input.value = '';
        input.dispatchEvent(new win.Event('input', { bubbles: true }));
      });
    }

    async function verifyEmail(passwords, maskedEmail, restoreFocus) {
      var box = dialog('安全校验', '请使用 ' + (maskedEmail || '账号邮箱') + ' 接收验证码，完成本次操作。', restoreFocus);
      var form = el('form', 'er-security-code-form');
      var label = el('label', 'er-security-code-label', '邮箱验证码');
      var input = el('input', 'er-security-code');
      input.id = 'er-security-email-code';
      label.htmlFor = input.id;
      input.type = 'text'; input.inputMode = 'numeric'; input.autocomplete = 'one-time-code';
      input.maxLength = 6; input.pattern = '[0-9]{6}'; input.required = true;
      input.placeholder = '输入 6 位验证码';
      var line = el('div', 'er-security-code-line');
      var send = button('发送验证码', '', async function () {
        if (sending || busy) return;
        sending = true;
        send.disabled = true;
        statusMessage(box.status, '正在发送…');
        try {
          var data = await request('sendPasswordChangeCode', { old_password: passwords.old_password });
          if (!box.node.isConnected) return;
          until = Date.now() + (Number(data.cooldown_seconds) || 60) * 1000;
          statusMessage(box.status, '验证码已发送，请查收邮箱。');
          tick();
          input.focus();
        } catch (error) { statusMessage(box.status, error.message, true); }
        finally { sending = false; tick(); }
      });
      var until = 0;
      var sending = false;
      function tick() {
        var remaining = Math.max(0, Math.ceil((until - Date.now()) / 1000));
        send.disabled = busy || sending || remaining > 0;
        send.textContent = remaining ? remaining + ' 秒后重发' : (until ? '重新发送' : '发送验证码');
      }
      var timer = win.setInterval(tick, 1000);
      line.append(input, send); form.append(label, line); box.body.appendChild(form);
      var cancel = button('取消', '', function () { box.finish(false); });
      var submit = button('确认', 'er-security-primary');
      submit.type = 'submit'; submit.setAttribute('form', 'er-security-code-form'); form.id = 'er-security-code-form';
      var busy = false;
      form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        busy = true; box.busy = true; submit.disabled = send.disabled = cancel.disabled = true;
        statusMessage(box.status, '正在验证…');
        try {
          await request('changePassword', Object.assign({}, passwords, { email_code: input.value.trim() }));
          box.finish(true);
        } catch (error) {
          statusMessage(box.status, error.message, true);
          busy = false; box.busy = false; submit.disabled = cancel.disabled = false; tick();
        }
      });
      box.actions.append(cancel, submit);
      box.setCleanup(function () {
        win.clearInterval(timer);
        input.value = '';
        passwords.old_password = passwords.new_password = '';
      });
      input.focus();
      return box.result;
    }

    async function submitPassword(event) {
      var form = event.target;
      if (!inProfile || !form.matches('.er-profile-password form')) return;
      event.preventDefault(); event.stopImmediatePropagation();
      if (submitting) return;
      var inputs = form.querySelectorAll('input');
      if (inputs.length !== 3) return;
      var status = form.querySelector('.er-security-status');
      if (!status) { status = el('p', 'er-security-status'); status.setAttribute('role', 'status'); form.appendChild(status); }
      var passwords = { old_password: inputs[0].value, new_password: inputs[1].value };
      if (!passwords.old_password || Array.from(passwords.new_password).length < 8 || Array.from(passwords.new_password).length > 128) {
        statusMessage(status, '请输入当前密码，并设置 8–128 位新密码', true); return;
      }
      if (passwords.new_password !== inputs[2].value) { statusMessage(status, '两次输入的新密码不一致', true); return; }
      submitting = true;
      var save = form.querySelector('button[type="submit"]');
      if (save) save.disabled = true;
      statusMessage(status, '正在验证…');
      var success = false;
      try {
        try { await request('changePassword', passwords); success = true; }
        catch (error) {
          if (error.status !== 428 || error.details.code !== 'EMAIL_VERIFICATION_REQUIRED') throw error;
          if (save) save.disabled = false;
          success = await verifyEmail(passwords, error.details.masked_email, save);
        }
        statusMessage(status, success ? '密码已修改，旧会话已退出，请重新登录。' : '已取消，密码未修改');
        if (success) { clearPasswords(form); logout(); }
      } catch (error) { statusMessage(status, error.message, true); }
      finally {
        passwords.old_password = passwords.new_password = '';
        submitting = false;
        if (save) save.disabled = false;
      }
    }

    function sync() {
      if (destroyed) return;
      var profile = String(win.location.hash || '').split('?')[0] === '#/profile';
      if (!profile) {
        if (inProfile) {
          generation++; loading = false; loadedToken = ''; rows = [];
          if (activeDialog) activeDialog.finish(false);
          if (card) card.remove();
          card = null;
        }
        inProfile = false; return;
      }
      inProfile = true;
      var grid = doc.querySelector('.er-profile-grid');
      if (!grid) return;
      if (!card || !card.isConnected) {
        card = el('section', 'er-session-card'); card.id = 'er-login-devices';
        grid.insertBefore(card, grid.querySelector('.er-profile-danger'));
        render();
      }
      var auth = token(win);
      if (auth !== loadedToken) {
        generation++; loading = false; rows = []; listError = ''; loadedToken = auth;
        render();
        if (auth) load();
      }
    }
    var observer = new win.MutationObserver(sync);
    observer.observe(doc.body, { childList: true, subtree: true });
    doc.addEventListener('submit', submitPassword, true);
    win.addEventListener('hashchange', sync);
    win.addEventListener('popstate', sync);
    function unmount() {
      destroyed = true; generation++;
      observer.disconnect();
      doc.removeEventListener('submit', submitPassword, true);
      win.removeEventListener('hashchange', sync); win.removeEventListener('popstate', sync);
      if (activeDialog) activeDialog.finish(false);
      if (card) card.remove();
      mounts.delete(win);
    }
    mounts.set(win, unmount); sync();
    return unmount;
  }
  return { mount: mount, formatDate: date };
});
