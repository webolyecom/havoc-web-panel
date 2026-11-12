<h1 class="h1"><?= e(t('nav.packages')) ?></h1>
<form method="post" action="/packages" class="card mb-6 grid gap-3 sm:grid-cols-5"><?= csrf_field() ?>
  <div class="sm:col-span-5"><label class="label"><?= e(t('common.name')) ?></label><input class="input" name="name" required maxlength="64"></div>
  <?php $def = ['disk_mb' => 5120, 'bandwidth_mb' => 102400, 'inodes' => 250000, 'cpu_percent' => 100, 'ram_mb' => 1024, 'max_domains' => 5, 'max_databases' => 5, 'max_ftp' => 5, 'max_cron' => 10];
  foreach ($fields as $f): ?><div><label class="label"><?= e(t('pkg.' . $f)) ?></label><input class="input" type="number" name="<?= e($f) ?>" value="<?= (int)$def[$f] ?>" min="0"></div><?php endforeach; ?>
  <div class="flex items-end"><button class="btn w-full"><?= e(t('common.add')) ?></button></div>
</form>
<div class="card overflow-x-auto p-0"><table class="table">
  <thead><tr><th><?= e(t('common.name')) ?></th><?php foreach ($fields as $f): ?><th><?= e(t('pkg.' . $f)) ?></th><?php endforeach; ?><th><?= e(t('nav.users')) ?></th><th></th></tr></thead>
  <tbody><?php foreach ($packages as $p): ?>
    <tr><td class="font-medium"><?= e($p['name']) ?></td><?php foreach ($fields as $f): ?><td><?= (int)$p[$f] ?></td><?php endforeach; ?><td><?= (int)$p['used'] ?></td>
      <td class="text-right"><form method="post" action="/packages/<?= (int)$p['id'] ?>/delete" data-confirm="<?= e(t('common.confirm')) ?>"><?= csrf_field() ?><button class="btn-ghost text-xs text-red-600"><?= e(t('common.delete')) ?></button></form></td></tr>
  <?php endforeach; ?>
  <?php if (!$packages): ?><tr><td colspan="12" class="py-8 text-center text-slate-400"><?= e(t('common.empty')) ?></td></tr><?php endif; ?></tbody></table></div>
