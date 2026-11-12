// Live log viewer: follows the file (offset based), client-side filter with highlighting, line-limit, wrap toggle.
(() => {
  const view = document.getElementById('log-view');
  if (!view) return;
  const $ = (id) => document.getElementById(id);
  const MAX = 5000;
  let lines = [], offset = null, paused = false, filter = '', partial = '';

  const site = $('log-site');
  if (site) site.addEventListener('change', () => {
    const u = new URL(location.href); u.searchParams.set('domain_id', site.value); location.href = u.toString();
  });

  const cls = (l) => (/\b(error|crit|alert|emerg|fatal)\b|\[(error|crit)\]|PHP (Fatal|Parse) error/i.test(l) ? 'text-red-400'
    : /\bwarn(ing)?\b|\[warn\]|PHP Warning/i.test(l) ? 'text-amber-300'
    : /" [45]\d\d /.test(l) ? 'text-amber-200' : '');

  const render = () => {
    const stick = view.scrollTop + view.clientHeight >= view.scrollHeight - 40;
    const q = filter.toLowerCase();
    const shown = q ? lines.filter((l) => l.toLowerCase().includes(q)) : lines;
    const frag = document.createDocumentFragment();
    shown.forEach((l) => {
      const div = document.createElement('div');
      const c = cls(l); if (c) div.className = c;
      if (q) {
        let i = 0, low = l.toLowerCase(), p;
        while ((p = low.indexOf(q, i)) !== -1) {
          div.append(l.slice(i, p));
          const m = document.createElement('mark'); m.className = 'rounded bg-yellow-300 px-0.5 text-black'; m.textContent = l.slice(p, p + q.length); div.append(m);
          i = p + q.length;
        }
        div.append(l.slice(i));
      } else div.textContent = l || ' ';
      frag.append(div);
    });
    view.textContent = ''; view.append(frag);
    $('log-count').textContent = q ? `${shown.length} / ${lines.length}` : `${lines.length}`;
    if (stick || offset === null) view.scrollTop = view.scrollHeight;
  };

  const add = (text, reset) => {
    if (reset) { lines = []; partial = ''; }
    const parts = (partial + text).split('\n');
    partial = parts.pop();
    lines.push(...parts);
    if (reset && partial) { lines.push(partial); partial = ''; }
    if (lines.length > MAX) lines = lines.slice(-MAX);
  };

  const tick = async () => {
    if (paused) return;
    try {
      const q = new URLSearchParams({ lines: $('log-lines').value });
      if (offset !== null) q.set('offset', offset);
      const r = await fetch(view.dataset.url + '&' + q, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const j = await r.json();
      if (j.error) { $('log-status').textContent = j.error; return; }
      const first = offset === null;
      if (j.data || first) { add(j.data || '', first); render(); }
      offset = j.offset;
      $('log-status').textContent = '● ' + new Date().toLocaleTimeString();
    } catch (_) { $('log-status').textContent = '…'; }
  };

  $('log-filter').addEventListener('input', (e) => { filter = e.target.value.trim(); render(); });
  $('log-lines').addEventListener('change', () => { offset = null; tick(); });
  $('log-wrap').addEventListener('change', (e) => { view.classList.toggle('whitespace-pre-wrap', e.target.checked); view.classList.toggle('break-all', e.target.checked); view.classList.toggle('whitespace-pre', !e.target.checked); });
  const tg = $('log-toggle');
  tg.addEventListener('click', () => { paused = !paused; tg.textContent = paused ? tg.dataset.resume : tg.dataset.pause; if (!paused) tick(); });
  tick();
  setInterval(tick, 2000);
})();
