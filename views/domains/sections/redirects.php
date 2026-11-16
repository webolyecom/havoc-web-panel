<?php $id = (int)$d['id']; ?>
<div class="max-w-4xl">
  <div class="card space-y-3">
    <?php foreach ($redirects as $r): ?>
      <form method="post" action="/domains/<?= $id ?>/redirects/<?= (int)$r['id'] ?>/delete" class="flex items-center justify-between gap-2 text-sm"><?= csrf_field() ?>
        <span class="min-w-0 break-all"><code><?= e($r['source']) ?></code> → <code><?= e($r['target']) ?></code> <span class="text-slate-400"><?= (int)$r['code'] ?></span></span>
        <button class="btn-ghost text-xs text-red-600"><?= e(t('common.delete')) ?></button></form>
    <?php endforeach; ?>
    <?php if (!$redirects): ?><p class="text-sm text-slate-400"><?= e(t('common.empty')) ?></p><?php endif; ?>
    <form method="post" action="/domains/<?= $id ?>/redirects" class="grid gap-2 sm:grid-cols-5"><?= csrf_field() ?>
      <input class="input sm:col-span-2" name="source" placeholder="/old, /dir/* or /" required>
      <input class="input sm:col-span-2" name="target" placeholder="https://example.com/new" required>
      <select class="input" name="code"><?php foreach ([301, 302, 307, 308] as $c): ?><option><?= $c ?></option><?php endforeach; ?></select>
      <button class="btn sm:col-span-5"><?= e(t('common.add')) ?></button></form>
    <p class="text-xs text-slate-500"><?= e(t('domains.redirect_hint')) ?></p>
  </div>
</div>
