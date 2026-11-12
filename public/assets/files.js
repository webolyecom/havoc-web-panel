// Havoc file manager. Every name shown comes from the tenant, so the DOM is built with textContent only.
(() => {
  const app = document.getElementById('fm');
  if (!app) return;
  const T = JSON.parse(app.dataset.t);
  const csrf = document.querySelector('meta[name=csrf]').content;
  const nonceMeta = document.querySelector('meta[name=csp-nonce]');
  const $ = (id) => document.getElementById(id);
  const TEXT_EXT = /\.(php|phtml|inc|js|mjs|cjs|ts|tsx|jsx|json|css|scss|less|html?|twig|blade|tpl|xml|svg|md|markdown|txt|log|ini|conf|cnf|env|ya?ml|sh|bash|py|rb|sql|csv|htaccess|htpasswd|gitignore|editorconfig|lock|vue|svelte|map)$/i;
  const IMG_EXT = /\.(png|jpe?g|gif|webp|svg|ico|avif|bmp)$/i;
  const ZIP_EXT = /\.zip$/i;
  const TAR_EXT = /\.(tar|tar\.gz|tgz|tar\.bz2|tbz2|tar\.xz|txz)$/i;
  const CHUNK = 4 * 1024 * 1024;
  // progress widths used below (listed literally so Tailwind generates them): w-[10%] w-[20%] w-[30%] w-[40%] w-[50%] w-[60%] w-[70%] w-[80%] w-[90%]

  let cwd = '';
  let items = [];
  let sort = { key: 'name', dir: 1 };
  let searchMode = false;
  const sel = new Set();

  // ---------- helpers ----------
  const h = (tag, cls, text, on) => {
    const el = document.createElement(tag);
    if (cls) el.className = cls;
    if (text != null) el.textContent = text;
    if (on) el.addEventListener('click', on);
    return el;
  };
  const join = (a, b) => (a ? a + '/' : '') + b;
  const parent = (p) => p.split('/').slice(0, -1).join('/');
  const size = (b) => { const u = ['B', 'KB', 'MB', 'GB']; let i = 0; while (b >= 1024 && i < 3) { b /= 1024; i++; } return b.toFixed(i ? 1 : 0) + ' ' + u[i]; };
  const date = (s) => new Date(s * 1000).toLocaleString();

  const call = async (op, args = {}) => {
    const r = await fetch(app.dataset.api, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, Accept: 'application/json' },
      body: JSON.stringify({ op, args }),
    });
    let j = {};
    try { j = await r.json(); } catch (_) { throw new Error('HTTP ' + r.status); }
    if (!r.ok || j.error) throw new Error(j.error || 'HTTP ' + r.status);
    return j;
  };

  let toastTimer = null;
  const toast = (msg, bad = false) => {
    const t = $('fm-toast');
    t.textContent = msg;
    t.className = 'fixed bottom-4 left-1/2 z-50 flex -translate-x-1/2 rounded-lg px-4 py-2 text-sm shadow-lg ' +
      (bad ? 'bg-red-600 text-white' : 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.add('hidden'), bad ? 6000 : 2500);
  };
  const act = (fn) => async (...a) => { try { await fn(...a); } catch (e) { toast(e.message, true); } };

  // ---------- modal ----------
  const modal = (title, build, okText = T.apply) => new Promise((resolve) => {
    const m = $('fm-modal'), body = $('fm-modal-body');
    $('fm-modal-title').textContent = title;
    body.textContent = '';
    const get = build(body) || (() => true);
    $('fm-modal-ok').textContent = okText;
    $('fm-modal-cancel').textContent = T.cancel;
    m.classList.remove('hidden'); m.classList.add('flex');
    const first = body.querySelector('input,select'); if (first) { first.focus(); if (first.select) first.select(); }
    const close = (v) => { m.classList.add('hidden'); m.classList.remove('flex'); document.removeEventListener('keydown', key); resolve(v); };
    const key = (e) => { if (e.key === 'Escape') close(null); if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') { e.preventDefault(); close(get()); } };
    document.addEventListener('keydown', key);
    $('fm-modal-ok').onclick = () => close(get());
    $('fm-modal-cancel').onclick = () => close(null);
  });
  const field = (body, label, value = '', attrs = {}) => {
    const l = h('label', 'block'); l.append(h('span', 'label', label));
    const i = h('input', 'input'); i.value = value; Object.assign(i, attrs); l.append(i); body.append(l); return i;
  };
  const ask = (title, label, value) => modal(title, (b) => { const i = field(b, label, value); return () => i.value.trim(); });
  const confirmBox = (title, text) => modal(title, (b) => { b.append(h('p', 'text-sm', text)); return () => true; }, T.apply);

  // ---------- listing ----------
  const load = act(async (path = cwd) => {
    const r = await call('list', { path });
    cwd = r.path; items = r.items; searchMode = false; sel.clear();
    $('fm-search').value = '';
    render();
  });

  const crumbs = () => {
    const c = $('fm-crumbs'); c.textContent = '';
    c.append(h('button', 'rounded px-1 text-brand hover:underline', '/ ' + T.root, () => load('')));
    let acc = '';
    cwd.split('/').filter(Boolean).forEach((p) => {
      acc = join(acc, p); const target = acc;
      c.append(h('span', 'text-slate-400', '/'), h('button', 'rounded px-1 text-brand hover:underline', p, () => load(target)));
    });
    if (searchMode) c.append(h('span', 'ml-2 text-slate-500', '· ' + T.results), h('button', 'ml-2 text-brand hover:underline', T.back_to_folder, () => load(cwd)));
  };

  const updateSel = () => {
    const bar = $('fm-selbar');
    bar.classList.toggle('hidden', sel.size === 0); bar.classList.toggle('flex', sel.size > 0);
    $('fm-selcount').textContent = T.selected.replace(':n', sel.size);
    const all = $('fm-all');
    all.checked = items.length > 0 && sel.size === items.length;
    all.indeterminate = sel.size > 0 && sel.size < items.length;
  };

  const btn = 'rounded px-1.5 py-0.5 text-xs text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800 dark:hover:text-slate-200';

  const open = (it, p) => {
    if (it.dir) return load(p);
    if (IMG_EXT.test(it.name)) return preview(p);
    if (TEXT_EXT.test(it.name) || it.size < 512 * 1024) return edit(p);
    return download(p);
  };

  const render = () => {
    crumbs();
    const tb = $('fm-list'); tb.textContent = '';
    const rows = [...items].sort((a, b) => {
      if (!searchMode && a.dir !== b.dir) return a.dir ? -1 : 1;
      const k = sort.key, x = a[k], y = b[k];
      return (typeof x === 'string' ? x.localeCompare(y, undefined, { numeric: true, sensitivity: 'base' }) : x - y) * sort.dir;
    });
    if (!searchMode && cwd) {
      const tr = h('tr', 'cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/50');
      tr.append(h('td'), h('td', 'font-medium text-brand', '↰ ..'), h('td', 'hidden sm:table-cell'), h('td', 'hidden md:table-cell'), h('td', 'hidden md:table-cell'), h('td'));
      tr.addEventListener('click', () => load(parent(cwd)));
      tb.append(tr);
    }
    if (!rows.length) {
      const tr = h('tr'); const td = h('td', 'py-10 text-center text-slate-400', searchMode ? T.no_results : T.empty); td.colSpan = 6; tr.append(td); tb.append(tr);
    }
    rows.forEach((it) => {
      const p = searchMode ? it.path : join(cwd, it.name);
      const tr = h('tr', 'group hover:bg-slate-50 dark:hover:bg-slate-800/50' + (sel.has(p) ? ' bg-brand/5' : ''));
      const cb = h('input'); cb.type = 'checkbox'; cb.checked = sel.has(p); cb.setAttribute('aria-label', it.name);
      cb.addEventListener('change', () => { cb.checked ? sel.add(p) : sel.delete(p); tr.classList.toggle('bg-brand/5', cb.checked); updateSel(); });
      const td0 = h('td'); td0.append(cb);

      const nameTd = h('td', 'max-w-[18rem] sm:max-w-md');
      const nameBtn = h('button', 'flex w-full min-w-0 items-center gap-2 text-left ' + (it.dir ? 'font-medium text-brand' : ''), null, () => open(it, p));
      nameBtn.append(h('span', 'shrink-0', it.dir ? '📁' : it.link ? '🔗' : IMG_EXT.test(it.name) ? '🖼' : ZIP_EXT.test(it.name) || TAR_EXT.test(it.name) ? '🗜' : '📄'),
        h('span', 'truncate', searchMode ? it.path : it.name));
      nameTd.append(nameBtn);

      const ops = h('td', 'whitespace-nowrap text-right');
      const wrap = h('span', 'inline-flex gap-0.5 sm:opacity-60 sm:group-hover:opacity-100');
      if (!it.dir && !searchMode) {
        if (TEXT_EXT.test(it.name)) wrap.append(h('button', btn, T.edit, act(() => edit(p))));
        if (IMG_EXT.test(it.name)) wrap.append(h('button', btn, T.preview, act(() => preview(p))));
        if (ZIP_EXT.test(it.name)) wrap.append(h('button', btn, T.extract, act(async () => { await call('unzip', { path: p, dest: cwd }); toast(T.done); load(); })));
        if (TAR_EXT.test(it.name)) wrap.append(h('button', btn, T.extract, act(async () => { await call('untar', { path: p, dest: cwd }); toast(T.done); load(); })));
        wrap.append(h('button', btn, '⬇', () => download(p)));
      }
      if (searchMode) wrap.append(h('button', btn, T.open, () => (it.dir ? load(p) : load(parent(p)))));
      else {
        wrap.append(h('button', btn, T.rename, act(async () => {
          const n = await ask(T.rename, T.name, it.name);
          if (n && n !== it.name) { await call('rename', { from: p, to: join(cwd, n) }); load(); }
        })));
        wrap.append(h('button', btn + ' font-mono', it.perm, act(() => perms([p], it.dir))));
      }
      ops.append(wrap);
      tr.append(td0, nameTd,
        h('td', 'hidden text-slate-500 sm:table-cell', it.dir ? '' : size(it.size)),
        h('td', 'hidden text-slate-500 md:table-cell', date(it.mtime)),
        h('td', 'hidden font-mono text-xs text-slate-500 md:table-cell', it.perm || ''), ops);
      tb.append(tr);
    });
    updateSel();
  };

  document.querySelectorAll('[data-sort]').forEach((b) => b.addEventListener('click', () => {
    const k = b.dataset.sort; sort = { key: k, dir: sort.key === k ? -sort.dir : 1 }; render();
  }));
  $('fm-all').addEventListener('change', (e) => {
    sel.clear(); if (e.target.checked) items.forEach((it) => sel.add(searchMode ? it.path : join(cwd, it.name))); render();
  });
  const site = $('fm-site'); if (site) site.addEventListener('change', () => { location.href = site.value; });

  // ---------- actions ----------
  const download = (p) => { location.href = app.dataset.dl + '?path=' + encodeURIComponent(p); };

  const preview = async (p) => {
    const r = await call('preview', { path: p });
    await modal(p.split('/').pop(), (b) => {
      const img = h('img', 'mx-auto max-h-[60vh] max-w-full rounded'); img.alt = ''; img.src = `data:${r.mime};base64,${r.b64}`;
      b.append(img, h('p', 'text-center text-xs text-slate-500', size(r.size)));
      return () => true;
    }, '⬇ ' + T.download).then((ok) => { if (ok) download(p); });
  };

  const perms = async (paths, isDir) => {
    const v = await modal(T.perms, (b) => {
      const fm = field(b, T.file_mode, isDir ? '644' : (items.find((i) => join(cwd, i.name) === paths[0]) || {}).perm || '644', { maxLength: 4, inputMode: 'numeric' });
      const dm = field(b, T.dir_mode, '755', { maxLength: 4, inputMode: 'numeric' });
      const l = h('label', 'flex items-center gap-2 text-sm'); const rc = h('input'); rc.type = 'checkbox'; rc.checked = paths.length > 1 || isDir; l.append(rc, T.recursive); b.append(l);
      return () => ({ file_mode: fm.value.trim(), dir_mode: dm.value.trim(), recursive: rc.checked });
    });
    if (!v) return;
    const r = await call('chmodr', { paths, ...v });
    toast(`${T.done} (${r.count})`); load();
  };

  const pickFolder = (title) => modal(title, (b) => {
    const i = field(b, T.target_folder, '/' + cwd);
    return () => i.value.trim().replace(/^\/+|\/+$/g, '');
  });

  const run = {
    mkdir: act(async () => { const n = await ask(T.new_folder, T.folder_name, ''); if (n) { await call('mkdir', { path: join(cwd, n) }); load(); } }),
    touch: act(async () => { const n = await ask(T.new_file, T.file_name, 'index.php'); if (n) { await call('touch', { path: join(cwd, n) }); await load(); edit(join(cwd, n)); } }),
    copy: act(async () => { const d = await pickFolder(T.copy); if (d !== null) { await call('copy', { paths: [...sel], dest: d }); toast(T.copy_done); load(d); } }),
    move: act(async () => { const d = await pickFolder(T.move); if (d !== null) { await call('move', { paths: [...sel], dest: d }); toast(T.move_done); load(d); } }),
    perms: act(() => perms([...sel], true)),
    compress: act(async () => {
      const v = await modal(T.compress, (b) => {
        const n = field(b, T.archive_name, 'archive');
        const l = h('label', 'block'); l.append(h('span', 'label', T.format));
        const s = h('select', 'input'); ['zip', 'tar.gz'].forEach((f) => { const o = h('option', null, f); o.value = f; s.append(o); }); l.append(s); b.append(l);
        return () => ({ name: n.value.trim(), fmt: s.value });
      });
      if (!v || !v.name) return;
      const dest = join(cwd, v.name.replace(/\.(zip|tar\.gz|tgz)$/i, ''));
      await call(v.fmt === 'zip' ? 'zip' : 'targz', { paths: [...sel], dest: v.fmt === 'zip' ? dest + '.zip' : dest + '.tar.gz' });
      toast(T.done); load();
    }),
    delete: act(async () => {
      if (!(await confirmBox(T.delete, T.confirm_delete.replace(':n', sel.size)))) return;
      await call('delete', { paths: [...sel] }); load();
    }),
  };
  app.querySelectorAll('[data-act]').forEach((b) => b.addEventListener('click', () => run[b.dataset.act]()));

  // ---------- search ----------
  let searchTimer = null;
  $('fm-search').addEventListener('input', (e) => {
    clearTimeout(searchTimer);
    const q = e.target.value.trim();
    if (q.length < 2) { if (searchMode) load(cwd); return; }
    searchTimer = setTimeout(act(async () => {
      const r = await call('search', { path: cwd, q });
      items = r.results.map((x) => ({ ...x, name: x.path.split('/').pop(), perm: '' }));
      searchMode = true; sel.clear(); render();
      if (r.limited) toast(T.results_limited);
    }), 350);
  });

  // ---------- uploads (chunked, any size up to 2 GB) ----------
  const b64 = (blob) => new Promise((res, rej) => { const r = new FileReader(); r.onload = () => res(String(r.result).split(',')[1] || ''); r.onerror = rej; r.readAsDataURL(blob); });
  const uploadOne = async (file, dir) => {
    const row = h('div', 'card space-y-1 p-3 text-xs');
    const label = h('div', 'flex justify-between gap-2'); const name = h('span', 'truncate', file.name); const pct = h('span', 'shrink-0', '0%');
    label.append(name, pct);
    const bar = h('div', 'h-1.5 rounded bg-slate-200 dark:bg-slate-800'); const fill = h('div', 'h-1.5 w-0 rounded bg-brand transition-all'); bar.append(fill);
    row.append(label, bar); $('fm-uploads').append(row);
    const steps = [0, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100];
    const setPct = (p) => { pct.textContent = p + '%'; const s = steps.reduce((a, x) => (p >= x ? x : a), 0); fill.className = 'h-1.5 rounded bg-brand transition-all ' + (s === 100 ? 'w-full' : s === 0 ? 'w-0' : `w-[${s}%]`); };
    try {
      const path = join(dir, file.name);
      if (file.size === 0) { await call('chunk', { path, b64: '', offset: 0, final: true }); }
      for (let off = 0; off < file.size; off += CHUNK) {
        const part = file.slice(off, off + CHUNK);
        await call('chunk', { path, b64: await b64(part), offset: off, final: off + CHUNK >= file.size });
        setPct(Math.min(100, Math.round(((off + part.size) / file.size) * 100)));
      }
      setPct(100); pct.textContent = T.uploaded;
      setTimeout(() => row.remove(), 2500);
    } catch (e) {
      pct.textContent = T.upload_failed; row.classList.add('border-red-400'); row.append(h('div', 'text-red-600', e.message));
      setTimeout(() => row.remove(), 10000);
    }
  };
  const uploadAll = async (files) => {
    const dir = cwd;
    for (const f of files) await uploadOne(f, dir);   // sequential: keeps chunk order and server load predictable
    if (cwd === dir && !searchMode) load(dir);
  };
  $('fm-upload').addEventListener('change', (e) => { uploadAll([...e.target.files]); e.target.value = ''; });
  const drop = $('fm-drop'), mask = $('fm-dropmask');
  let depth = 0;
  const showMask = (on) => { mask.classList.toggle('hidden', !on); mask.classList.toggle('flex', on); };
  drop.addEventListener('dragenter', (e) => { if ([...e.dataTransfer.types].includes('Files')) { e.preventDefault(); depth++; showMask(true); } });
  drop.addEventListener('dragover', (e) => { if ([...e.dataTransfer.types].includes('Files')) e.preventDefault(); });
  drop.addEventListener('dragleave', () => { depth = Math.max(0, depth - 1); if (!depth) showMask(false); });
  drop.addEventListener('drop', (e) => {
    e.preventDefault(); depth = 0; showMask(false);
    const files = [...e.dataTransfer.files]; if (files.length) uploadAll(files);
  });

  // ---------- editor (CodeMirror, loaded on first use) ----------
  let editorLib = null, editor = null, editing = null, dirty = false;
  const loadEditor = () => editorLib || (editorLib = new Promise((res, rej) => {
    const s = document.createElement('script'); s.src = app.dataset.editor; s.onload = () => res(window.HavocEditor); s.onerror = () => rej(new Error('editor')); document.head.append(s);
  }));
  const setDirty = (d) => { dirty = d; $('fm-edit-dirty').textContent = d ? '● ' + T.unsaved : ''; };
  const save = act(async () => {
    if (!editor) return;
    await call('write', { path: editing, content: editor.getValue() });
    setDirty(false); toast(T.saved);
  });
  const closeEditor = () => {
    if (dirty && !window.confirm(T.close_unsaved)) return;
    $('fm-editor').classList.add('hidden'); $('fm-editor').classList.remove('flex');
    if (editor) { editor.destroy(); editor = null; }
    editing = null; setDirty(false); load();
  };
  const edit = act(async (p) => {
    toast(T.loading);
    const [r, lib] = await Promise.all([call('read', { path: p }), loadEditor()]);
    editing = p;
    $('fm-edit-name').textContent = p;
    const area = $('fm-edit-area'); area.textContent = '';
    $('fm-editor').classList.remove('hidden'); $('fm-editor').classList.add('flex');
    editor = lib.create(area, {
      value: r.content, filename: p, dark: document.documentElement.classList.contains('dark'),
      nonce: nonceMeta ? nonceMeta.content : '', onSave: save, onChange: () => setDirty(true),
    });
    setDirty(false); editor.focus();
  });
  $('fm-save').addEventListener('click', save);
  $('fm-close').addEventListener('click', closeEditor);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && editor && $('fm-modal').classList.contains('hidden')) closeEditor(); });
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  load('');
})();
