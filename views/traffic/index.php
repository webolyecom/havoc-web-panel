<?php
$total = array_sum(array_column($series, 'bytes'));
$hits = array_sum(array_column($series, 'hits'));
$sum = fn(string $k) => array_sum(array_column($series, $k));
$W = 700; $H = 210; $pad = 34;
$n = max(1, count($series));
$max = max(1, $series ? max(array_column($series, 'bytes')) : 1);
$bw = ($W - 2 * $pad) / $n;
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
  <h1 class="h1 mb-0"><?= e(t('nav.traffic')) ?></h1>
  <form method="get" action="/traffic" class="flex flex-wrap items-center gap-2">
    <select class="input w-auto" name="domain_id"><?php foreach ($domains as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $d && (int)$x['id'] === (int)$d['id'] ? 'selected' : '' ?>><?= e($x['name']) ?></option><?php endforeach; ?></select>
    <select class="input w-auto" name="days"><?php foreach ([7, 30, 90, 365] as $o): ?><option value="<?= $o ?>" <?= $o === $days ? 'selected' : '' ?>><?= $o ?> <?= e(t('traffic.days')) ?></option><?php endforeach; ?></select>
    <button class="btn"><?= e(t('waf.apply_filter')) ?></button>
    <?php if ($d): ?><a class="btn-ghost border border-slate-300 dark:border-slate-700" href="/traffic/export?domain_id=<?= (int)$d['id'] ?>&amp;days=<?= $days ?>">CSV</a><?php endif; ?>
  </form>
</div>
<?php if (!$d): ?><p class="card text-slate-400"><?= e(t('common.empty')) ?></p><?php else: ?>
<?php if (!$d['logs_enabled']): ?><div class="mb-4 rounded-lg bg-amber-100 p-3 text-sm text-amber-900"><?= e(t('traffic.logs_off')) ?></div><?php endif; ?>
<div class="mb-6 grid gap-4 sm:grid-cols-4">
  <div class="card"><p class="text-sm text-slate-500"><?= e(t('traffic.bandwidth')) ?></p><p class="mt-1 text-2xl font-bold"><?= e(bytes($total)) ?></p></div>
  <div class="card"><p class="text-sm text-slate-500"><?= e(t('traffic.hits')) ?></p><p class="mt-1 text-2xl font-bold"><?= number_format($hits) ?></p></div>
  <div class="card"><p class="text-sm text-slate-500">2xx / 3xx</p><p class="mt-1 text-2xl font-bold"><?= number_format($sum('s2')) ?> / <?= number_format($sum('s3')) ?></p></div>
  <div class="card"><p class="text-sm text-slate-500">4xx / 5xx</p><p class="mt-1 text-2xl font-bold"><?= number_format($sum('s4')) ?> / <?= number_format($sum('s5')) ?></p></div>
</div>
<div class="card mb-6 overflow-x-auto">
  <svg viewBox="0 0 <?= $W ?> <?= $H ?>" class="h-56 w-full min-w-[480px]" role="img" aria-label="<?= e(t('traffic.bandwidth')) ?>">
    <line x1="<?= $pad ?>" y1="<?= $H - $pad ?>" x2="<?= $W - $pad ?>" y2="<?= $H - $pad ?>" class="stroke-slate-300 dark:stroke-slate-700" stroke-width="1"/>
    <text x="<?= $pad ?>" y="14" class="fill-slate-500 text-[11px]"><?= e(bytes($max)) ?></text>
    <?php foreach ($series as $i => $r): $h = ($r['bytes'] / $max) * ($H - 2 * $pad); ?>
      <rect x="<?= round($pad + $i * $bw + 1, 2) ?>" y="<?= round($H - $pad - $h, 2) ?>" width="<?= round(max(1, $bw - 2), 2) ?>" height="<?= round(max($r['bytes'] > 0 ? 1 : 0, $h), 2) ?>" rx="1" class="fill-brand">
        <title><?= e($r['day'] . ' · ' . bytes((int)$r['bytes']) . ' · ' . $r['hits'] . ' ' . t('traffic.hits')) ?></title></rect>
    <?php endforeach; ?>
    <?php if ($series): ?>
      <text x="<?= $pad ?>" y="<?= $H - 10 ?>" class="fill-slate-500 text-[11px]"><?= e($series[0]['day']) ?></text>
      <text x="<?= $W - $pad ?>" y="<?= $H - 10 ?>" text-anchor="end" class="fill-slate-500 text-[11px]"><?= e($series[count($series) - 1]['day']) ?></text>
    <?php endif; ?>
  </svg>
</div>
<div class="card max-h-96 overflow-auto p-0"><table class="table">
  <thead><tr><th><?= e(t('traffic.day')) ?></th><th><?= e(t('traffic.bandwidth')) ?></th><th><?= e(t('traffic.hits')) ?></th><th>2xx</th><th>3xx</th><th>4xx</th><th>5xx</th></tr></thead>
  <tbody>
  <?php foreach (array_reverse($series) as $r): ?>
    <tr><td><?= e($r['day']) ?></td><td><?= e(bytes((int)$r['bytes'])) ?></td><td><?= number_format((int)$r['hits']) ?></td><td><?= (int)$r['s2'] ?></td><td><?= (int)$r['s3'] ?></td><td><?= (int)$r['s4'] ?></td><td><?= (int)$r['s5'] ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
<p class="mt-2 text-xs text-slate-500"><?= e(t('traffic.hint')) ?></p>
<?php endif; ?>
