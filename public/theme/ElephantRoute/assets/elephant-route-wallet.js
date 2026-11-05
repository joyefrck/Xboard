(function (root, factory) {
  var api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.ElephantRouteWallet = api;
})(typeof window !== 'undefined' ? window : null, function () {
  'use strict';
  var amounts = Object.freeze([10000, 20000, 50000]);
  var checkouts = new Map();

  function fee(amount, method) {
    if (!method) return 0;
    return Math.round(amount * Number(method.handling_fee_percent || 0) / 100 + Number(method.handling_fee_fixed || 0));
  }
  function money(amount) { return (Number(amount || 0) / 100).toFixed(2); }
  function isRecharge(order) { return Number(order && order.type) === 6 && order.period === 'recharge'; }

  function createComponents(runtime) {
    var h = runtime.h, ref = runtime.ref, mounted = runtime.onMounted, unmount = runtime.onBeforeUnmount;
    var Button = runtime.Button, Card = runtime.Card;
    function button(text, props) { return h(Button, props, { default: function () { return text; } }); }
    function refreshOnFocus(user) {
      var alive = true;
      function refresh() { if (alive) user.getUserInfo().catch(function () {}); }
      mounted(function () { refresh(); window.addEventListener('focus', refresh); });
      unmount(function () { alive = false; window.removeEventListener('focus', refresh); });
    }
    var Balance = runtime.defineComponent({
      name: 'ElephantRouteWalletBalance',
      setup: function () {
        var user = runtime.useUser(), router = runtime.useRouter(), route = runtime.useRoute();
        refreshOnFocus(user);
        return function () {
          if (route.path !== '/dashboard') return null;
          return h('div', { class: 'er-wallet-shortcut' }, [
            h('span', { class: 'er-wallet-shortcut-balance' }, ['账户余额：', h('strong', null, money(user.balance)), ' 元']),
            button('充值', { type: 'primary', onClick: function () { router.push('/wallet/recharge'); } })
          ]);
        };
      }
    });
    var Recharge = runtime.defineComponent({
      name: 'ElephantRouteWalletRecharge',
      setup: function () {
        var user = runtime.useUser(), router = runtime.useRouter(), api = runtime.request;
        var amount = ref(amounts[0]), methods = ref([]), selected = ref(null);
        var loading = ref(true), error = ref(''), busy = ref(false), trade = ref('');
        var alive = true;
        unmount(function () { alive = false; });
        refreshOnFocus(user);
        async function load() {
          loading.value = true; error.value = '';
          try {
            var response = await api.get('/user/order/getPaymentMethod');
            if (!alive) return;
            if (!response || !Array.isArray(response.data)) throw new Error('Payment methods unavailable');
            methods.value = response.data;
            selected.value = methods.value[0] || null;
          } catch (_) { if (alive) error.value = '支付通道加载失败，请重试'; }
          finally { if (alive) loading.value = false; }
        }
        async function submit() {
          if (busy.value || loading.value || !selected.value || !amounts.includes(amount.value)) return;
          busy.value = true; error.value = '';
          try {
            if (!trade.value) {
              var created = await api.post('/user/order/recharge', { amount: amount.value });
              if (!created || typeof created.data !== 'string' || !created.data) {
                throw Object.assign(new Error('Recharge creation failed'), { walletMessage: created && created.message });
              }
              trade.value = created.data;
            }
            var result = await api.post('/user/order/checkout', { trade_no: trade.value, method: selected.value.id });
            if (!result || !(((result.type === 0 || result.type === 1) && typeof result.data === 'string' && result.data)
              || (result.type === -1 && result.data === true))) {
              throw Object.assign(new Error('Checkout failed'), { walletMessage: result && result.message });
            }
            if (result.type === 1 && typeof result.data === 'string') {
              window.location.assign(result.data);
            } else {
              if (result.type === 0) checkouts.set(trade.value, result.data);
              await router.push('/order/' + trade.value);
              user.getUserInfo().catch(function () {});
            }
          } catch (failure) {
            if (alive) error.value = (failure && failure.walletMessage) || (trade.value ? '支付未完成，可重试或查看订单继续支付' : '充值订单创建失败，请处理页面提示后重试');
          } finally { if (alive) busy.value = false; }
        }
        mounted(load);
        function row(label, value, className) {
          return h('div', { class: 'er-wallet-summary-row ' + (className || '') }, [h('span', null, label), h('strong', null, value)]);
        }
        return function () {
          var handling = fee(amount.value, selected.value);
          return h(runtime.Layout, null, { default: function () { return h('main', { class: 'er-wallet-page' }, [
            h('header', { class: 'er-wallet-heading' }, [
              h('div', null, [h('h1', null, '余额充值'), h('p', null, '充值到账户余额，可用于购买套餐和流量包')]),
              h('div', { class: 'er-wallet-current' }, ['当前余额', h('strong', null, '¥ ' + money(user.balance))])
            ]),
            h(Card, { class: 'er-wallet-card' }, { default: function () { return [
              h('section', { class: 'er-wallet-step', 'aria-labelledby': 'er-wallet-amount-title' }, [
                h('h2', { id: 'er-wallet-amount-title' }, [h('span', { class: 'er-wallet-step-number' }, '1'), '选择充值金额']),
                h('div', { class: 'er-wallet-amounts', role: 'group', 'aria-label': '充值金额' }, amounts.map(function (value) {
                  return button(money(value).replace('.00', '') + ' 元', {
                    class: 'er-wallet-amount' + (amount.value === value ? ' is-selected' : ''),
                    'aria-pressed': amount.value === value, disabled: busy.value || !!trade.value,
                    onClick: function () { amount.value = value; }
                  });
                }))
              ]),
              h('section', { class: 'er-wallet-step', 'aria-labelledby': 'er-wallet-payment-title' }, [
                h('h2', { id: 'er-wallet-payment-title' }, [h('span', { class: 'er-wallet-step-number' }, '2'), '选择支付通道']),
                loading.value ? h('p', { role: 'status', class: 'er-wallet-hint' }, '正在加载支付通道…') : null,
                !loading.value && !methods.value.length && !error.value ? h('p', { class: 'er-wallet-hint' }, '暂无可用支付通道') : null,
                h('div', { class: 'er-wallet-methods', role: 'group', 'aria-label': '支付通道' }, methods.value.map(function (method) {
                  return button(h('span', { class: 'er-wallet-method-content' }, [
                    h('span', { class: 'er-wallet-method-name' }, method.name),
                    method.icon ? h('img', { src: method.icon, alt: '', onError: function (event) { event.target.hidden = true; } }) : null,
                    h('span', { class: 'er-wallet-method-check', 'aria-hidden': 'true' }, selected.value === method ? '✓' : '')
                  ]), {
                    class: 'er-wallet-method' + (selected.value === method ? ' is-selected' : ''),
                    'aria-pressed': selected.value === method, disabled: busy.value,
                    onClick: function () { selected.value = method; }
                  });
                }))
              ]),
              error.value ? h('div', { class: 'er-wallet-error', role: 'alert' }, [error.value,
                !methods.value.length ? button('重新加载', { onClick: load, disabled: loading.value }) : null,
                trade.value ? button('查看订单', { onClick: function () { router.push('/order/' + trade.value); } }) : null
              ]) : null,
              h('footer', { class: 'er-wallet-summary' }, [
                h('div', { class: 'er-wallet-summary-grid' }, [
                  row('充值金额', '¥ ' + money(amount.value)), row('支付手续费', '¥ ' + money(handling)),
                  row('实付金额', '¥ ' + money(amount.value + handling), 'er-wallet-total')
                ]),
                h('p', { class: 'er-wallet-hint' }, '到账 ¥ ' + money(amount.value) + '，手续费按所选支付通道配置收取'),
                button(trade.value ? '继续支付' : '支付', { class: 'er-wallet-pay', type: 'primary', loading: busy.value,
                  disabled: busy.value || loading.value || !selected.value, onClick: submit })
              ])
            ]; } })
          ]); } });
        };
      }
    });
    return { Recharge: Recharge, Balance: Balance };
  }
  return { amounts: amounts, fee: fee, money: money, isRecharge: isRecharge, createComponents: createComponents,
    takeCheckout: function (trade) { var result = checkouts.get(trade); checkouts.delete(trade); return result; } };
});
