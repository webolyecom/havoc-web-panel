<?php
$admin = Havoc\Core\Auth::user()['role'] === 'admin';
$cell = function (?float $r, string $text) {
    if ($r === null) return '<span class="text-slate-400">' . e($text) . ' · ∞</span>';
    $cls = $r >= 1 ? 'badge-warn' : ($r >= 0.8 ? 'badge-warn' : 'badge-ok');
    return '<div>' . e($text) . '</div><span class="badge ' . $cls . '">' . round($r * 100) . '%</span>';
};
?>
<h1 class="h1"><?= e(t('nav.limits')) ?></h1>
<?php if ($admin): ?>
<div class="card mb-6 flex flex-wrap items-center justify-between gap-3">
  <form method="post" action="/limits/settings" class="flex items-center gap-3"><?= csrf_field() ?>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="enforce" value="1" <?= $enforce ? 'checked' : '' ?>> <?= e(t('limits.enforce')) ?></label>
    <button class="btn"><?= e(t('common.save')) ?></button></form>
  <form method="post" action="/limits/scan"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('limits.scan_now')) ?></button></form>
  <p class="w-full text-xs text-slate-500"><?= e(t('limits.hint')) ?> <?= $scanned ? e(t('limits.last_scan')) . ': ' . e($scanned) . ' UTC' : '' ?></p>
</div>
<?php endif; ?>
<div class="card overflow-x-auto p-0"><table class="table">
  <thead><tr><th><?= e(t('auth.username')) ?></th><th><?= e(t('nav.packages')) ?></th><th><?= e(t('limits.disk')) ?></th><th><?= e(t('limits.inodes')) ?></th><th><?= e(t('limits.bandwidth')) ?></th><th><?= e(t('common.status')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="font-medium"><?= e($r['username']) ?></td><td><?= e((string)($r['pkg'] ?? '—')) ?>
        <?php if ($r['pkg'] !== null): ?><div class="text-xs text-slate-400">RAM <?= (int)$r['ram_mb'] ?> MB · CPU <?= (int)$r['cpu_percent'] ?>%</div><?php endif; ?></td>
      <td><?= $cell($r['ratios']['disk'], bytes((int)$r['disk_bytes'] + (int)$r['db_bytes'])) ?></td>
      <td><?= $cell($r['ratios']['inodes'], number_format((int)$r['inodes'])) ?></td>
      <td><?= $cell($r['ratios']['bandwidth'], bytes((int)$r['bw_month'])) ?></td>
      <td><span class="badge <?= $r['state'] === 'ok' ? 'badge-ok' : 'badge-warn' ?>"><?= e(t('limits.st_' . $r['state'])) ?></span></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="py-8 text-center text-slate-400"><?= e(t('limits.no_data')) ?></td></tr><?php endif; ?>
  </tbody></table></div>

<h2 class="h2 mb-3 mt-8"><?= e(t('limits.live')) ?></h2>
<div class="card overflow-x-auto p-0"><table class="table">
  <thead><tr><th><?= e(t('domains.name')) ?></th><th>RAM</th><th><?= e(t('limits.cpu_time')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($domains as $d): $f = $fpm[$d['id']] ?? null; ?>
    <tr><td class="font-medium"><?= e($d['name']) ?><?php if (!empty($d['quota_suspended'])): ?> <span class="badge badge-warn"><?= e(t('limits.suspended')) ?></span><?php endif; ?></td>
      <td><?= $f && $f['mem'] !== null ? e(bytes($f['mem'])) . ($f['mem_max'] ? ' / ' . e(bytes($f['mem_max'])) : '') : '—' ?></td>
      <td><?= $f && $f['cpu_s'] !== null ? e((string)$f['cpu_s']) . ' s' : '—' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$domains): ?><tr><td colspan="3" class="py-6 text-center text-slate-400"><?= e(t('common.empty')) ?></td></tr><?php endif; ?>
  </tbody></table></div>
<p class="mt-2 text-xs text-slate-500"><?= e(t('limits.cgroup_hint')) ?></p>
