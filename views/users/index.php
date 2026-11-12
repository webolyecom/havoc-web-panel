<?php $me = Havoc\Core\Auth::user(); ?>
<h1 class="h1"><?= e(t('nav.users')) ?></h1>
<form method="post" action="/users" class="card mb-6 grid gap-3 sm:grid-cols-6">
  <?= csrf_field() ?>
  <div><label class="label"><?= e(t('auth.username')) ?></label><input class="input" name="username" required pattern="[a-z][a-z0-9_]{2,31}"></div>
  <div class="sm:col-span-2"><label class="label">Email</label><input class="input" type="email" name="email" required></div>
  <div><label class="label"><?= e(t('auth.password')) ?></label><input class="input" type="password" name="password" minlength="10" required autocomplete="new-password"></div>
  <?php if ($me['role'] === 'admin'): ?><div><label class="label"><?= e(t('users.role')) ?></label><select class="input" name="role"><option value="user">User</option><option value="reseller">Reseller</option></select></div><?php endif; ?>
  <div class="flex items-end"><button class="btn w-full"><?= e(t('common.add')) ?></button></div>
</form>
<div class="card overflow-x-auto p-0"><table class="table">
  <thead><tr><th><?= e(t('auth.username')) ?></th><th>Email</th><th><?= e(t('users.role')) ?></th><th><?= e(t('common.status')) ?></th><th><?= e(t('nav.packages')) ?></th><th></th></tr></thead>
  <tbody><?php foreach ($users as $x): ?>
    <tr><td class="font-medium"><?= e($x['username']) ?></td><td><?= e($x['email']) ?></td><td><?= e($x['role']) ?></td>
      <td><span class="badge <?= $x['status'] === 'active' ? 'badge-ok' : 'badge-warn' ?>"><?= e($x['status']) ?></span></td>
      <td><?php if ($packages): ?><form method="post" action="/users/<?= (int)$x['id'] ?>/package" class="flex gap-1"><?= csrf_field() ?><select class="input w-auto py-1 text-xs" name="package_id"><option value="0">—</option><?php foreach ($packages as $pk): ?><option value="<?= (int)$pk['id'] ?>" <?= (int)$x['package_id'] === (int)$pk['id'] ? 'selected' : '' ?>><?= e($pk['name']) ?></option><?php endforeach; ?></select><button class="btn-ghost text-xs">OK</button></form><?php endif; ?></td>
      <td class="text-right"><?php if ((int)$x['id'] !== (int)$me['id']): ?><form method="post" action="/users/<?= (int)$x['id'] ?>/status" class="inline"><?= csrf_field() ?><button class="btn-ghost text-xs"><?= e(t($x['status'] === 'active' ? 'users.suspend' : 'users.activate')) ?></button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
