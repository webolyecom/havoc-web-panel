<?php $types = ['access' => t('logs.access'), 'error' => t('logs.error'), 'php' => t('logs.php')]; ?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
  <h1 class="h1 mb-0 truncate"><?= e(t('nav.logs')) ?> · <?= e($d ? $d['name'] : t('logs.sys_' . str_replace('-', '_', $ref['system']))) ?></h1>
  <a class="text-sm text-brand hover:underline" href="/logs">← <?= e(t('logs.all_logs')) ?></a>
</div>
<div class="card mb-3 flex flex-wrap items-center gap-2 p-3">
  <?php if ($d): ?>
    <select class="input w-auto py-1.5" id="log-site" aria-label="<?= e(t('domains.name')) ?>">
      <?php foreach ($sites as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === (int)$d['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
    </select>
    <div class="inline-flex overflow-hidden rounded-lg border border-slate-300 dark:border-slate-700">
      <?php foreach ($types as $k => $l): ?><a class="px-3 py-1.5 text-sm <?= $ref['type'] === $k ? 'bg-brand text-white' : 'hover:bg-slate-100 dark:hover:bg-slate-800' ?>" href="/logs/view?domain_id=<?= (int)$d['id'] ?>&amp;type=<?= $k ?>"><?= e($l) ?></a><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <input type="search" class="input w-48 py-1.5" id="log-filter" placeholder="<?= e(t('logs.filter')) ?>" autocomplete="off">
  <select class="input w-auto py-1.5" id="log-lines"><?php foreach ([100, 300, 1000, 2000] as $n): ?><option value="<?= $n ?>" <?= $n === 300 ? 'selected' : '' ?>><?= e(t('logs.last_lines', ['n' => $n])) ?></option><?php endforeach; ?></select>
  <label class="flex items-center gap-1 text-sm"><input type="checkbox" id="log-wrap" checked> <?= e(t('logs.wrap')) ?></label>
  <span class="ml-auto flex flex-wrap gap-2">
    <button type="button" class="btn-ghost border border-slate-300 dark:border-slate-700" id="log-toggle" data-pause="<?= e(t('logs.pause')) ?>" data-resume="<?= e(t('logs.resume')) ?>"><?= e(t('logs.pause')) ?></button>
    <a class="btn-ghost border border-slate-300 dark:border-slate-700" href="/logs/download?<?= e($query) ?>"><?= e(t('fm.download')) ?></a>
    <form method="post" action="/logs/clear" data-confirm="<?= e(t('logs.clear_confirm')) ?>"><?= csrf_field() ?>
      <input type="hidden" name="sel[]" value="<?= e($d ? $d['id'] . ':' . $ref['type'] : 'system:' . $ref['system']) ?>"><input type="hidden" name="back" value="/logs/view?<?= e($query) ?>">
      <button class="btn-danger"><?= e(t('logs.clear')) ?></button></form>
  </span>
</div>
<?php if ($d && !$d['logs_enabled']): ?><div class="mb-3 rounded-lg bg-amber-100 p-3 text-sm text-amber-900"><?= e(t('logs.disabled_hint')) ?></div><?php endif; ?>
<div class="mb-1 flex justify-between text-xs text-slate-500"><span id="log-status"></span><span id="log-count"></span></div>
<pre id="log-view" data-url="/logs/poll?<?= e($query) ?>" class="card h-[65vh] overflow-auto whitespace-pre-wrap break-all bg-slate-900 p-4 font-mono text-xs leading-5 text-slate-200 dark:bg-black"></pre>
<script src="<?= e(asset('logs.js')) ?>" defer></script>
