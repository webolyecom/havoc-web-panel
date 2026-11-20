<?php
$m = $r['mem'];
$tot = max(1, (int)$m['total']);
$svcMem = array_sum(array_column($services, 'memory')) + $phpTotal;
$pct = static fn($v, $of) => $of > 0 ? min(100, round(100 * $v / $of, 1)) : 0;
$sz = static fn($b) => bytes((int)$b);
$bar = static fn(float $p, string $cls) => '<div class="h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-full ' . $cls . '" data-pct="' . $p . '"></div></div>';
$tone = static fn(float $p) => $p >= 90 ? 'bg-rose-500' : ($p >= 75 ? 'bg-amber-500' : 'bg-brand');
?>
<div class="mb-6 flex flex-wrap items-start justify-between gap-3">
  <div><h1 class="h1 mb-0"><?= e(t('res.title')) ?></h1><p class="mt-1 max-w-3xl text-sm text-slate-500"><?= e(t('res.intro')) ?></p></div>
  <a class="btn-ghost border border-slate-300 dark:border-slate-700" href="/server/resources"><?= e(t('res.refresh')) ?></a>
</div>

<div class="mb-6 grid gap-4 lg:grid-cols-3">
  <div class="card lg:col-span-2">
    <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
      <h2 class="h2 mb-0"><?= e(t('res.memory')) ?></h2>
      <span class="text-sm text-slate-500"><?= e(t('res.mem_line', ['avail' => $sz($m['available']), 'total' => $sz($m['total'])])) ?></span>
    </div>
    <div class="flex h-4 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
      <div class="h-full bg-brand" data-pct="<?= $pct($m['used'], $tot) ?>" title="<?= e(t('res.k_used')) ?>"></div>
      <div class="h-full bg-sky-300 dark:bg-sky-700" data-pct="<?= $pct(min($m['cache'], $m['available']), $tot) ?>" title="<?= e(t('res.k_cache')) ?>"></div>
    </div>
    <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-4">
      <div><dt class="flex items-center gap-1.5 text-xs text-slate-500"><span class="inline-block h-2 w-2 rounded-full bg-brand"></span><?= e(t('res.k_used')) ?></dt><dd class="font-semibold"><?= e($sz($m['used'])) ?> <span class="text-xs font-normal text-slate-500">(<?= $pct($m['used'], $tot) ?> %)</span></dd></div>
      <div><dt class="flex items-center gap-1.5 text-xs text-slate-500"><span class="inline-block h-2 w-2 rounded-full bg-sky-300 dark:bg-sky-700"></span><?= e(t('res.k_cache')) ?></dt><dd class="font-semibold"><?= e($sz($m['cache'])) ?></dd></div>
      <div><dt class="text-xs text-slate-500"><?= e(t('res.k_free')) ?></dt><dd class="font-semibold"><?= e($sz($m['free'])) ?></dd></div>
      <div><dt class="text-xs text-slate-500"><?= e(t('res.k_swap')) ?></dt><dd class="font-semibold"><?= $m['swap_total'] ? e($sz($m['swap_used']) . ' / ' . $sz($m['swap_total'])) : e(t('res.no_swap')) ?></dd></div>
    </dl>
    <p class="mt-3 text-xs text-slate-500"><?= e(t('res.mem_hint')) ?></p>
    <?php if (!$m['swap_total']): ?><p class="mt-2 text-xs text-amber-700 dark:text-amber-300"><?= e(t('res.swap_hint')) ?></p><?php endif; ?>
  </div>
  <div class="card">
    <h2 class="h2"><?= e(t('res.cpu')) ?></h2>
    <p class="text-2xl font-bold"><?= e(implode(' · ', array_map(static fn($l) => number_format($l, 2), $r['load']))) ?></p>
    <p class="text-xs text-slate-500"><?= e(t('res.load_line', ['n' => $r['cpus']])) ?></p>
    <p class="mt-3 text-xs text-slate-500"><?= e(t('res.cpu_hint', ['n' => $r['cpus'], 'p' => $r['cpus'] * 100])) ?></p>
  </div>
