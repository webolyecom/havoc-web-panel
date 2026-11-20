<?php $id = (int)$d['id']; ?>
<div class="grid gap-6 lg:grid-cols-[minmax(0,22rem)_1fr]">
  <form method="post" action="/domains/<?= $id ?>/php" class="card space-y-4">
    <?= csrf_field() ?><h2 class="h2">PHP</h2>
    <div><label class="label"><?= e(t('domains.php_version')) ?></label><select class="input" name="php"><?php foreach ($php as $v): ?><option <?= $v === $d['php_version'] ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <label class="flex items-center gap-2"><input type="checkbox" name="opcache" value="1" <?= $d['opcache_enabled'] ? 'checked' : '' ?>> OPcache</label>
    <div><label class="label">OPcache MB</label><input class="input" type="number" min="32" max="1024" name="opcache_memory" value="<?= (int)$d['opcache_memory'] ?>"></div>
    <button class="btn"><?= e(t('common.save')) ?></button>
  </form>
  <form method="post" action="/domains/<?= $id ?>/php" class="card space-y-3">
    <?= csrf_field() ?><input type="hidden" name="ext_form" value="1">
    <input type="hidden" name="php" value="<?= e($d['php_version']) ?>"><input type="hidden" name="opcache" value="<?= $d['opcache_enabled'] ? 1 : '' ?>"><input type="hidden" name="opcache_memory" value="<?= (int)$d['opcache_memory'] ?>">
    <h2 class="h2"><?= e(t('domains.extensions')) ?></h2>
    <div class="grid max-h-[28rem] grid-cols-2 gap-1 overflow-y-auto text-sm sm:grid-cols-3 lg:grid-cols-4">
      <?php foreach ($ext['available'] as $x): ?><label class="flex items-center gap-2"><input type="checkbox" name="ext[]" value="<?= e($x) ?>" <?= in_array($x, $enabled, true) ? 'checked' : '' ?>> <?= e($x) ?></label><?php endforeach; ?>
    </div>
    <button class="btn"><?= e(t('common.save')) ?></button>
  </form>
</div>

<?php
$lim = $eff['limits'];
$ramTxt = (int)$lim['ram_mb'] === 0 ? t('pool.unlimited') : $lim['ram_mb'] . ' MB';
$cpuTxt = (int)$lim['cpu_percent'] === 0 ? t('pool.unlimited') : $lim['cpu_percent'] . ' %';
$mode = $pool['pm'] ?? 'ondemand';
$children = $pool['max_children'] ?? $autoChildren;
$memLimit = (string)(json_decode((string)($d['php_ini'] ?? ''), true)['memory_limit'] ?? '128M');
$memMb = preg_match('/^(\d+)\s*([MG]?)$/i', $memLimit, $mm) ? (int)$mm[1] * (strtoupper($mm[2]) === 'G' ? 1024 : 1) : 128;
$worst = $children * $memMb;
$dis = $canPool ? '' : ' disabled';
$num = static fn(string $k, int $min, int $max, string $ph, string $unit = '') => '<div><label class="label" for="pl-' . $k . '">' . e(t('pool.' . $k)) . '</label><div class="flex items-center gap-2">'
    . '<input class="input" type="number" id="pl-' . $k . '" name="' . $k . '" min="' . $min . '" max="' . $max . '" value="' . e((string)($pool[$k] ?? '')) . '" placeholder="' . e($ph) . '"' . $dis . '>'
    . ($unit !== '' ? '<span class="shrink-0 text-sm text-slate-500">' . e($unit) . '</span>' : '') . '</div></div>';
