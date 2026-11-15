// One-time upload page: sends the archive in chunks, in order, with retries.
(() => {
  const root = document.getElementById('upload');
  if (!root) return;
  const url = root.dataset.url, CHUNK = +root.dataset.chunk, MAX = +root.dataset.max;
  const input = root.querySelector('input[type=file]'), drop = root.querySelector('[data-drop]');
  const prog = root.querySelector('[data-progress]'), bar = root.querySelector('[data-bar]'), status = root.querySelector('[data-status]');
  const result = root.querySelector('[data-result]');
  const fmt = (n) => (n >= 1073741824 ? (n / 1073741824).toFixed(2) + ' GB' : (n / 1048576).toFixed(1) + ' MB');
  const show = (ok, text) => {
    result.textContent = text;
    result.className = 'rounded-lg p-3 text-sm ' + (ok ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200' : 'bg-rose-50 text-rose-800 dark:bg-rose-950/40 dark:text-rose-200');
  };
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  let busy = false;

  async function send(file) {
    if (busy) return;
    if (!/\.(zip|tar\.gz|tgz|tar)$/i.test(file.name) || file.size === 0) return show(false, root.dataset.msgType);
    if (file.size > MAX) return show(false, root.dataset.msgBig);
    busy = true;
    drop.classList.add('pointer-events-none', 'opacity-50');
    prog.classList.remove('hidden');
    result.className = 'hidden';
    const started = Date.now();
    let off = 0, last = null;
    try {
      while (off < file.size) {
        const part = file.slice(off, off + CHUNK);
        const final = off + part.size >= file.size;
        for (let tries = 1; ; tries++) {
          try {
            const r = await fetch(`${url}?offset=${off}&final=${final ? 1 : 0}&name=${encodeURIComponent(file.name)}`,
              { method: 'POST', body: part, headers: { 'Content-Type': 'application/octet-stream' } });
            last = await r.json();
            if (!r.ok) throw new Error(last.error || r.status);
            break;
          } catch (e) {
            if (tries >= 3 || /sync|large|only|invalid|expired/i.test(String(e.message))) throw e;
            await sleep(1500 * tries);
          }
        }
        off += part.size;
        const pct = Math.round((off / file.size) * 100);
        const secs = (Date.now() - started) / 1000;
        bar.style.width = pct + '%';
        status.textContent = `${pct}% · ${fmt(off)} / ${fmt(file.size)} · ${fmt(off / Math.max(secs, 0.1))}/s`;
      }
      show(true, root.dataset.msgDone.replace(':path', last.path));
    } catch (e) {
      show(false, root.dataset.msgFail.replace(':msg', e.message));
      drop.classList.remove('pointer-events-none', 'opacity-50');
      busy = false;
    }
  }

  input.addEventListener('change', () => input.files[0] && send(input.files[0]));
  ['dragenter', 'dragover'].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.add('border-brand'); }));
  ['dragleave', 'drop'].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.remove('border-brand'); }));
  drop.addEventListener('drop', (e) => e.dataTransfer.files[0] && send(e.dataTransfer.files[0]));
})();
