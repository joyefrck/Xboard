// Disposable provider fixture for the real admin shell. Never contacts KiwiVM.
const { spawnSync } = require('node:child_process');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const stamp = () => new Date().toISOString();
const GiB = 1024 ** 3;
function instance(id, name, ip, location, percent) {
  const total = 1000 * GiB, used = percent * total / 100;
  return { id, veid: String(2000000 + id), name, hostname: 'vps-' + id + '.example.test', vm_type: 'kvm', imported_ip: ip,
    info: { ip_addresses: [ip], node_location: location, node_alias: 'Node' + id, plan: 'KVM 2G GIA', os: 'debian-12',
      plan_ram: 2 * GiB, plan_swap: GiB, plan_disk: 40 * GiB, data_counter: used, plan_monthly_data: total,
      monthly_data_multiplier: 1, suspended: false, data_next_reset: Math.floor(Date.now() / 1000) + 8 * 86400 },
    live: { ve_status: 'Running', load_average: '0.12 0.08 0.05', mem_available_kb: 1024 * 1024, swap_total_kb: 1024 * 1024,
      swap_available_kb: 1024 * 1024, ve_used_disk_space_b: 6.4 * GiB, is_cpu_throttled: false, is_disk_throttled: false, ssh_port: '22' },
    usage: { used_bytes: used, total_bytes: total, remaining_bytes: total - used, percent, reset_at: Math.floor(Date.now() / 1000) + 8 * 86400 },
    synced_at: stamp(), live_synced_at: stamp(), attempted_at: stamp(), queued_at: null, last_error: null, live_error: null,
    stale: false, reset_requested_at: null, reset_state: 'none' };
}
let inventory = [instance(1, '美国 · 高峰专线', '198.51.100.10', 'US, Los Angeles', 68),
  instance(2, '新加坡 · 住宅线路', '198.51.100.60', 'SG, Singapore', 86),
  instance(3, '德国 · 备用服务器', '198.51.100.90', 'DE, Frankfurt', 97)];
inventory[1].live.ve_status = 'Stopped';
inventory[2].live_error = '搬瓦工接口限流，请稍后刷新';
function providerTraffic(ip) {
  const matches = inventory.filter(row => row.info?.ip_addresses.includes(ip));
  if (!matches.length) return { status: 'unmatched' };
  if (matches.length > 1) return { status: 'conflict' };
  const row = matches[0];
  return { status: 'matched', usage: row.usage, synced_at: row.synced_at, stale: row.stale, error: row.last_error, veid: row.veid };
}
function handleBandwagon(action, data, json, fail) {
  const row = inventory.find(row => row.id === Number(data.id));
  if (action === '/fetch') {
    let rows = inventory.filter(row => !data.search || JSON.stringify(row).toLowerCase().includes(data.search.toLowerCase()));
    if (data.status === 'running') rows = rows.filter(row => row.live?.ve_status === 'Running');
    if (data.status === 'stopped') rows = rows.filter(row => row.live?.ve_status === 'Stopped');
    if (data.status === 'suspended') rows = rows.filter(row => row.info?.suspended);
    if (data.status === 'error') rows = rows.filter(row => row.last_error || row.live_error);
    if (data.status === 'pending') rows = rows.filter(row => !row.synced_at);
    const page = Number(data.page) || 1;
    return json({ data: rows.slice((page-1)*30,page*30), current_page: page, last_page: Math.max(1,Math.ceil(rows.length/30)), total: rows.length });
  }
  if (action === '/preview' || action === '/import') {
    const code = `require getcwd().'/vendor/autoload.php'; $p=new App\\Services\\Bandwagon\\CsvImporter; try { echo json_encode($p->preview($p->parse(stream_get_contents(STDIN)))); } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }`;
    const run = spawnSync('php', ['-r', code], { cwd: root, input: data.csv || '', encoding: 'utf8' });
    if (run.status !== 0) return fail('预览解析失败');
    const rows = JSON.parse(run.stdout);
    if (rows.error) return fail(rows.error);
    if (action === '/preview') return json(rows.map(item => ({ ...item, existing: inventory.some(row => row.veid === item.veid) })));
    const result = { created:0,updated:0,skipped:0,busy:0 };
    for (const item of rows) {
      if (item.status !== 'valid') { result.skipped++; continue; }
      let old = inventory.find(row => row.veid === item.veid);
      if (old) result.updated++;
      else { old = instance(Math.max(0,...inventory.map(row=>row.id))+1,'',item.ip || '198.51.100.150','US, Los Angeles',21); inventory.push(old); result.created++; }
      old.veid = item.veid; old.hostname = item.hostname; old.queued_at = stamp();
      setTimeout(()=>{ old.queued_at=null;old.synced_at=stamp(); },800);
    }
    return json(result);
  }
  if (action === '/refresh') {
    const rows = data.id ? [row].filter(Boolean) : inventory;
    rows.forEach(row=>{row.queued_at=stamp();setTimeout(()=>{row.queued_at=null;row.synced_at=stamp();row.live_synced_at=stamp();},800);});
    return json({ queued: rows.length, skipped: 0 });
  }
  if (!row) return fail('服务器不存在');
  if (action === '/rename') { row.name = data.name; return json(true); }
  if (action === '/drop') { inventory = inventory.filter(item => item.id !== row.id); return json(true); }
  if (action === '/reset-password') {
    if (data.confirm_veid !== row.veid) return fail('VEID 不匹配');
    row.reset_state = 'completed'; row.reset_requested_at = stamp();
    return json({ veid:row.veid,password:'fixture-only-not-a-real-password' });
  }
  return fail('未知预览操作');
}
module.exports = { handleBandwagon, providerTraffic };
