<?php
$labels = ['access' => t('logs.access'), 'error' => t('logs.error'), 'php' => t('logs.php')];
$cell = function (array $x, string $value, string $href) {
    if (!$x['exists'] && !$x['rotated']) {
        return '<span class="text-slate-400">—</span>';
    }
    return '<label class="flex items-center gap-2"><input type="checkbox" name="sel[]" value="' . e($value) . '" form="logs-clear">'
        . '<a class="text-brand hover:underline" href="' . e($href) . '">' . e(bytes($x['size'])) . '</a>'
        . ($x['rotated'] ? '<span class="text-xs text-slate-400">+' . e(bytes($x['rotated_size'])) . '</span>' : '') . '</label>';
};
?>
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
  <h1 class="h1 mb-0"><?= e(t('nav.logs')) ?></h1>
  <div class="flex flex-wrap gap-2">
    <a class="btn-ghost border border-slate-300 dark:border-slate-700" href="/logs/recent?type=error"><?= e(t('logs.recent_errors')) ?></a>
    <a class="btn-ghost border border-slate-300 dark:border-slate-700" href="/logs/recent?type=access"><?= e(t('logs.recent_access')) ?></a>
  </div>
</div>

<form method="post" action="/logs/clear" id="logs-clear" class="card mb-4 flex flex-wrap items-center gap-4 text-sm" data-confirm="<?= e(t('logs.clear_confirm')) ?>"><?= csrf_field() ?>
  <span class="text-slate-500"><?= e(t('logs.total', ['size' => bytes($total)])) ?></span>
  <label class="flex items-center gap-2"><input type="checkbox" name="rotated" value="1" checked> <?= e(t('logs.with_rotated')) ?></label>
  <button class="btn-danger" name="mode" value="sel"><?= e(t('logs.clear_selected')) ?></button>
  <span class="flex flex-wrap items-center gap-3 border-l border-slate-200 pl-4 dark:border-slate-700">
    <?php foreach ($labels as $k => $l): ?><label class="flex items-center gap-1"><input type="checkbox" name="types[]" value="<?= $k ?>" checked> <?= e($l) ?></label><?php endforeach; ?>
    <button class="btn-danger" name="all" value="1"><?= e(t('logs.clear_all')) ?></button>
  </span>
</form>

<div class="card overflow-x-auto p-0"><table class="table">
  <thead><tr><th><?= e(t('domains.name')) ?></th><th><?= e(t('domains.owner')) ?></th><?php foreach ($labels as $l): ?><th><?= e($l) ?></th><?php endforeach; ?><th><?= e(t('logs.writing')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($sites as $s): $x = $info[$s['name']] ?? null; if (!$x) continue; ?>
    <tr><td class="font-medium"><?= e($s['name']) ?></td><td class="text-slate-500"><?= e($s['username']) ?></td>
      <?php foreach (array_keys($labels) as $k): ?><td><?= $cell($x[$k], $s['id'] . ':' . $k, '/logs/view?domain_id=' . (int)$s['id'] . '&type=' . $k) ?></td><?php endforeach; ?>
      <td><span class="badge <?= $s['logs_enabled'] ? 'badge-ok' : 'badge-warn' ?>"><?= e(t($s['logs_enabled'] ? 'logs.on' : 'logs.off')) ?></span></td>
      <td class="text-right"><a class="text-sm text-brand hover:underline" href="/logs/view?domain_id=<?= (int)$s['id'] ?>&amp;type=access"><?= e(t('logs.open')) ?></a></td></tr>
  <?php endforeach; ?>
  <?php if (!$sites): ?><tr><td colspan="7" class="py-8 text-center text-slate-400"><?= e(t('common.empty')) ?></td></tr><?php endif; ?>
  </tbody></table></div>
<p class="mt-2 text-xs text-slate-500"><?= e(t('logs.hint')) ?></p>

<?php if ($admin && $system): ?>
<h2 class="h2 mb-3 mt-8"><?= e(t('logs.server')) ?></h2>
<div class="card overflow-x-auto p-0"><table class="table">
  <thead><tr><th><?= e(t('common.name')) ?></th><th><?= e(t('logs.file')) ?></th><th><?= e(t('db.size')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($system as $k => $x): ?>
    <tr><td class="font-medium"><?= e(t('logs.sys_' . str_replace('-', '_', $k))) ?></td><td class="font-mono text-xs text-slate-500"><?= e($x['path']) ?></td>
      <td><?= $cell($x, 'system:' . $k, '/logs/view?system=' . $k) ?></td>
      <td class="text-right"><?php if ($x['exists']): ?><a class="text-sm text-brand hover:underline" href="/logs/view?system=<?= e($k) ?>"><?= e(t('logs.open')) ?></a><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>
