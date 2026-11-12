// Havoc WEB Panel - vanilla JS, no inline handlers (strict CSP).
(() => {
  const $ = (s, r = document) => r.querySelector(s);

  // theme toggle (light -> dark -> system)
  const root = document.documentElement;
  const applyTheme = (t) => {
    localStorage.setItem('havoc_theme', t);
    root.classList.toggle('dark', t === 'dark' || (t === 'system' && matchMedia('(prefers-color-scheme: dark)').matches));
  };
  $('#theme-btn')?.addEventListener('click', () => {
    const order = ['light', 'dark', 'system'];
    const cur = localStorage.getItem('havoc_theme') || 'system';
    applyTheme(order[(order.indexOf(cur) + 1) % 3]);
  });
  $('#theme-select')?.addEventListener('change', (e) => applyTheme(e.target.value));
  // mobile drawer
  const drawer = (open) => { root.classList.toggle('sidebar-open', open); $('#sidebar-backdrop')?.classList.toggle('hidden', !open); };
  $('#menu-btn')?.addEventListener('click', () => drawer(true));
  $('#menu-close')?.addEventListener('click', () => drawer(false));
  $('#sidebar-backdrop')?.addEventListener('click', () => drawer(false));
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && root.classList.contains('sidebar-open')) drawer(false); });

  // compact rail on desktop
  const compactBtn = $('#nav-compact');
  const setCompact = (on) => {
    root.classList.toggle('nav-compact', on);
    if (on) document.querySelectorAll('[data-nav-group]').forEach((g) => { g.open = true; });
    if (compactBtn) compactBtn.title = compactBtn.dataset[on ? 'expand' : 'collapse'] || compactBtn.title;
    try { localStorage.setItem('havoc_nav_compact', on ? '1' : '0'); } catch (_) {}
  };
  if (compactBtn) {
    compactBtn.addEventListener('click', () => setCompact(!root.classList.contains('nav-compact')));
    if (root.classList.contains('nav-compact')) setCompact(true);
  }

  // menu filter (Ctrl/Cmd+K focuses it, Enter opens the first match)
  const filter = $('#nav-filter');
  if (filter) {
    const groups = [...document.querySelectorAll('#side-nav [data-nav-group], #side-nav > .nav-items')];
    const fold = (s) => s.toLocaleLowerCase(document.documentElement.lang).normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/ı/g, 'i');
    filter.addEventListener('input', () => {
      const q = fold(filter.value.trim());
      let any = false;
      groups.forEach((g) => {
        let hit = false;
        g.querySelectorAll('.nav-link').forEach((a) => { const ok = !q || fold(a.textContent).includes(q); a.hidden = !ok; hit = hit || ok; });
        g.hidden = !hit;
        if (q && hit && g.tagName === 'DETAILS') g.open = true;
        any = any || hit;
      });
      $('#nav-empty')?.classList.toggle('hidden', any);
    });
    filter.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { const a = [...document.querySelectorAll('#side-nav .nav-link')].find((x) => !x.hidden && !x.closest('[hidden]')); if (a) a.click(); }
      if (e.key === 'Escape') { filter.value = ''; filter.dispatchEvent(new Event('input')); filter.blur(); }
    });
    document.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        if (root.classList.contains('nav-compact')) setCompact(false);
        if (window.innerWidth < 768) drawer(true);
        filter.focus(); filter.select();
      }
    });
  }

  // sidebar groups remember being collapsed (the group of the current page always stays open)
  document.querySelectorAll('[data-nav-group]').forEach((g) => {
    const k = 'havoc_nav_' + g.dataset.navGroup;
    try { if (!g.hasAttribute('data-active') && localStorage.getItem(k) === 'closed') g.open = false; } catch (_) {}
    g.addEventListener('toggle', () => { try { localStorage.setItem(k, g.open ? 'open' : 'closed'); } catch (_) {} });
  });

  // pages with running background jobs refresh themselves
  if ($('[data-autorefresh]')) setTimeout(() => location.reload(), 5000);

  // snippet buttons: append a ready-made block to a textarea
  document.querySelectorAll('[data-snippet]').forEach((b) => b.addEventListener('click', () => {
    const t = document.getElementById(b.dataset.snippetTarget);
    if (!t) return;
    t.value = (t.value.trim() ? t.value.replace(/\s*$/, '') + '\n\n' : '') + b.dataset.snippet + '\n';
    t.focus();
  }));

  // preset buttons: fill a form from a JSON object (checkboxes get true/false)
  document.querySelectorAll('[data-fill-form]').forEach((b) => b.addEventListener('click', () => {
    const f = document.getElementById(b.dataset.fillForm);
    const vals = JSON.parse(b.dataset.values || '{}');
    Object.entries(vals).forEach(([k, v]) => {
      const el = f.elements[k];
      if (!el) return;
      if (el.type === 'checkbox') el.checked = !!v; else el.value = String(v);
      el.classList.add('ring-2', 'ring-brand/40');
      setTimeout(() => el.classList.remove('ring-2', 'ring-brand/40'), 1500);
    });
  }));

  // confirm dialogs
  document.addEventListener('submit', (e) => {
    const m = e.target.dataset?.confirm;
    if (m && !confirm(m)) e.preventDefault();
  });

  // server monitor
  const mon = $('#monitor');
  if (mon) {
    let last = null;
    const set = (k, v) => { const el = $(`[data-v="${k}"]`, mon); if (el) el.textContent = v; };
    const bar = (k, p) => { const el = $(`[data-bar="${k}"]`, mon); if (el) el.style.width = Math.min(100, p) + '%'; };
    const fmt = (b) => { const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0; while (b >= 1024 && i < 4) { b /= 1024; i++; } return b.toFixed(i ? 1 : 0) + ' ' + u[i]; };
    const up = (s) => `${Math.floor(s / 86400)}d ${Math.floor(s % 86400 / 3600)}h ${Math.floor(s % 3600 / 60)}m`;
    const poll = async () => {
      try {
        const s = await (await fetch(mon.dataset.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })).json();
        set('cpu', s.cpu + '%'); bar('cpu', s.cpu);
        set('mem', `${s.mem.pct}% · ${fmt(s.mem.used)} / ${fmt(s.mem.total)}`); bar('mem', s.mem.pct);
        set('disk', `${s.disk.pct}% · ${fmt(s.disk.used)} / ${fmt(s.disk.total)}`); bar('disk', s.disk.pct);
        set('load', s.load.join(' · '));
        set('uptime', up(s.uptime));
        if (last) {
          const dt = Math.max(1, s.time - last.time);
          set('rx', fmt((s.net.rx - last.net.rx) / dt) + '/s');
          set('tx', fmt((s.net.tx - last.net.tx) / dt) + '/s');
        }
        last = s;
      } catch (_) {}
    };
    poll();
    setInterval(poll, 3000);
  }
})();
