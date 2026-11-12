<?php $admin = Havoc\Core\Auth::user()['role'] === 'admin'; ?>
<h1 class="h1"><?= e(t('nav.dns')) ?></h1>
<?php if (!$ns): ?><div class="mb-4 rounded-lg bg-amber-100 p-3 text-sm text-amber-900"><?= e(t('dns.no_ns')) ?></div><?php endif; ?>
<?php if ($admin): ?>
<form method="post" action="/dns/settings" class="card mb-6 grid gap-3 sm:grid-cols-4"><?= csrf_field() ?>
  <div><label class="label"><?= e(t('dns.ns1')) ?></label><input class="input" name="ns1" value="<?= e($ns1) ?>" placeholder="ns1.example.com"></div>
  <div><label class="label"><?= e(t('dns.ns2')) ?></label><input class="input" name="ns2" value="<?= e($ns2) ?>" placeholder="ns2.example.com"></div>
  <div><label class="label"><?= e(t('dns.server_ip')) ?></label><input class="input" name="server_ip" value="<?= e($ip) ?>" placeholder="203.0.113.10"></div>
  <div class="flex items-end"><button class="btn w-full"><?= e(t('common.save')) ?></button></div>
  <p class="text-xs text-slate-500 sm:col-span-4"><?= e(t('dns.settings_hint')) ?></p>
</form>
<?php endif; ?>
<div class="card overflow-x-auto p-0"><table class="table">
  <thead><tr><th><?= e(t('domains.name')) ?></th><th><?= e(t('domains.owner')) ?></th><th><?= e(t('common.status')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($zones as $z): ?>
    <tr><td class="font-medium"><a class="text-brand hover:underline" href="/dns/<?= (int)$z['id'] ?>"><?= e($z['name']) ?></a></td><td><?= e($z['username']) ?></td>
      <td><span class="badge <?= $z['dns_enabled'] ? 'badge-ok' : 'badge-warn' ?>"><?= e(t($z['dns_enabled'] ? 'dns.active' : 'dns.inactive')) ?></span></td>
      <td class="text-right"><a class="text-sm text-slate-500 hover:underline" href="/dns/<?= (int)$z['id'] ?>"><?= e(t('common.edit')) ?></a></td></tr>
  <?php endforeach; ?>
  <?php if (!$zones): ?><tr><td colspan="4" class="py-8 text-center text-slate-400"><?= e(t('common.empty')) ?></td></tr><?php endif; ?>
  </tbody></table></div>
