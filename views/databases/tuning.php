<?php
$groups = [
    'tune.g_memory' => ['innodb_buffer_pool_size', 'innodb_log_file_size', 'tmp_table_size', 'max_heap_table_size'],
    'tune.g_conn' => ['max_connections', 'thread_cache_size', 'wait_timeout', 'max_allowed_packet'],
    'tune.g_tables' => ['table_open_cache', 'table_definition_cache'],
    'tune.g_innodb' => ['innodb_flush_log_at_trx_commit', 'innodb_io_capacity'],
    'tune.g_slow' => ['slow_query_log', 'long_query_time'],
];
$cur = $st['current'] ?? [];
$file = $st['file'] ?? [];
$fmtCur = function (string $k) use ($cur, $keys) {
    if (!array_key_exists($k, $cur)) return '—';
    $v = $cur[$k];
    return $keys[$k][0] === 'mb' ? $v . ' MB' : ($keys[$k][0] === 'bool' ? ($v ? 'ON' : 'OFF') : (string)$v);
};
$fileVal = function (string $k) use ($file, $keys) {
    if (!isset($file[$k])) return '';
    $v = $file[$k];
    return $keys[$k][0] === 'mb' ? (string)(int)$v : $v;
};
?>
<h1 class="h1"><?= e(t('nav.db_tuning')) ?></h1>
<div class="card mb-6 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
  <span><?= e(t('tune.ram')) ?>: <b><?= e(bytes((int)($st['ram_mb'] ?? 0) * 1048576)) ?></b></span>
  <?php if (!empty($st['version'])): ?><span>MariaDB <b><?= e($st['version']) ?></b></span><span><?= e(t('mon.uptime')) ?>: <b><?= e(gmdate('z\d H\h i\m', (int)$st['uptime'])) ?></b></span><?php endif; ?>
  <?php if (!empty($st['error'])): ?><span class="text-red-600"><?= e($st['error']) ?></span><?php endif; ?>
</div>

<form method="post" action="/databases/tuning" class="space-y-6" id="tune-form" data-confirm="<?= e(t('tune.confirm')) ?>"><?= csrf_field() ?>
  <div class="card space-y-3">
    <h2 class="h2"><?= e(t('tune.presets')) ?></h2>
    <p class="text-sm text-slate-500"><?= e(t('tune.presets_hint')) ?></p>
    <div class="flex flex-wrap gap-2">
      <?php foreach (($st['presets'] ?? []) as $name => $vals): ?>
        <button type="button" class="btn-ghost border border-slate-300 dark:border-slate-700" data-fill-form="tune-form" data-values="<?= e(json_encode($vals)) ?>"><?= e(t('tune.p_' . $name)) ?></button>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="grid gap-6 lg:grid-cols-2">
  <?php foreach ($groups as $gl => $names): ?>
    <div class="card space-y-3">
      <h2 class="h2"><?= e(t($gl)) ?></h2>
      <?php foreach ($names as $k): $def = $keys[$k]; ?>
        <div class="grid grid-cols-5 items-center gap-3">
          <label class="col-span-3 text-sm" for="f-<?= e($k) ?>"><span class="font-mono text-xs"><?= e($k) ?></span><span class="block text-xs text-slate-500"><?= e(t('tune.h_' . $k)) ?></span></label>
          <div class="col-span-2">
            <?php if ($def[0] === 'bool'): ?>
              <label class="flex items-center gap-2 text-sm"><input type="checkbox" id="f-<?= e($k) ?>" name="<?= e($k) ?>" value="1" <?= ($file[$k] ?? ($cur[$k] ?? false ? 'ON' : 'OFF')) === 'ON' ? 'checked' : '' ?>> ON</label>
            <?php elseif ($def[0] === 'enum'): ?>
              <select class="input" id="f-<?= e($k) ?>" name="<?= e($k) ?>"><option value=""><?= e(t('phpini.default')) ?></option><?php foreach ($def[1] as $o): ?><option <?= $fileVal($k) === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select>
            <?php else: ?>
              <div class="flex items-center gap-1"><input class="input" id="f-<?= e($k) ?>" name="<?= e($k) ?>" inputmode="decimal" value="<?= e($fileVal($k)) ?>" placeholder="<?= e(t('phpini.default')) ?>"><?php if ($def[0] === 'mb'): ?><span class="text-xs text-slate-500">MB</span><?php endif; ?></div>
            <?php endif; ?>
            <span class="text-xs text-slate-400"><?= e(t('tune.now')) ?>: <?= e($fmtCur($k)) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  </div>
  <div class="flex flex-wrap items-center justify-between gap-3">
    <p class="text-xs text-slate-500"><?= e(t('tune.hint')) ?></p>
    <button class="btn"><?= e(t('tune.apply')) ?></button>
  </div>
</form>
