// Keep refund UI readable while sharing the existing admin React/Radix runtime.
export const money = value => '¥' + (Number(value || 0) / 100).toFixed(2);
export function refundForOrder(order) {
  if (order.balance_refund_entry?.amount != null) return Number(order.balance_refund_entry.amount);
  const refund = order.balance_refund;
  if (!refund) return null;
  // Older audit records may predate per-order allocation columns.
  return Number(refund.order_id ?? order.id) === Number(order.id) ? Number(refund.amount) : 0;
}
export function unavailableReason(order) {
  if (Number(order.status) === 5 || order.balance_refund) return '该订单已退款到余额';
  if (Number(order.type) === 6 || order.period === 'recharge') return '充值订单不能重复计入余额';
  if (Number(order.status) !== 3) return '仅已完成的订单可以退款';
  if (Number(order.type) === 4 || ['reset_price', 'reset_traffic'].includes(order.period)) return '流量重置订单没有独立可回收权益';
  if (order.no_proration) return '该套餐不参与折抵，不能自动退款';
  if (Number(order.commission_status) === 1) return '佣金正在发放，请先处理佣金';
  if (Number(order.commission_status) === 2 || Number(order.actual_commission_balance) > 0) return '佣金已结算，请先人工核对';
  if (Number(order.total_amount || 0) < 0 || Number(order.balance_amount || 0) < 0) return '该订单金额异常';
  return '';
}

