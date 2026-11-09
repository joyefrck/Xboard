(function (root) {
  'use strict';
  function renderAmount(h, order, format) {
    if (Number(order.status) !== 5) return format(order.total_amount);
    var refund = order.refund;
    var amount = refund && Number.isSafeInteger(Number(refund.amount)) ? Number(refund.amount) : null;
    return h('div', { class: 'er-order-refund-amount', style: { fontSize: 'inherit', lineHeight: '1.6' } }, [
      h('s', { class: 'er-order-original-amount', style: { display: 'block', fontSize: 'inherit' } }, format(order.total_amount)),
      h('span', {
        class: 'er-order-refund-value', style: { display: 'block', color: '#dc2626', fontSize: 'inherit', whiteSpace: 'nowrap' },
        title: refund && refund.group_order_count > 1 ? '本行显示分配退款金额；关联订单合并入账合计 ' + (refund.group_total / 100).toFixed(2) + ' 元' : '已退至站内余额'
      }, amount === null ? '退款金额待确认' : '+' + (amount / 100).toFixed(2) + ' 元')
    ]);
  }
  root.ElephantRouteOrderRefund = { renderAmount: renderAmount };
})(window);
