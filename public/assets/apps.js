// Applications page: one-time upload link, environment rows, live log tail.
(() => {
  const csrf = document.querySelector('meta[name=csrf]')?.content || '';

  // Get an upload link (the user uploads the build in the browser; the file never passes through the panel request).
  const linkBtn = document.querySelector('[data-upload-link]');
  if (linkBtn) {
    const out = document.querySelector('[data-upload-out]');
    linkBtn.addEventListener('click', async () => {
      linkBtn.disabled = true;
      try {
        const r = await fetch(linkBtn.dataset.uploadLink, { method: 'POST', headers: { 'X-CSRF-Token': csrf, Accept: 'application/json' }, credentials: 'same-origin' });
        const j = await r.json();
        if (!r.ok) throw new Error(j.error || r.status);
        out.replaceChildren();
        const a = document.createElement('a'); a.href = j.url; a.textContent = j.url; a.target = '_blank'; a.rel = 'noopener'; a.className = 'link break-all';
        const copy = document.createElement('button'); copy.type = 'button'; copy.textContent = (out.dataset.copy || 'Copy'); copy.className = 'ml-2 link';
        copy.addEventListener('click', () => { navigator.clipboard?.writeText(j.url); copy.textContent = out.dataset.copied || 'Copied'; });
        out.append(a, copy);
      } catch (e) {
        out.textContent = '⚠ ' + e.message;
      } finally {
        linkBtn.disabled = false;
      }
    });
    out.dataset.copy = linkBtn.dataset.copy || 'Copy';
  }

  // Environment: add more KEY/value rows.
  const rows = document.querySelector('[data-env-rows]');
  const add = document.querySelector('[data-env-add]');
  if (rows && add) {
    let i = 1;
    add.addEventListener('click', () => {
      const row = document.createElement('div');
      row.className = 'flex gap-2';
      row.innerHTML = '';
      const k = document.createElement('input'); k.className = 'input font-mono'; k.name = `env[${i}][key]`; k.placeholder = 'KEY'; k.pattern = '[A-Z_][A-Z0-9_]*'; k.autocomplete = 'off';
      const v = document.createElement('input'); v.className = 'input'; v.name = `env[${i}][value]`; v.placeholder = rows.dataset.value || 'value'; v.autocomplete = 'off';
      row.append(k, v); rows.append(row); i++; k.focus();
    });
  }

  // Live log tail.
  const log = document.getElementById('app-log');
  const auto = document.getElementById('app-log-auto');
  if (log) {
    let timer = null, busy = false;
    const load = async () => {
      if (busy) return;
      busy = true;
      try {
        const r = await fetch(log.dataset.url + '?lines=400', { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
        const j = await r.json();
        const atBottom = log.scrollTop + log.clientHeight >= log.scrollHeight - 20;
        log.textContent = j.log || j.error || '';
        if (atBottom) log.scrollTop = log.scrollHeight;
      } catch (e) {
        /* keep the last content on a transient error */
      } finally {
        busy = false;
      }
    };
    const tick = () => { clearInterval(timer); if (auto?.checked) timer = setInterval(() => { if (!document.hidden) load(); }, 4000); };
    auto?.addEventListener('change', tick);
    load().then(() => { log.scrollTop = log.scrollHeight; });
    tick();
  }
})();
