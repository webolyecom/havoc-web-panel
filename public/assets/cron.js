// Cron schedule builder: presets, frequency fields <-> expression, live description from the server.
(() => {
  const pad = (n) => String(n).padStart(2, '0');
  document.querySelectorAll('[data-cron-builder]').forEach((root) => {
    const f = (k) => root.querySelector(`[data-f="${k}"]`);
    const expr = f('expr');
    const fields = root.querySelector('[data-cron-fields]');
    const preview = root.querySelector('[data-cron-preview]');
    fields.classList.remove('hidden');
    fields.classList.add('grid');

    const show = () => {
      const mode = f('mode').value;
      root.querySelectorAll('[data-show]').forEach((el) => el.classList.toggle('hidden', !el.dataset.show.split(' ').includes(mode)));
    };

    // expression -> fields (best effort; anything unusual becomes "custom")
    const parse = (v) => {
      const p = v.trim().split(/\s+/);
      const set = (mode) => { f('mode').value = mode; show(); };
      if (p.length !== 5 || p[3] !== '*') return set('custom');
      const [mi, h, dom, , dow] = p;
      const num = (s) => /^\d+$/.test(s);
      const time = () => { f('time').value = `${pad(h)}:${pad(mi)}`; };
      let m;
      if (h === '*' && dom === '*' && dow === '*' && (mi === '*' || (m = mi.match(/^\*\/(\d+)$/)))) {
        const n = mi === '*' ? '1' : m[1];
        if (![...f('nmin').options].some((o) => o.value === n)) return set('custom');
        f('nmin').value = n; return set('minutes');
      }
      if (num(mi) && dom === '*' && dow === '*' && (h === '*' || (m = h.match(/^\*\/(\d+)$/)))) {
        const n = h === '*' ? '1' : m[1];
        if (![...f('nhour').options].some((o) => o.value === n)) return set('custom');
        f('nhour').value = n; f('minute').value = mi; return set('hours');
      }
      if (num(mi) && num(h) && dom === '*' && dow === '*') { time(); return set('day'); }
      if (num(mi) && num(h) && dom === '*' && /^[0-7](,[0-7])*$/.test(dow)) {
        const days = dow.split(',').map((d) => String(+d % 7));
        root.querySelectorAll('[data-f="dow"]').forEach((c) => { c.checked = days.includes(c.value); });
        time(); return set('week');
      }
      if (num(mi) && num(h) && num(dom) && dow === '*') { f('dom').value = String(+dom); time(); return set('month'); }
      return set('custom');
    };

    // fields -> expression
    const build = () => {
      const mode = f('mode').value;
      const [hh, mm] = (f('time').value || '03:00').split(':').map((x) => String(+x));
      const minute = String(Math.min(59, Math.max(0, +f('minute').value || 0)));
      if (mode === 'custom') return;
      if (mode === 'minutes') expr.value = f('nmin').value === '1' ? '* * * * *' : `*/${f('nmin').value} * * * *`;
      if (mode === 'hours') expr.value = `${minute} ${f('nhour').value === '1' ? '*' : '*/' + f('nhour').value} * * *`;
      if (mode === 'day') expr.value = `${mm} ${hh} * * *`;
      if (mode === 'week') {
        const days = [...root.querySelectorAll('[data-f="dow"]:checked')].map((c) => c.value).sort();
        expr.value = `${mm} ${hh} * * ${days.length ? days.join(',') : '1'}`;
      }
      if (mode === 'month') expr.value = `${mm} ${hh} ${f('dom').value} * *`;
      refresh();
    };

    let timer = null;
    const refresh = () => {
      clearTimeout(timer);
      timer = setTimeout(async () => {
        try {
          const r = await fetch('/cron/preview?schedule=' + encodeURIComponent(expr.value), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
          const j = await r.json();
          preview.textContent = j.text + (j.next && j.next.length ? '  →  ' + j.next.join(' · ') : '');
        } catch (_) { preview.textContent = ''; }
      }, 250);
    };

    root.querySelectorAll('[data-cron-preset]').forEach((b) => b.addEventListener('click', () => { expr.value = b.dataset.cronPreset; parse(expr.value); refresh(); }));
    fields.addEventListener('change', (e) => { if (e.target.dataset.f === 'mode') show(); build(); });
    fields.addEventListener('input', (e) => { if (e.target.dataset.f === 'minute' || e.target.dataset.f === 'time') build(); });
    expr.addEventListener('input', () => { parse(expr.value); refresh(); });
    parse(expr.value);
    refresh();
  });
})();
