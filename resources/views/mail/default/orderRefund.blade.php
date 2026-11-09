<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>退款已到账户余额</title></head>
<body style="margin:0;padding:0;background:#f3f6fb;color:#182230;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Microsoft YaHei',Arial,sans-serif;font-size:16px;line-height:1.6;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;background:#f3f6fb;"><tr><td align="center" style="padding:28px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;">
<tr><td style="padding:24px 28px;background:#112d45;color:#ffffff;font-size:18px;font-weight:700;">{{ $name }}</td></tr>
<tr><td style="padding:28px 28px 0;">
<p style="margin:0 0 8px;color:#526477;font-size:14px;">退款通知</p>
<h1 style="margin:0 0 16px;font-size:24px;line-height:1.4;color:#182230;">退款已到账户余额</h1>
<p style="margin:0 0 20px;">您好，您的订单退款已处理成功。退款金额已退至您的<strong>站内账户余额</strong>，可用于后续购买，不会原路退回支付账户。</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;">
<tr><td style="padding:18px 20px 2px;color:#526477;font-size:14px;">本次到账余额</td></tr>
<tr><td style="padding:0 20px 18px;color:#dc2626;font-size:30px;font-weight:700;">+{{ $refund_amount }} <span style="font-size:16px;">元</span></td></tr>
</table>
<p style="margin:20px 0 8px;font-size:14px;color:#526477;">退款日期：{{ $refund_date }}（北京时间）</p>
@if(count($orders) > 1)
<p style="margin:0 0 16px;font-size:14px;color:#526477;">本次为 {{ count($orders) }} 笔关联订单合并退款，以上金额只入账一次。下方逐单退款金额合计等于本次到账金额。</p>
@endif
@foreach($orders as $order)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;margin:16px 0;border:1px solid #e2e8f0;border-radius:10px;">
<tr><td colspan="2" style="padding:16px 16px 8px;font-size:14px;font-weight:700;color:#526477;">订单编号</td></tr>
<tr><td colspan="2" style="padding:0 16px 12px;word-break:break-all;font-weight:600;">{{ $order['trade_no'] }}</td></tr>
<tr><td style="padding:8px 16px;color:#526477;font-size:14px;">订单日期</td><td align="right" style="padding:8px 16px;font-size:14px;">{{ $order['order_date'] }}<br><span style="color:#64748b;">北京时间</span></td></tr>
<tr><td style="padding:8px 16px;color:#526477;font-size:14px;">订单金额</td><td align="right" style="padding:8px 16px;"><s>{{ $order['order_amount'] }} 元</s></td></tr>
@if($order['balance_payment'] !== '0.00')
<tr><td style="padding:8px 16px;color:#526477;font-size:14px;">另使用余额支付</td><td align="right" style="padding:8px 16px;">{{ $order['balance_payment'] }} 元</td></tr>
@endif
<tr><td style="padding:8px 16px 16px;color:#526477;font-size:14px;">退款金额</td><td align="right" style="padding:8px 16px 16px;color:#dc2626;font-weight:600;">+{{ $order['refund_amount'] }} 元</td></tr>
</table>
@endforeach
<p style="margin:16px 0 24px;color:#526477;font-size:14px;">退款已按对应权益的剩余价值折算，原订单金额与实际退款金额可能不同。对应套餐或流量包权益已取消，退款到账金额以本通知为准。</p>
@if($url)
<p style="margin:0 0 28px;"><a href="{{ $url }}" style="display:inline-block;background:#0b708b;border-radius:8px;padding:12px 22px;color:#ffffff;text-decoration:none;font-size:16px;font-weight:600;">查看我的订单</a></p>
@endif
</td></tr>
<tr><td style="padding:20px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;color:#64748b;font-size:12px;">此邮件由 {{ $name }} 自动发送，请保留以便核对。</td></tr>
</table>
</td></tr></table>
</body></html>
