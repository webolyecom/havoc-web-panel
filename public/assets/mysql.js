// Server → MySQL queries: live list of MariaDB connections/statements (every 3 s while visible), stopping them one by one,
// selected, or by rule. Rows are built with DOM methods (query text is user data, never HTML).
(() => {
  const root = document.getElementById('dbp');
  if (!root) return;
  const csrf = document.querySelector('meta[name=csrf]').content;
  const $ = (s) => root.querySelector(s);
  const T = root.dataset;
  const rows = $('[data-rows]'), q = $('[data-q]'), dbSel = $('[data-db]'), longDb = $('[data-long-db]'), showIdle = $('[data-show-idle]');
  const status = $('[data-status]'), auto = document.getElementById('dbp-auto'), allBox = $('[data-all]'), killSel = $('[data-kill-selected]');
  let data = [], timer = null, busy = false, note = '', noteUntil = 0;
  const selected = new Set(), open = new Set();

  const fmtTime = (s) => { const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
    return h ? `${h}h ${m}m` : m ? `${m}m ${s % 60}s` : `${s}s`; };
  const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined && text !== null) e.textContent = text; return e; };
  const isQuery = (t) => t.command === 'Query' || t.command === 'Execute';
  const sum = (k, v) => { $(`[data-sum="${k}"]`).textContent = v; };

  function fillDbs(sel, dbs) {
    const cur = sel.value;
    sel.length = 1;
    dbs.forEach((d) => sel.add(new Option(d, d)));
    sel.value = dbs.includes(cur) ? cur : '';
  }

  function summary(j) {
    const st = j.status || {}, v = j.vars || {}, user = data.filter((t) => !t.system);
    const running = user.filter(isQuery), idle = user.filter((t) => t.command === 'Sleep');
    sum('conn', `${st.threads_connected ?? user.length} / ${v.max_connections ?? '?'}`);
    sum('conn_sub', T.tIdle.replace(':n', idle.length) + ` · max ${st.max_used_connections ?? '?'}`);
    sum('running', running.length);
    sum('running_sub', `Threads_running: ${st.threads_running ?? '?'}`);
    const top = running.slice().sort((a, b) => b.time_ms - a.time_ms)[0];
    sum('longest', top ? fmtTime(top.time) : '–');
    sum('longest_sub', top ? `${top.user}${top.db ? ' · ' + top.db : ''}` : '');
    sum('slow', st.slow_queries ?? '–');
    sum('slow_sub', `long_query_time ${v.long_query_time ?? '?'} s · slow log ${v.slow_log ? 'on' : 'off'}`);
  }

  function render() {
    const needle = q.value.trim().toLowerCase(), db = dbSel.value;
    // running statements first (longest on top), then idle connections, then MariaDB's own threads
    const rank = (t) => (t.system ? 2 : t.command === 'Sleep' ? 1 : 0);
    const list = data.filter((t) => (showIdle.checked || (t.command !== 'Sleep' && !t.system))
      && (!db || t.db === db)
      && (!needle || `${t.id} ${t.user} ${t.label} ${t.db || ''} ${t.host} ${t.state || ''} ${t.info || ''}`.toLowerCase().includes(needle)))
      .sort((a, b) => rank(a) - rank(b) || b.time_ms - a.time_ms);
    for (const id of [...selected]) if (!data.some((t) => t.id === id && !t.system)) selected.delete(id);
    rows.replaceChildren();
    if (!list.length) {
      const tr = el('tr'), td = el('td', 'py-6 text-center text-slate-500', T.tNone); td.colSpan = 9; tr.append(td); rows.append(tr);
    }
    for (const t of list) {
      const tr = el('tr', t.time >= 60 && isQuery(t) ? 'bg-rose-50/70 dark:bg-rose-950/20' : t.time >= 10 && isQuery(t) ? 'bg-amber-50/70 dark:bg-amber-950/20' : '');
      const c0 = el('td');
      if (!t.system) {
        const cb = el('input'); cb.type = 'checkbox'; cb.checked = selected.has(t.id);
        cb.addEventListener('change', () => { cb.checked ? selected.add(t.id) : selected.delete(t.id); updateSel(); });
        c0.append(cb);
      }
      const user = el('td');
      user.append(el('div', 'font-medium', t.user), el('div', 'text-xs text-slate-500', [t.label, t.host].filter(Boolean).join(' · ')));
      const time = el('td', 'whitespace-nowrap text-right font-mono' + (isQuery(t) && t.time >= 10 ? ' font-semibold text-rose-700 dark:text-rose-300' : ''), fmtTime(t.time));
      const qtd = el('td', 'max-w-[40rem]');
      if (t.info) {
        const short = t.info.replace(/\s+/g, ' ');
        if (short.length > 140 || t.info.includes('\n')) {
          const d = el('details'); d.open = open.has(t.id);
          d.addEventListener('toggle', () => { d.open ? open.add(t.id) : open.delete(t.id); });
          const s = el('summary', 'cursor-pointer font-mono text-xs', short.slice(0, 140) + '…');
          const pre = el('pre', 'mt-2 max-h-80 overflow-auto whitespace-pre-wrap break-words rounded bg-slate-50 p-2 font-mono text-xs dark:bg-slate-900', t.info + (t.info_len > t.info.length ? '\n…' : ''));
          d.append(s, pre); qtd.append(d);
        } else {
          qtd.append(el('code', 'break-words font-mono text-xs', short));
        }
        if (t.rows_examined) qtd.append(el('div', 'text-xs text-slate-400', `rows examined: ${t.rows_examined}`));
      }
      const act = el('td', 'whitespace-nowrap text-right');
      if (t.system) {
        act.append(el('span', 'text-xs text-slate-400', T.tSystem));
      } else {
        if (isQuery(t)) {
          const b = el('button', 'btn-danger px-2 py-1 text-xs', T.tStop); b.type = 'button';
          b.addEventListener('click', () => kill({ ids: [t.id], mode: 'query' }, T.cStop.replace(':id', t.id).replace(':q', (t.info || '').slice(0, 160))));
          act.append(b, ' ');
        }
        const b2 = el('button', 'btn-ghost border border-slate-300 px-2 py-1 text-xs dark:border-slate-700', T.tClose); b2.type = 'button';
        b2.addEventListener('click', () => kill({ ids: [t.id], mode: 'connection' }, T.cClose.replace(':id', t.id).replace(':user', t.user)));
        act.append(b2);
      }
      tr.dataset.id = t.id;
      tr.append(c0, el('td', 'font-mono text-xs', t.id), user, el('td', '', t.db || '–'), el('td', '', t.command), time,
        el('td', 'text-xs text-slate-500', t.state || ''), qtd, act);
      rows.append(tr);
    }
    updateSel();
  }

  function updateSel() {
    killSel.classList.toggle('hidden', selected.size === 0);
    killSel.textContent = killSel.textContent.replace(/\s*\(\d+\)$/, '') + (selected.size ? ` (${selected.size})` : '');
    const selectable = [...rows.querySelectorAll('tr[data-id] input[type=checkbox]')];
    allBox.checked = selectable.length > 0 && selectable.every((c) => c.checked);
  }

  async function load() {
    if (busy) return;
    busy = true;
    try {
      const r = await fetch(root.dataset.url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
      const j = await r.json();
      if (!r.ok) throw new Error(j.error || r.status);
      data = j.threads;
      const dbs = [...new Set(data.map((t) => t.db).filter(Boolean))].sort();
      fillDbs(dbSel, dbs); fillDbs(longDb, dbs);
      summary(j);
      render();
      const upd = T.tUpdated.replace(':t', new Date(j.at * 1000).toLocaleTimeString());
      status.textContent = Date.now() < noteUntil ? `${note} · ${upd}` : upd;   // the result of an action stays readable for a while
    } catch (e) {
      status.textContent = '⚠ ' + e.message;
    } finally {
      busy = false;
    }
  }

  async function kill(body, question) {
    if (!confirm(question)) return;
    try {
      const r = await fetch(root.dataset.kill, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, Accept: 'application/json' }, body: JSON.stringify(body) });
      const j = await r.json().catch(() => ({}));
      note = j.ok ? '✓ ' + j.message : '⚠ ' + (j.error || 'HTTP ' + r.status);
      noteUntil = Date.now() + 8000;
      status.textContent = note;
      if (j.ok && body.ids) body.ids.forEach((id) => selected.delete(id));
    } catch (e) {
      status.textContent = '⚠ ' + e.message;
    }
    setTimeout(load, 500);
  }

  allBox.addEventListener('change', () => {
    rows.querySelectorAll('tr[data-id]').forEach((tr) => {
      const cb = tr.querySelector('input[type=checkbox]');
      if (!cb) return;
      cb.checked = allBox.checked;
      allBox.checked ? selected.add(+tr.dataset.id) : selected.delete(+tr.dataset.id);
    });
    updateSel();
  });
  killSel.addEventListener('click', () => kill({ ids: [...selected], mode: 'query' }, T.cSel.replace(':n', selected.size)));
  root.querySelectorAll('[data-bulk]').forEach((b) => b.addEventListener('click', () => {
    if (b.dataset.bulk === 'long') {
      const s = Math.max(1, +$('[data-long-sec]').value || 60);
      kill({ rule: 'long', seconds: s, db: longDb.value }, T.cLong.replace(':s', s).replace(':db', longDb.value || '*'));
    } else {
      const s = Math.max(1, +$('[data-sleep-sec]').value || 300);
      kill({ rule: 'sleep', seconds: s }, T.cSleep.replace(':s', s));
    }
  }));
  [q, dbSel, showIdle].forEach((x) => x.addEventListener('input', render));
  const tick = () => { clearInterval(timer); if (auto.checked) timer = setInterval(() => { if (!document.hidden) load(); }, 3000); };
  auto.addEventListener('change', tick);
  load();
  tick();
})();
