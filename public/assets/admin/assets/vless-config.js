export function patchVlessDraft(draft, path, value) {
  const keys = path.split('.');
  if (keys.some(key => ['__proto__', 'prototype', 'constructor'].includes(key))) throw new Error('Invalid field');
  const next = { ...draft };
  let target = next;
  let original = draft;
  for (const key of keys.slice(0, -1)) {
    target[key] = { ...(original?.[key] || {}) };
    target = target[key];
    original = original?.[key];
  }
  target[keys.at(-1)] = value;
  return next;
}

export function parseXhttpExtra(value) {
  const parsed = value.trim() ? JSON.parse(value) : {};
  if (!parsed || Array.isArray(parsed) || typeof parsed !== 'object') throw new Error('Expected a JSON object');
  return parsed;
}

export function VlessConfigFields({ React: R, form, t, generateKeys, generateShortId }) {
  const h = R.createElement;
  const values = form.watch();
  const [showSecrets, setShowSecrets] = R.useState(false);
  const [importValue, setImportValue] = R.useState('');
  const [message, setMessage] = R.useState('');
  const [extraDraft, setExtraDraft] = R.useState(null);
  const prefix = R.useId();
  const text = key => t(`dynamic_form.vless.upgrade.${key}`);
  const get = path => path.split('.').reduce((value, key) => value?.[key], values);
  const set = (path, value) => {
    const key = path.split('.')[0];
    const updated = patchVlessDraft(form.getValues(), path, value);
    form.setValue(key, updated[key], { shouldDirty: true, shouldValidate: true });
  };
  const fieldClass = 'flex min-h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';
  const readError = path => path.split('.').reduce((value, key) => value?.[key], form.formState.errors);
  const field = (path, label, options = {}) => {
    const id = `${prefix}-${path}`;
    const value = get(path) ?? options.defaultValue ?? '';
    const choices = options.options && !options.options.some(([key]) => key === value) && value !== '' ? [...options.options, [value, String(value)]] : options.options;
    const input = choices
      ? h('select', { id, className: fieldClass, value, onChange: event => {
        const next = options.number ? Number(event.target.value) : event.target.value;
        set(path, next);
        if (path === 'tls' && next === 2 && !get('reality_settings.private_key') && !get('utls')) {
          set('utls', { enabled: true, fingerprint: 'chrome' });
        }
      } }, choices.map(([key, title]) => h('option', { key, value: key }, title)))
      : h(options.multiline ? 'textarea' : 'input', {
        id, className: fieldClass, rows: options.multiline ? 3 : undefined,
        type: options.secret && !showSecrets ? 'password' : options.number ? 'number' : 'text',
        autoComplete: options.secret ? 'off' : undefined,
        spellCheck: options.secret ? false : undefined,
        value, onChange: event => set(path, options.number ? Number(event.target.value) : event.target.value),
      });
    return h('div', { className: 'space-y-2', key: path },
      h('label', { htmlFor: id, className: 'text-sm font-medium' }, label), input,
      readError(path)?.message ? h('p', { className: 'text-sm text-destructive', role: 'alert' }, readError(path).message) : null);
  };
  const toggle = (path, label) => h('label', { className: 'flex min-h-10 cursor-pointer items-center gap-3 text-sm font-medium', key: path },
    h('input', { type: 'checkbox', checked: Boolean(get(path)), onChange: event => set(path, event.target.checked), className: 'h-4 w-4 accent-primary' }), label);
  const button = (label, onClick) => h('button', { type: 'button', onClick, className: 'inline-flex min-h-9 items-center justify-center rounded-md border border-input bg-background px-3 py-2 text-sm font-medium hover:bg-accent' }, label);
  const section = (title, children) => h('fieldset', { className: 'space-y-4 rounded-lg border border-border p-4', key: title }, h('legend', { className: 'px-1 text-sm font-semibold' }, title), ...children.filter(Boolean));
  const network = get('network') || 'tcp';
  const tls = Number(get('tls') || 0);
  const encrypted = Boolean(get('encryption.enabled'));
  const extra = get('network_settings.extra');
  const extraValue = extraDraft ?? (typeof extra === 'string' ? extra : extra ? JSON.stringify(extra, null, 2) : '');
  let extraError = false;
  try { parseXhttpExtra(extraValue); } catch { extraError = true; }
  const children = [
    field('tls', text('security'), { number: true, options: [[0, text('none')], [1, 'TLS'], [2, 'REALITY']] }),
    field('network', text('transport'), { options: [['tcp', 'TCP / RAW'], ['ws', 'WebSocket'], ['grpc', 'gRPC'], ['httpupgrade', 'HTTPUpgrade'], ['xhttp', 'XHTTP']] }),
    field('flow', text('flow'), { options: [['', text('none')], ['xtls-rprx-vision', 'xtls-rprx-vision']] }),
  ];
  if (get('flow') === 'xtls-rprx-vision' && (network !== 'tcp' || tls === 0)) children.push(h('p', { className: 'text-sm text-destructive', role: 'alert', key: 'flow-error' }, text('visionError')));
  if (tls === 1) children.push(section('TLS', [field('tls_settings.server_name', 'SNI'), toggle('tls_settings.allow_insecure', text('insecure'))]));
  if (tls === 2) children.push(section('REALITY', [
    field('reality_settings.server_name', 'SNI'),
    field('reality_settings.dest', text('target')),
    field('reality_settings.server_port', text('targetPort'), { number: true, defaultValue: 443 }),
    field('reality_settings.private_key', text('privateKey'), { secret: true }),
    field('reality_settings.public_key', text('publicKey')),
    field('reality_settings.short_id', 'Short ID'),
    h('div', { className: 'flex flex-wrap gap-2', key: 'keys' },
      button(text('generateKeys'), () => {
        if (get('reality_settings.private_key') && !window.confirm(text('replaceKeys'))) return;
        try {
          const pair = generateKeys();
          set('reality_settings.private_key', pair.privateKey);
          set('reality_settings.public_key', pair.publicKey);
        } catch { setMessage(text('importError')); }
      }), button(text('generateId'), () => set('reality_settings.short_id', generateShortId()))),
  ]));
  if (network === 'ws' || network === 'httpupgrade') children.push(section(text('transport'), [field('network_settings.path', 'Path'), field(network === 'ws' ? 'network_settings.headers.Host' : 'network_settings.host', 'Host')]));
  if (network === 'grpc') children.push(field('network_settings.serviceName', 'Service name'));
  if (network === 'xhttp') children.push(section('XHTTP', [
    field('network_settings.path', 'Path', { defaultValue: '/' }), field('network_settings.host', 'Host'),
    field('network_settings.mode', text('mode'), { defaultValue: 'auto', options: ['auto', 'packet-up', 'stream-up', 'stream-one'].map(value => [value, value]) }),
    h('div', { className: 'space-y-2', key: 'extra' }, h('label', { htmlFor: `${prefix}-extra`, className: 'text-sm font-medium' }, text('extra')),
      h('textarea', { id: `${prefix}-extra`, className: fieldClass, value: extraValue, rows: 4, onChange: event => {
        setExtraDraft(event.target.value);
        try { set('network_settings.extra', parseXhttpExtra(event.target.value)); } catch { set('network_settings.extra', event.target.value); }
      } }), extraError ? h('p', { role: 'alert', className: 'text-sm text-destructive' }, text('jsonError')) : null),
  ]));
  children.push(section('uTLS', [toggle('utls.enabled', text('utls')), get('utls.enabled') ? field('utls.fingerprint', text('fingerprint'), { defaultValue: 'chrome', options: ['chrome', 'firefox', 'safari', 'ios', 'edge', 'android', 'qq', 'random'].map(value => [value, value]) }) : null]));
  children.push(section('VLESS Encryption', [
    toggle('encryption.enabled', text('encryption')),
    encrypted ? h('div', { className: 'space-y-4', key: 'encryption-values' },
      field('encryption.encryption', text('clientParameter'), { multiline: true }),
      field('encryption.decryption', text('serverParameter'), { secret: true }),
      h('label', { className: 'block text-sm font-medium', htmlFor: `${prefix}-import` }, text('importPair')),
      h('textarea', { id: `${prefix}-import`, className: fieldClass, rows: 3, value: importValue, onChange: event => setImportValue(event.target.value), spellCheck: false }),
      button(text('import'), () => {
        try {
          const pair = JSON.parse(importValue);
          if (typeof pair.encryption !== 'string' || typeof pair.decryption !== 'string' || !pair.encryption.startsWith('mlkem768x25519plus.') || !pair.decryption.startsWith('mlkem768x25519plus.')) throw new Error('Invalid pair');
          set('encryption.encryption', pair.encryption); set('encryption.decryption', pair.decryption);
          setImportValue(''); setMessage('');
        } catch { setMessage(text('importError')); }
      })) : null,
  ]));
  if (encrypted || network === 'xhttp' || tls === 2) children.push(section(text('backend'), [
    h('p', { className: 'text-sm text-muted-foreground', key: 'backend-hint' }, text('backendHint')),
    field('backend.core', text('core'), { options: [['', text('selectCore')], ['xray', 'Xray']] }),
    field('backend.release', text('release')),
  ]));
  children.push(h('label', { className: 'flex items-center gap-3 text-sm', key: 'show-secrets' }, h('input', { type: 'checkbox', checked: showSecrets, onChange: event => setShowSecrets(event.target.checked) }), text('showSecrets')));
  if (message) children.push(h('p', { role: 'alert', className: 'text-sm text-destructive', key: 'message' }, message));
  return h('div', { className: 'space-y-5', 'data-vless-config': true }, ...children);
}
