<?php $id = (int)$d['id']; ?>
<div class="max-w-2xl">
  <div class="card space-y-3">
    <?php foreach ($aliases as $a): ?>
      <form method="post" action="/domains/<?= (int)$a['id'] ?>/delete" class="flex items-center justify-between text-sm" data-confirm="<?= e(t('common.confirm')) ?>"><?= csrf_field() ?><input type="hidden" name="from_domain" value="<?= $id ?>">
        <span><?= e($a['name']) ?></span><button class="btn-ghost text-xs text-red-600"><?= e(t('common.delete')) ?></button></form>
    <?php endforeach; ?>
    <?php if (!$aliases): ?><p class="text-sm text-slate-400"><?= e(t('common.empty')) ?></p><?php endif; ?>
    <form method="post" action="/domains" class="flex gap-2"><?= csrf_field() ?>
      <input type="hidden" name="from_domain" value="<?= $id ?>"><input type="hidden" name="type" value="alias"><input type="hidden" name="parent" value="<?= $id ?>"><input type="hidden" name="owner" value="<?= (int)$d['user_id'] ?>"><input type="hidden" name="php" value="<?= e($d['php_version']) ?>">
      <input class="input" name="name" placeholder="alias.com" required><button class="btn"><?= e(t('common.add')) ?></button></form>
  </div>
<p class="mt-3 text-xs text-slate-500"><?= e(t('dsec.aliases_hint')) ?></p>
</div>