export function createRefundUI({ React: R, api, toast, ui }) {
  const h = R.createElement;
  const { Button, Menu, MenuTrigger, MenuContent, MenuItem, Dots, Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } = ui;
  const errorText = error => error?.response?.data?.message || error?.message || '请求失败，请重试';
  async function call(path, payload) {
    const result = await api.post(window.settings.secure_path + '/order/' + path, payload);
    if (!result || result.status === 'fail') throw new Error(result?.message || '退款失败');
    if (!result.data) throw new Error('退款接口未返回有效结果，请重新核对');
    return result.data;
  }
  const line = (label, value) => h('div', { className: 'flex items-start justify-between gap-4 text-sm' },
    h('span', { className: 'text-muted-foreground shrink-0' }, label),
    h('span', { className: 'text-right break-all font-medium' }, value));

  function AmountCell({ order }) {
    const props = { className: 'flex items-center whitespace-nowrap font-mono text-foreground/90' };
    if (Number(order.status) !== 5) return h('div', props, money(order.total_amount));
    const amount = refundForOrder(order);
    const grouped = (order.balance_refund?.snapshot?.order_ids?.length || 0) > 1;
    return h('div', props,
      h('s', { style: { fontSize: 'inherit' } }, money(order.total_amount)),
      '/',
      h('span', {
        style: { color: '#dc2626', fontSize: 'inherit' },
        title: grouped ? '本行显示对应订单的分配退款金额，合并退款只入账一次' : '已退至用户余额',
      }, Number.isFinite(amount) && amount !== null ? money(amount) : '—'));
  }

  function Audit({ refund }) {
    if (!refund) return null;
    return h('section', { className: 'rounded-lg border p-4 space-y-2', 'aria-label': '退款记录' },
      h('h3', { className: 'text-sm font-medium' }, '退款到余额 · ' + money(refund.amount)),
      line('退款时间', new Date(refund.created_at * 1000).toLocaleString('zh-CN', { hour12: false })),
      line('操作管理员', '#' + refund.operator_id),
      line('账户余额', money(refund.balance_before) + ' → ' + money(refund.balance_after)),
      refund.snapshot ? h('div', { className: 'space-y-2' },
        line('退款范围', refund.snapshot.label), line('折算基数', money(refund.snapshot.base_amount)),
        line('关联订单', (refund.snapshot.related_orders || []).map(order => order.trade_no).join('、')),
        h('p', { className: 'text-sm' }, refund.snapshot.kind === 'subscription' ? '已移除当前套餐、套餐流量和有效期。' : '已回收该流量包的剩余流量。')) : null,
      h('p', { className: 'text-sm whitespace-pre-wrap break-words' }, refund.reason));
  }

  function Actions({ order, refresh }) {
    const [open, setOpen] = R.useState(false);
    const [completed, setCompleted] = R.useState(false);
    const [preview, setPreview] = R.useState(null);
    const [reason, setReason] = R.useState('');
    const [error, setError] = R.useState('');
    const [loading, setLoading] = R.useState(false);
    const [busy, setBusy] = R.useState(false);
    const [retry, setRetry] = R.useState(0);
    const submitting = R.useRef(false);
    const cancelButton = R.useRef(null);
    const reasonId = R.useId();
    const blocked = completed ? '该订单已退款到余额' : unavailableReason(order);
    R.useEffect(() => {
      if (!open) return;
      let active = true;
      setPreview(null); setLoading(true); setError('');
      call('refund-preview', { trade_no: order.trade_no }).then(data => { if (active) setPreview(data); })
        .catch(e => { if (active) setError(errorText(e)); })
        .finally(() => { if (active) setLoading(false); });
      return () => { active = false; };
    }, [open, retry, order.trade_no]);
    function changeOpen(value) {
      if (submitting.current) return;
      if (value) { setPreview(null); setReason(''); setError(''); }
      setOpen(value);
    }
    async function submit(event) {
      event.preventDefault();
      if (submitting.current || !preview || reason.trim().length < 2) return;
      submitting.current = true; setBusy(true); setError('');
      let result;
      try {
        result = await call('refund', { trade_no: order.trade_no, expected_amount: preview.amount, reason: reason.trim(), confirmed: true, quote: preview.quote });
      } catch (e) {
        setError(errorText(e));
      } finally {
        submitting.current = false; setBusy(false);
      }
      if (result) {
        // Invalidate this row immediately even if a background list refresh fails.
        setPreview(null); setCompleted(true); setOpen(false);
        toast.success('已退款 ' + money(result.amount) + ' 到用户余额');
        try { await refresh(); } catch { toast.error('退款已完成，列表刷新失败，请手动刷新'); }
      }
    }
    return h(R.Fragment, null,
      h(Menu, { modal: false },
        h(MenuTrigger, { asChild: true }, h(Button, { variant: 'ghost', size: 'sm', className: 'h-8 w-8 p-0', 'aria-label': '订单操作 ' + order.trade_no }, h(Dots, { className: 'h-4 w-4', 'aria-hidden': true }))),
        h(MenuContent, { align: 'end', className: 'w-64' },
          h(MenuItem, { disabled: !!blocked, onSelect: () => changeOpen(true), className: 'cursor-pointer' }, '退款到余额'),
          blocked ? h('p', { className: 'px-2 py-1.5 text-xs text-muted-foreground' }, blocked) : null)),
      h(Dialog, { open, onOpenChange: changeOpen },
        h(DialogContent, { className: 'sm:max-w-[480px] max-h-[90vh] overflow-y-auto',
          onOpenAutoFocus: event => { event.preventDefault(); cancelButton.current?.focus(); },
          onEscapeKeyDown: event => { if (submitting.current) event.preventDefault(); },
          onPointerDownOutside: event => event.preventDefault(),
          onInteractOutside: event => event.preventDefault() },
          h(DialogHeader, null,
            h(DialogTitle, null, '确认退款到余额？'),
            h(DialogDescription, null, '确认后立即计入用户站内余额，无法撤销，不会原路退回支付账户。')),
          h('form', { onSubmit: submit, className: 'space-y-4' },
            loading ? h('p', { role: 'status', className: 'text-sm text-muted-foreground' }, '正在核对订单与账户余额…') : null,
            preview ? h('div', { className: 'rounded-lg border p-4 space-y-2' },
              line('订单号', preview.trade_no), line('退款用户', preview.email + ' · #' + preview.user_id),
              line('退款范围', preview.label),
              line('当前订单本金', money(Number(preview.total_amount) + Number(preview.balance_amount))),
              line('折算基数', money(preview.base_amount)),
              line('本订单手续费（不退）', money(preview.handling_amount)),
              h('p', { className: 'text-sm text-muted-foreground' }, preview.rule),
              preview.related_orders?.length > 1 ? h('details', { className: 'text-sm' },
                h('summary', { className: 'cursor-pointer' }, '涉及 ' + preview.related_orders.length + ' 笔订单（一起关闭）'),
                preview.related_orders.map(item => h('p', { key: item.id, className: 'break-all font-mono' }, item.trade_no))) : null,
              h('div', { className: 'border-t pt-2' }, line('本次折算退款', money(preview.amount))),
              line('预计退款后余额', money(preview.balance_before) + ' → ' + money(preview.balance_after))) : null,
            h('div', { className: 'rounded-md bg-muted p-3 text-sm leading-relaxed' },
              preview?.kind === 'subscription'
                ? '退款将立即取消当前套餐，清除套餐流量和有效期；上方关联订单一起关闭，未结算佣金作废。另购的独立流量包不受影响。'
                : preview?.kind === 'traffic_package'
                  ? '退款将立即回收该订单对应的全部剩余流量，订单关闭且未结算佣金作废。其他套餐和独立流量包不受影响。'
                  : '退款将同时回收对应权益，请核对金额与回收范围后再确认。'),
            preview ? h('div', { className: 'space-y-2' },
              h('label', { htmlFor: reasonId, className: 'text-sm font-medium' }, '退款原因 ', h('span', { className: 'text-muted-foreground' }, '（必填）')),
              h('textarea', { id: reasonId, value: reason, onChange: event => setReason(event.target.value), disabled: busy, required: true, minLength: 2, maxLength: 500, rows: 2,
                placeholder: '例如：用户申请按剩余价值退款并取消对应权益', className: 'flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm' })) : null,
            preview ? h('div', { className: 'flex items-center justify-between gap-2 text-sm' },
              h('span', { className: 'text-muted-foreground' }, '金额预览有效 5 分钟'),
              h(Button, { type: 'button', variant: 'ghost', size: 'sm', disabled: busy || loading, onClick: () => { setPreview(null); setRetry(value => value + 1); } }, '重新核对金额')) : null,
            error ? h('p', { role: 'alert', className: 'text-sm text-destructive' }, error) : null,
            h(DialogFooter, null,
              h(Button, { type: 'button', variant: 'outline', ref: cancelButton, disabled: busy, onClick: () => changeOpen(false) }, '取消'),
              !preview && !loading ? h(Button, { type: 'button', onClick: () => setRetry(value => value + 1) }, '重新核对') :
                h(Button, { type: 'submit', variant: 'destructive', disabled: busy || loading || !preview || reason.trim().length < 2 },
                  busy ? '正在退款…' : preview ? '确认退款 ' + money(preview.amount) : '确认退款'))))));
  }
  return { Actions, Audit, AmountCell };
}
