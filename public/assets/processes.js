// Server → Processes: live list (refreshes every 5 s while the tab is visible), sorting, filters, ending account processes.
(() => {
  const root = document.getElementById('proc');
  if (!root) return;
  const csrf = document.querySelector('meta[name=csrf]').content;
  const $ = (s) => root.querySelector(s);
  const rows = $('[data-rows]'), usersBody = $('[data-users]'), q = $('[data-q]'), userSel = $('[data-user]'), kernel = $('[data-kernel]');
  const status = $('[data-status]'), auto = document.getElementById('proc-auto');
  let data = [], memTotal = 0, cores = 1, sortKey = 'cpu', sortDir = -1, timer = null, busy = false;

  const fmtBytes = (n) => (n >= 1073741824 ? (n / 1073741824).toFixed(2) + ' GB' : n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.round(n / 1024) + ' KB');
  const fmtTime = (s) => { const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
    return d ? `${d}d ${h}h` : h ? `${h}h ${m}m` : m ? `${m}m ${s % 60}s` : `${s}s`; };
  const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; };
  const who = (p) => p.label ? `${p.user} (${p.label})` : p.user;

  function summary(list) {
    const cpu = list.reduce((a, p) => a + p.cpu, 0), rss = list.reduce((a, p) => a + p.rss, 0);
    $('[data-sum="count"]').textContent = list.length;
    $('[data-sum="cpu"]').textContent = (cpu / cores).toFixed(1) + ' %';
    $('[data-sum="mem"]').textContent = fmtBytes(rss) + (memTotal ? ` / ${fmtBytes(memTotal)}` : '');
    const by = {};
    list.forEach((p) => { const k = p.user; (by[k] ||= { user: k, label: p.label, n: 0, cpu: 0, rss: 0 }); by[k].n++; by[k].cpu += p.cpu; by[k].rss += p.rss; });
    const top = Object.values(by).sort((a, b) => b.cpu - a.cpu || b.rss - a.rss);
    $('[data-sum="top"]').textContent = top[0] ? who(top[0]) : '–';
    usersBody.replaceChildren(...top.slice(0, 8).map((u) => {
      const tr = el('tr');
      tr.append(el('td', '', who(u)), el('td', 'text-right', String(u.n)), el('td', 'text-right', (u.cpu / cores).toFixed(1) + ' %'), el('td', 'text-right', fmtBytes(u.rss)));
      return tr;
    }));
    const cur = userSel.value;
    const opts = [userSel.options[0], ...top.map((u) => { const o = el('option', '', who(u)); o.value = u.user; return o; })];
    userSel.replaceChildren(...opts);
    userSel.value = top.some((u) => u.user === cur) ? cur : '';
  }

  function render() {
    const term = q.value.trim().toLowerCase(), u = userSel.value;
    const list = data.filter((p) => (kernel.checked || !p.kernel) && (!u || p.user === u)
      && (!term || String(p.pid) === term || p.cmd.toLowerCase().includes(term) || who(p).toLowerCase().includes(term)));
    list.sort((a, b) => { const x = a[sortKey], y = b[sortKey]; return (typeof x === 'string' ? x.localeCompare(y) : x - y) * sortDir; });
    rows.replaceChildren(...(list.length ? list.slice(0, 500).map((p) => {
      const tr = el('tr');
      const cmd = el('td', 'max-w-[14rem] truncate font-mono text-xs lg:max-w-[20rem] 2xl:max-w-[32rem]', p.cmd); cmd.title = p.cmd;
      const act = el('td', 'whitespace-nowrap text-right');
      if (p.account) {
        const end = el('button', 'btn-ghost px-2 py-1 text-xs text-red-600', root.dataset.end); end.addEventListener('click', () => signal(p, 'TERM'));
        const kill = el('button', 'btn-ghost px-2 py-1 text-xs text-red-700', root.dataset.force); kill.addEventListener('click', () => signal(p, 'KILL'));
        act.append(end, kill);
      }
      tr.append(el('td', 'font-mono', String(p.pid)), el('td', 'whitespace-nowrap', who(p)), el('td', 'text-right' + (p.cpu >= 50 ? ' font-semibold text-amber-600' : ''), p.cpu.toFixed(1)),
        el('td', 'text-right', p.mem.toFixed(1)), el('td', 'text-right whitespace-nowrap', fmtBytes(p.rss)), el('td', 'text-right whitespace-nowrap', fmtTime(p.time)),
        el('td', 'font-mono text-xs', p.state), cmd, act);
      return tr;
    }) : [(() => { const tr = el('tr'); const td = el('td', 'py-6 text-center text-slate-400', root.dataset.none); td.colSpan = 9; tr.append(td); return tr; })()]));
    root.querySelectorAll('[data-sort]').forEach((b) => { b.dataset.dir = b.dataset.sort === sortKey ? (sortDir > 0 ? '▲' : '▼') : ''; b.textContent = b.textContent.replace(/ [▲▼]$/, '') + (b.dataset.dir ? ' ' + b.dataset.dir : ''); });
  }

  async function load() {
    if (busy) return;
    busy = true;
    try {
      const r = await fetch(root.dataset.url, { headers: { Accept: 'application/json' } });
      const j = await r.json();
      if (!r.ok) throw new Error(j.error || r.status);
      data = j.processes; memTotal = j.mem_total; cores = j.cores;
      summary(data.filter((p) => !p.kernel));
      render();
      status.textContent = root.dataset.updated.replace(':t', new Date(j.at * 1000).toLocaleTimeString());
    } catch (e) {
      status.textContent = '⚠ ' + e.message;
    } finally {
      busy = false;
    }
  }

  async function signal(p, sig) {
    if (!confirm((sig === 'KILL' ? root.dataset.confirmKill : root.dataset.confirmTerm).replace(':pid', p.pid).replace(':cmd', p.cmd.slice(0, 120)))) return;
    const r = await fetch(root.dataset.signal, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf, Accept: 'application/json' },
      body: new URLSearchParams({ pid: p.pid, signal: sig }) });
    const j = await r.json().catch(() => ({}));
    status.textContent = j.ok ? j.message : '⚠ ' + (j.error || r.status);
    setTimeout(load, 600);
  }

  root.querySelectorAll('[data-sort]').forEach((b) => b.addEventListener('click', () => {
    const k = b.dataset.sort;
    sortDir = sortKey === k ? -sortDir : (['user', 'cmd', 'pid'].includes(k) ? 1 : -1);
    sortKey = k;
    render();
  }));
  [q, userSel, kernel].forEach((x) => x.addEventListener('input', render));
  const tick = () => { clearInterval(timer); if (auto.checked) timer = setInterval(() => { if (!document.hidden) load(); }, 5000); };
  auto.addEventListener('change', tick);
  load();
  tick();
})();