</div>

<?php if (!$r['cgroup']): ?>
  <div class="card mb-6"><h2 class="h2"><?= e(t('res.programs')) ?></h2><p class="mb-3 text-xs text-slate-500"><?= e(t('res.no_cgroup')) ?></p>
    <table class="table text-sm"><tbody><?php foreach ($r['procs'] as $n => $p): ?><tr><td class="font-mono"><?= e($n) ?></td><td class="text-right"><?= e($sz($p['memory'])) ?></td><td class="text-right text-slate-500"><?= (int)$p['procs'] ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php else: ?>
<div class="card mb-6 p-0">
  <div class="flex flex-wrap items-baseline justify-between gap-2 p-4 pb-0"><h2 class="h2 mb-0"><?= e(t('res.services')) ?></h2><span class="text-xs text-slate-500"><?= e(t('res.services_hint')) ?></span></div>
  <div class="overflow-x-auto"><table class="table text-sm">
    <thead><tr><th><?= e(t('res.service')) ?></th><th class="w-1/3"><?= e(t('res.k_memory')) ?></th><th class="text-right"><?= e(t('res.k_cache_short')) ?></th><th class="text-right">CPU</th><th class="text-right"><?= e(t('res.k_procs')) ?></th><th></th></tr></thead>
    <tbody>
    <tr class="bg-slate-50/60 dark:bg-white/[0.02]"><td class="font-medium"><?= e(t('res.php_sites', ['n' => count($sites)])) ?></td><td><div class="flex items-center gap-3"><span class="w-20 shrink-0 text-right"><?= e($sz($phpTotal)) ?></span><?= $bar($pct($phpTotal, $tot), 'bg-brand') ?></div></td><td></td><td></td><td></td><td class="text-right"><a class="link text-xs" href="#sites"><?= e(t('res.see_below')) ?></a></td></tr>
    <?php foreach ($services as $s): ?>
      <tr><td><span class="font-medium"><?= e($s['label']) ?></span><?php if ($s['label'] !== $s['name']): ?> <span class="font-mono text-xs text-slate-400"><?= e($s['name']) ?></span><?php endif; ?></td>
        <td><div class="flex items-center gap-3"><span class="w-20 shrink-0 text-right"><?= e($sz($s['memory'])) ?></span><?= $bar($pct($s['memory'], $tot), 'bg-brand') ?></div></td>
        <td class="text-right text-slate-500"><?= e($sz($s['cache'])) ?></td>
        <td class="text-right"><?= $s['cpu'] === null ? '–' : e($s['cpu'] . ' %') ?></td>
        <td class="text-right text-slate-500"><?= (int)$s['procs'] ?></td>
        <td class="text-right"><?php if ($s['link']): ?><a class="link text-xs" href="<?= e($s['link']) ?>"><?= e(t('res.settings')) ?></a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<div class="card p-0" id="sites">
  <div class="p-4 pb-2"><h2 class="h2 mb-1"><?= e(t('res.sites')) ?></h2><p class="text-xs text-slate-500"><?= e(t('res.sites_hint')) ?></p></div>
  <div class="overflow-x-auto"><table class="table text-sm">
    <thead><tr class="whitespace-nowrap"><th><?= e(t('res.site')) ?></th><th class="min-w-[10rem]"><?= e(t('res.ram_now')) ?></th><th class="text-right"><?= e(t('res.cpu_now')) ?></th><th class="text-right"><?= e(t('res.workers_now')) ?></th>
      <th><?= e(t('res.c_ram')) ?></th><th><?= e(t('res.c_cpu')) ?></th><th><?= e(t('res.c_workers')) ?></th><th>pm</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($sites as $s): $d = $s['d']; $id = (int)$d['id']; $u = $s['unit']; $lim = $s['eff']['limits']; $ramB = (int)$lim['ram_mb'] * 1048576;
      $memNow = (int)($u['memory'] ?? 0); $p = $ramB > 0 ? $pct($memNow, $ramB) : 0; $f = 'rf-' . $id; ?>
      <tr id="site-<?= $id ?>">
        <td><a class="link font-medium" href="/domains/<?= $id ?>/php#pool"><?= e($d['name']) ?></a><div class="text-xs text-slate-500"><?= e($d['username']) ?><?= $s['pkg']['package'] ? ' · ' . e($s['pkg']['package']) : '' ?></div></td>
        <td><div class="text-xs"><?= e($sz($memNow)) ?> / <?= (int)$lim['ram_mb'] === 0 ? e(t('pool.unlimited')) : e($sz($ramB)) ?></div><?php if ($ramB > 0): ?><?= $bar($p, $tone($p)) ?><?php endif; ?></td>
        <td class="whitespace-nowrap text-right"><?= ($u['cpu'] ?? null) === null ? '–' : e($u['cpu'] . ' %') ?><div class="text-xs text-slate-500">/ <?= (int)$lim['cpu_percent'] === 0 ? e(t('pool.unlimited')) : (int)$lim['cpu_percent'] . ' %' ?></div></td>
        <td class="whitespace-nowrap text-right"><?= max(0, (int)($u['procs'] ?? 0) - 1) ?> / <?= (int)$s['children'] ?></td>
        <td><div class="flex items-center gap-1.5"><input form="<?= $f ?>" class="input w-20 px-2 py-1" type="number" name="ram_mb" min="128" max="1048576" value="<?= e((string)($s['pool']['ram_mb'] ?: '')) ?>" placeholder="<?= (int)$s['pkg']['ram_mb'] ?>">
          <label class="flex items-center gap-1 text-xs" title="<?= e(t('pool.unlimited')) ?>"><input form="<?= $f ?>" type="checkbox" name="ram_unlimited" value="1"<?= $s['pool']['ram_mb'] === 0 ? ' checked' : '' ?>>∞</label></div></td>
        <td><div class="flex items-center gap-1.5"><input form="<?= $f ?>" class="input w-16 px-2 py-1" type="number" name="cpu_percent" min="10" max="12800" value="<?= e((string)($s['pool']['cpu_percent'] ?: '')) ?>" placeholder="<?= (int)$s['pkg']['cpu_percent'] ?>">
          <label class="flex items-center gap-1 text-xs" title="<?= e(t('pool.unlimited')) ?>"><input form="<?= $f ?>" type="checkbox" name="cpu_unlimited" value="1"<?= $s['pool']['cpu_percent'] === 0 ? ' checked' : '' ?>>∞</label></div></td>
        <td><input form="<?= $f ?>" class="input w-20 px-2 py-1" type="number" name="max_children" min="1" max="1000" value="<?= e((string)($s['pool']['max_children'] ?? '')) ?>" placeholder="<?= e(t('pool.auto_n', ['n' => Havoc\Services\PoolService::autoChildren((int)$lim['ram_mb'])])) ?>"></td>
        <td><select form="<?= $f ?>" class="input w-28 px-2 py-1" name="pm"><?php foreach (Havoc\Services\PoolService::MODES as $mo): ?><option<?= $mo === ($s['pool']['pm'] ?? 'ondemand') ? ' selected' : '' ?>><?= $mo ?></option><?php endforeach; ?></select></td>
        <td class="pr-4"><form id="<?= $f ?>" method="post" action="/server/resources/<?= $id ?>" data-confirm="<?= e(t('res.confirm', ['site' => $d['name']])) ?>"><?= csrf_field() ?><button class="btn px-3 py-1 text-xs"><?= e(t('common.save')) ?></button></form></td>
      </tr>
      <?php if ($ramB > 0 && $p >= 85): ?><tr><td colspan="9" class="pt-0 text-xs text-amber-700 dark:text-amber-300"><?= e(t('res.near_limit', ['site' => $d['name'], 'p' => $p])) ?></td></tr><?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="p-4 pt-2 text-xs text-slate-500"><?= e(t('res.table_hint')) ?></p>
</div>
