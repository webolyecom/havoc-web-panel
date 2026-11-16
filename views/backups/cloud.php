<a href="/backups#targets" class="mb-2 inline-block text-sm text-slate-500 hover:text-brand">&larr; <?= e(t('nav.backups')) ?></a>
<h1 class="h1"><?= e(t('cloud.connect', ['name' => $name])) ?></h1>
<div class="card max-w-2xl space-y-5">
  <ol class="space-y-5 text-sm">
    <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand text-xs font-semibold text-white">1</span>
      <div class="space-y-2"><p><?= e(t('cloud.step1', ['name' => $name])) ?></p>
        <a class="btn inline-flex" href="<?= e($p['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('cloud.open', ['name' => $name])) ?> &nearr;</a></div></li>
    <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand text-xs font-semibold text-white">2</span>
      <div><p><?= e(t('cloud.step2')) ?></p><p class="mt-1 rounded bg-slate-100 px-2 py-1 font-mono text-xs text-slate-600 dark:bg-white/5 dark:text-slate-300">http://127.0.0.1:53682/?state=…&amp;code=…</p></div></li>
    <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand text-xs font-semibold text-white">3</span>
      <form method="post" action="/backups/cloud/finish" class="flex-1 space-y-2"><?= csrf_field() ?>
        <p><?= e(t('cloud.step3')) ?></p>
        <input class="input font-mono text-xs" name="address" required placeholder="http://127.0.0.1:53682/?state=...&amp;code=..." autocomplete="off">
        <div class="flex gap-2"><button class="btn"><?= e(t('cloud.finish')) ?></button>
          <button class="btn-ghost border border-slate-300 dark:border-slate-700" formaction="/backups/cloud/cancel" formnovalidate><?= e(t('common.cancel')) ?></button></div>
      </form></li>
  </ol>
  <p class="border-t border-slate-100 pt-4 text-xs text-slate-500 dark:border-slate-800"><?= e(t('cloud.why', ['name' => $name])) ?><?php if ($p['type'] === 'gdrive'): ?> <?= e(t('cloud.gdrive_scope')) ?><?php endif; ?></p>
</div>
