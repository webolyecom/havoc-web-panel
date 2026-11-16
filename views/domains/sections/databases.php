<?php $id = (int)$d['id']; ?>
<div class="max-w-4xl">
  <div class="card space-y-3" id="databases">
    <div class="flex items-center justify-between gap-2"><h2 class="h2 mb-0"><?= e(t('nav.databases')) ?></h2><a class="text-sm text-brand hover:underline" href="/databases?site=<?= $id ?>"><?= e(t('db.manage')) ?> &rarr;</a></div>
    <?php foreach ($dbs as $x): $i = $dbInfo[$x['name']] ?? ['tables' => 0, 'size' => 0]; ?>
      <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm dark:bg-white/[0.03]">
        <span><span class="font-mono font-medium"><?= e($x['name']) ?></span> <span class="text-xs text-slate-500"><?= (int)$i['tables'] ?> <?= e(t('db.tables_short')) ?> · <?= e(bytes((int)$i['size'])) ?></span></span>
        <span class="flex gap-1">
          <?php if ($pmaOn): ?><form method="post" action="/databases/<?= (int)$x['id'] ?>/pma" target="_blank"><?= csrf_field() ?><button class="btn py-1 text-xs" title="<?= e(t('pma.sso_hint')) ?>">phpMyAdmin ↗</button></form><?php endif; ?>
          <form method="post" action="/databases/<?= (int)$x['id'] ?>/site"><?= csrf_field() ?><input type="hidden" name="from_domain" value="<?= $id ?>"><input type="hidden" name="domain_id" value=""><button class="btn-ghost py-1 text-xs" title="<?= e(t('db.unassign_hint')) ?>"><?= e(t('db.unassign')) ?></button></form>
        </span></div>
    <?php endforeach; ?>
    <?php if (!$dbs): ?><p class="text-sm text-slate-400"><?= e(t('db.none_for_site')) ?></p><?php endif; ?>
    <form method="post" action="/databases" class="flex flex-wrap items-end gap-2 border-t border-slate-100 pt-3 dark:border-slate-800"><?= csrf_field() ?>
      <input type="hidden" name="domain_id" value="<?= $id ?>"><input type="hidden" name="from_domain" value="<?= $id ?>">
      <div class="flex-1"><label class="label"><?= e(t('db.new_for_site')) ?></label><div class="flex items-center gap-1"><span class="font-mono text-sm text-slate-400">hv<?= (int)$d['user_id'] ?>_</span><input class="input" name="name" required pattern="[a-z0-9_]{1,16}" placeholder="shop"></div></div>
      <button class="btn"><?= e(t('common.add')) ?></button></form>
    <?php if ($freeDbs): ?>
    <form method="post" action="" class="flex flex-wrap items-end gap-2" data-assign-form><?= csrf_field() ?><input type="hidden" name="from_domain" value="<?= $id ?>"><input type="hidden" name="domain_id" value="<?= $id ?>">
      <div class="flex-1"><label class="label"><?= e(t('db.assign_existing')) ?></label><select class="input" data-assign-select>
        <?php foreach ($freeDbs as $f): ?><option value="<?= (int)$f['id'] ?>"><?= e($f['name']) ?></option><?php endforeach; ?></select></div>
      <button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('db.assign')) ?></button></form>
    <?php endif; ?>
  </div>
</div>
