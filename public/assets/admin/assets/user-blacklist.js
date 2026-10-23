// Reuse the existing admin React runtime and authenticated API client.
export const statusLabels = { muted: '工单禁言中', expired: '工单已解禁', banned: '账号已封禁' };
export const actionLabels = { ticket_mute: '工单禁言 7 天', account_ban: '永久封禁账号', account_unban: '管理员解除封禁', ticket_unmute: '管理员解除工单禁言' };
export const formatTime = value => value ? new Date(value * 1000).toLocaleString('zh-CN', { timeZone: 'Asia/Shanghai', hour12: false }) : '—';

export function createBlacklistPage({ React: R, api, layout }) {
  const h = R.createElement;
  const prefix = window.settings.secure_path + '/blacklist';
  const errorText = error => error.response?.data?.message || error.message || '请求失败，请重试';
  async function call(path, data = {}) {
    const result = await api.get(prefix + path, { params: data });
    if (result.status === 'fail') throw new Error(result.message || '请求失败');
    return result.data;
  }
  const button = (text, onClick, disabled = false) => h('button', {
    type: 'button', className: 'bl-button', onClick, disabled,
  }, text);
  function Pager({ result, page, setPage, busy }) {
    return h('div', { className: 'bl-pager' }, h('span', null, '共 ' + result.total + ' 条 · ' + result.current_page + ' / ' + result.last_page),
      button('上一页', () => setPage(page - 1), busy || page <= 1),
      button('下一页', () => setPage(page + 1), busy || page >= result.last_page));
  }
  function Dialog({ title, close, children, busy = false }) {
    const ref = R.useRef(null);
    const titleId = R.useId();
    R.useEffect(() => {
      const previous = document.activeElement;
      const dialog = ref.current;
      dialog.showModal();
      return () => { dialog.close(); previous?.focus(); };
    }, []);
    return h('dialog', { ref, className: 'bl-dialog', 'aria-labelledby': titleId,
      onCancel: event => { event.preventDefault(); if (!busy) close(); } },
    h('div', { className: 'bl-heading' }, h('h3', { id: titleId }, title), button('关闭', close, busy)), children);
  }
  function History({ user, close }) {
    const [page, setPage] = R.useState(1);
    const [result, setResult] = R.useState(null);
    const [busy, setBusy] = R.useState(true);
    const [error, setError] = R.useState('');
    R.useEffect(() => {
      let cancelled = false;
      setBusy(true); setError(''); setResult(null);
      call('/detail', { user_id: user.id, page }).then(value => { if (!cancelled) setResult(value); })
        .catch(e => { if (!cancelled) setError(errorText(e)); })
        .finally(() => { if (!cancelled) setBusy(false); });
      return () => { cancelled = true; };
    }, [user.id, page]);
    return h(Dialog, { title: user.email + ' · 处罚记录', close },
      busy ? h('p', { role: 'status' }, '正在读取记录…') : null,
      error ? h('p', { role: 'alert', className: 'bl-error' }, error) : null,
      result?.data.length === 0 ? h('p', { className: 'bl-muted' }, '该账号为历史封禁，暂无详细处罚记录。') : null,
      result?.data.map(event => h('article', { className: 'bl-event', key: event.id },
        h('div', { className: 'bl-heading' }, h('strong', null, actionLabels[event.action] || event.action),
          h('span', { className: 'bl-muted' }, formatTime(event.created_at))),
        h('p', null, '来源：' + (event.source === 'ai' ? 'AI 自动审核 · ' + event.model : '管理员 #' + event.operator_id)),
        h('p', null, '原因：' + event.reason),
        event.muted_until ? h('p', null, '解禁时间：' + formatTime(event.muted_until) + '（北京时间）') : null,
        event.subject ? h('div', { className: 'bl-evidence' }, h('strong', null, '工单标题'), h('p', null, event.subject)) : null,
        event.message ? h('div', { className: 'bl-evidence' }, h('strong', null, '本次提交原文'), h('p', null, event.message)) : null,
        event.evidence ? h('div', { className: 'bl-evidence' }, h('strong', null, '违规证据'), h('p', null, event.evidence)) : null,
        event.context?.messages?.length ? h('details', null, h('summary', null, '查看判定上下文'),
          event.context.messages.map((message, index) => h('div', { className: 'bl-evidence', key: index },
            h('strong', null, message.role === 'staff' ? '客服' : '用户'), h('p', null, message.message)))) : null)),
      result ? h(Pager, { result, page, setPage, busy }) : null);
  }
  function Page() {
    const [draft, setDraft] = R.useState('');
    const [search, setSearch] = R.useState('');
    const [status, setStatus] = R.useState('all');
    const [page, setPage] = R.useState(1);
    const [refresh, setRefresh] = R.useState(0);
    const [result, setResult] = R.useState(null);
    const [busy, setBusy] = R.useState(true);
    const [error, setError] = R.useState('');
    const [history, setHistory] = R.useState(null);
    R.useEffect(() => {
      let cancelled = false;
      setBusy(true); setError('');
      call('/fetch', { search, status, page }).then(value => { if (!cancelled) setResult(value); })
        .catch(e => { if (!cancelled) setError(errorText(e)); })
        .finally(() => { if (!cancelled) setBusy(false); });
      return () => { cancelled = true; };
    }, [search, status, page, refresh]);
    R.useEffect(() => {
      const interval = setInterval(() => setRefresh(value => value + 1), 30000);
      return () => clearInterval(interval);
    }, []);
    const review = result?.review;
    const content = h('section', { className: 'user-blacklist', 'aria-busy': busy },
      h('header', { className: 'bl-heading' }, h('div', null, h('h2', null, '黑名单'),
        h('p', { className: 'bl-muted' }, '首次言语攻击禁言 7 天，解禁后再次违规永久封禁账号。'))),
      review ? h('p', { className: 'bl-policy' },
        review.enabled && review.configured ? 'DeepSeek 自动审核已启用 · ' + review.model : 'DeepSeek 自动审核' + (review.enabled ? '缺少服务端密钥' : '尚未启用'),
        ' · 仅审核文字 · 不提供用户申诉') : null,
      h('form', { className: 'bl-toolbar', onSubmit: event => { event.preventDefault(); setPage(1); setSearch(draft.trim()); setRefresh(v => v + 1); } },
        h('input', { 'aria-label': '搜索用户邮箱或 ID', placeholder: '搜索用户邮箱或 ID', value: draft, maxLength: 255,
          onChange: e => setDraft(e.target.value) }),
        h('select', { 'aria-label': '处罚状态', value: status, onChange: e => { setStatus(e.target.value); setPage(1); } },
          [['all', '全部状态'], ['muted', '工单禁言中'], ['expired', '工单已解禁'], ['banned', '账号已封禁']].map(([value, label]) => h('option', { value, key: value }, label))),
        h('button', { type: 'submit', className: 'bl-button', disabled: busy }, '搜索'),
        button('刷新', () => setRefresh(v => v + 1), busy)),
      error ? h('p', { role: 'alert', className: 'bl-error' }, error) : null,
      busy ? h('p', { role: 'status', className: 'bl-muted' }, '正在读取黑名单…') : null,
      !busy && !error && result?.data.length === 0 ? h('p', { className: 'bl-empty' }, search || status !== 'all' ? '没有符合条件的记录。' : '暂无黑名单记录。') : null,
      result?.data.length ? h('div', { className: 'bl-table-wrap' }, h('table', null,
        h('thead', null, h('tr', null, ['用户', '当前状态', '违规次数', '最近处理原因', '解禁时间（北京时间）', '操作'].map(text => h('th', { key: text }, text)))),
        h('tbody', null, result.data.map(user => h('tr', { key: user.id },
          h('td', null, h('strong', null, user.email), h('div', { className: 'bl-muted' }, '#' + user.id)),
          h('td', null, h('span', { className: 'bl-badge bl-' + user.status }, statusLabels[user.status])),
          h('td', null, user.ticket_abuse_count || 0),
          h('td', { className: 'bl-reason' }, user.latest?.reason || '历史封禁',
            user.latest ? h('div', { className: 'bl-muted' }, (user.latest.source === 'ai' ? 'AI 自动处理' : '管理员手动处理') + ' · ' + formatTime(user.latest.created_at)) : null),
          h('td', null, user.banned ? '永久封禁' : formatTime(user.ticket_muted_until)),
          h('td', null, h('div', { className: 'bl-actions' }, button('查看记录', () => setHistory(user))))))))) : null,
      result ? h(Pager, { result, page, setPage, busy }) : null,
      history ? h(History, { user: history, close: () => setHistory(null) }) : null);
    return h(layout.Root, null, h(layout.Header, null,
      h(layout.Search), h('div', { className: 'ml-auto flex items-center space-x-4' }, h(layout.Language), h(layout.User))),
    h(layout.Main, null, content));
  }
  return Page;
}
