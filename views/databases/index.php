<?php $me = Havoc\Core\Auth::user(); ?>
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
  <h1 class="h1 mb-0"><?= e(t('nav.databases')) ?></h1>
  <?php if (!empty($pma['installed'])): ?><a class="btn" href="/phpmyadmin/" target="_blank" rel="noopener"><?= e(t('pma.open')) ?> ↗</a><?php endif; ?>
</div>
<?php if ($new): ?>
<div class="mb-4 rounded-lg bg-amber-100 p-4 text-sm text-amber-900"><?= e(t('db.once')) ?><br>
  <b><?= e(t('db.name')) ?>:</b> <code><?= e($new['name']) ?></code> &nbsp; <b><?= e(t('auth.password')) ?>:</b> <code class="break-all"><?= e($new['password']) ?></code></div>
<?php endif; ?>
<form method="post" action="/databases" class="card mb-6 grid gap-3 sm:grid-cols-5"><?= csrf_field() ?>
  <div class="sm:col-span-2"><label class="label"><?= e(t('db.name')) ?></label><input class="input" name="name" required pattern="[a-z0-9_]{1,16}" placeholder="shop"></div>
  <div><label class="label"><?= e(t('auth.password')) ?></label><input class="input" name="password" placeholder="<?= e(t('db.auto')) ?>" autocomplete="new-password"></div>
  <?php if (count($owners) > 1): ?><div><label class="label"><?= e(t('domains.owner')) ?></label><select class="input" name="owner"><?php foreach ($owners as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['username']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <div class="flex items-end"><button class="btn w-full"><?= e(t('common.add')) ?></button></div>
</form>
<div class="card overflow-x-auto p-0"><table class="table">
  <thead><tr><th><?= e(t('db.name')) ?></th><th><?= e(t('domains.owner')) ?></th><th><?= e(t('db.tables')) ?></th><th><?= e(t('db.size')) ?></th><th><?= e(t('db.remote')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $i = $info[$r['name']] ?? ['tables' => 0, 'size' => 0]; ?>
    <tr><td class="font-mono font-medium"><?= e($r['name']) ?></td><td><?= e($r['username']) ?></td><td><?= (int)$i['tables'] ?></td><td><?= e(bytes((int)$i['size'])) ?></td>
      <td><form method="post" action="/databases/<?= (int)$r['id'] ?>/remote" class="inline"><?= csrf_field() ?><button class="badge <?= $r['remote_access'] ? 'badge-warn' : 'badge-ok' ?>"><?= e(t($r['remote_access'] ? 'db.remote_on' : 'db.remote_off')) ?></button></form></td>
      <td class="space-x-1 whitespace-nowrap text-right">
        <form method="post" action="/databases/<?= (int)$r['id'] ?>/password" class="inline"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 text-xs dark:border-slate-700"><?= e(t('db.reset')) ?></button></form>
        <form method="post" action="/databases/<?= (int)$r['id'] ?>/delete" class="inline" data-confirm="<?= e(t('common.confirm')) ?>"><?= csrf_field() ?><button class="btn-ghost text-xs text-red-600"><?= e(t('common.delete')) ?></button></form></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="py-8 text-center text-slate-400"><?= e(t('common.empty')) ?></td></tr><?php endif; ?>
  </tbody></table></div>
<?php if ($me['role'] === 'admin'): ?>
<form method="post" action="/databases/bind" class="card mt-6 flex flex-wrap items-center gap-3"><?= csrf_field() ?>
  <span class="text-sm text-slate-500"><?= e(t('db.bind_hint')) ?></span>
  <select class="input w-auto" name="enable"><option value="">127.0.0.1</option><option value="1">0.0.0.0</option></select>
  <button class="btn"><?= e(t('common.save')) ?></button></form>
<?php endif; ?>

<?php if ($me['role'] === 'admin'): $running = in_array($pma['job'] ?? '', ['active', 'activating'], true); ?>
<div class="card mt-6 space-y-3">
  <?php if ($running): ?><div data-autorefresh></div><?php endif; ?>
  <div class="flex flex-wrap items-center justify-between gap-2">
    <h2 class="h2">phpMyAdmin <?php if (!empty($pma['version'])): ?><span class="text-sm font-normal text-slate-400"><?= e($pma['version']) ?> · PHP <?= e((string)($pma['php'] ?? '')) ?></span><?php endif; ?></h2>
    <span class="badge <?= !empty($pma['installed']) ? 'badge-ok' : 'badge-warn' ?>"><?= e($running ? t('stack.installing') : (!empty($pma['installed']) ? ($pma['active'] ?? '') : t('stack.not_installed_short'))) ?></span>
  </div>
  <p class="text-sm text-slate-500"><?= e(t('pma.hint')) ?></p>
  <?php if (!empty($pma['log']) && (empty($pma['installed']) || ($pma['job'] ?? '') === 'failed' || $running)): ?><pre class="max-h-48 overflow-auto rounded bg-slate-900 p-3 text-xs text-green-300"><?= e($pma['log']) ?></pre><?php endif; ?>
  <?php if (!$running): ?>
  <div class="flex flex-wrap gap-2">
    <form method="post" action="/databases/pma/install" data-confirm="<?= e(t('pma.install_confirm')) ?>"><?= csrf_field() ?><button class="btn"><?= e(t(!empty($pma['installed']) ? 'pma.update' : 'pma.install')) ?></button></form>
    <?php if (!empty($pma['installed'])): ?><form method="post" action="/databases/pma/uninstall" data-confirm="<?= e(t('pma.remove_confirm')) ?>"><?= csrf_field() ?><button class="btn-danger"><?= e(t('pma.remove')) ?></button></form><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