?>
<form method="post" action="/domains/<?= $id ?>/php-pool" class="card mt-6 space-y-5" id="pool"><?= csrf_field() ?>
  <div class="flex flex-wrap items-start justify-between gap-3">
    <div><h2 class="h2 mb-0"><?= e(t('pool.title')) ?></h2>
      <p class="mt-1 max-w-3xl text-sm text-slate-500"><?= e(t('pool.intro')) ?></p></div>
    <div class="flex flex-wrap gap-2 text-xs">
      <span class="badge">RAM <?= e($ramTxt) ?></span><span class="badge">CPU <?= e($cpuTxt) ?></span>
      <span class="badge"><?= e(t('pool.workers_badge', ['n' => $children, 'mode' => $mode])) ?></span>
    </div>
  </div>
  <?php if (!$canPool): ?><p class="rounded-lg bg-slate-50 p-3 text-sm text-slate-600 dark:bg-white/[0.03] dark:text-slate-300"><?= e(t('pool.admin_only')) ?></p><?php endif; ?>

  <div class="grid gap-5 lg:grid-cols-2">
    <div class="space-y-1">
      <label class="label" for="pl-ram_mb"><?= e(t('pool.ram_mb')) ?></label>
      <div class="flex flex-wrap items-center gap-3">
        <div class="flex items-center gap-2"><input class="input w-36" type="number" id="pl-ram_mb" name="ram_mb" min="128" max="1048576" value="<?= e((string)($pool['ram_mb'] ?: '')) ?>" placeholder="<?= (int)$pkg['ram_mb'] ?>"<?= $dis ?>><span class="text-sm text-slate-500">MB</span></div>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="ram_unlimited" value="1"<?= $pool['ram_mb'] === 0 ? ' checked' : '' ?><?= $dis ?>> <?= e(t('pool.unlimited')) ?></label>
      </div>
      <p class="text-xs text-slate-500"><?= e(t('pool.h_ram', ['pkg' => $pkg['package'] ?? t('pool.no_package'), 'mb' => $pkg['ram_mb']])) ?></p>
    </div>
    <div class="space-y-1">
      <label class="label" for="pl-cpu_percent"><?= e(t('pool.cpu_percent')) ?></label>
      <div class="flex flex-wrap items-center gap-3">
        <div class="flex items-center gap-2"><input class="input w-36" type="number" id="pl-cpu_percent" name="cpu_percent" min="10" max="12800" value="<?= e((string)($pool['cpu_percent'] ?: '')) ?>" placeholder="<?= (int)$pkg['cpu_percent'] ?>"<?= $dis ?>><span class="text-sm text-slate-500">%</span></div>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="cpu_unlimited" value="1"<?= $pool['cpu_percent'] === 0 ? ' checked' : '' ?><?= $dis ?>> <?= e(t('pool.unlimited')) ?></label>
      </div>
      <p class="text-xs text-slate-500"><?= e(t('pool.h_cpu', ['pkg' => $pkg['package'] ?? t('pool.no_package'), 'p' => $pkg['cpu_percent']])) ?></p>
    </div>
  </div>

  <div class="grid gap-5 border-t border-slate-100 pt-5 dark:border-slate-800 sm:grid-cols-2 xl:grid-cols-4">
    <div>
      <label class="label" for="pl-pm"><?= e(t('pool.pm')) ?></label>
      <select class="input" id="pl-pm" name="pm" data-pm-select<?= $dis ?>>
        <?php foreach (Havoc\Services\PoolService::MODES as $m): ?><option value="<?= $m ?>"<?= $m === $mode ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?>
      </select>
      <p class="mt-1 text-xs text-slate-500" data-pm-when="ondemand"><?= e(t('pool.h_ondemand')) ?></p>
      <p class="mt-1 text-xs text-slate-500" data-pm-when="dynamic"><?= e(t('pool.h_dynamic')) ?></p>
      <p class="mt-1 text-xs text-slate-500" data-pm-when="static"><?= e(t('pool.h_static')) ?></p>
    </div>
    <?= $num('max_children', 1, 1000, t('pool.auto_n', ['n' => $autoChildren])) ?>
    <div data-pm-when="ondemand"><?= $num('idle_timeout', 1, 3600, '10', t('wst.seconds')) ?></div>
    <?= $num('max_requests', 0, 100000, '500') ?>
    <div data-pm-when="dynamic"><?= $num('start', 1, 1000, t('pool.auto')) ?></div>
    <div data-pm-when="dynamic"><?= $num('min_spare', 1, 1000, t('pool.auto')) ?></div>
    <div data-pm-when="dynamic"><?= $num('max_spare', 1, 1000, t('pool.auto')) ?></div>
  </div>

  <?php if ((int)$lim['ram_mb'] > 0 && $worst > (int)$lim['ram_mb']): ?>
    <p class="rounded-lg border border-amber-300/60 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"><?= e(t('pool.w_memory', ['n' => $children, 'm' => $memLimit, 'total' => $worst, 'ram' => $lim['ram_mb']])) ?></p>
  <?php endif; ?>
  <p class="text-xs text-slate-500"><?= e(t('pool.hint')) ?></p>
  <?php if ($canPool): ?><div class="flex flex-wrap items-center gap-3"><button class="btn"><?= e(t('common.save')) ?></button><span class="text-xs text-slate-500"><?= e(t('pool.save_hint')) ?></span></div><?php endif; ?>
</form>
