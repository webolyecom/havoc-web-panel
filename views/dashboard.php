<h1 class="h1"><?= e(t('nav.dashboard')) ?></h1>
<?php if (Havoc\Core\Auth::user()['role'] === 'admin' && ($newRel = Havoc\Services\UpdateService::available())): ?>
<a href="/server/settings#update" class="mb-6 flex items-center justify-between gap-3 rounded-xl border border-brand/30 bg-brand/5 px-4 py-3 text-sm hover:bg-brand/10">
  <span><span class="font-semibold"><?= e(t('update.banner', ['v' => $newRel['version']])) ?></span> <span class="text-slate-500"><?= e(t('update.banner_hint')) ?></span></span>
  <span class="text-brand">&rarr;</span></a>
<?php endif; ?>
<div class="grid gap-4 sm:grid-cols-3">
  <div class="card"><p class="text-sm text-slate-500"><?= e(t('nav.domains')) ?></p><p class="mt-1 text-3xl font-bold"><?= (int)$domains ?></p></div>
  <?php if ($users): ?><div class="card"><p class="text-sm text-slate-500"><?= e(t('nav.users')) ?></p><p class="mt-1 text-3xl font-bold"><?= (int)$users ?></p></div><?php endif; ?>
  <?php if ($ws): ?><div class="card"><p class="text-sm text-slate-500"><?= e(t('dash.webserver')) ?></p><p class="mt-1 text-xl font-bold"><?= e($ws['name']) ?> <?= e($ws['version'] ?? '') ?></p></div><?php endif; ?>
</div>
<?php if ($audit): ?>
<h2 class="h2 mt-8"><?= e(t('dash.audit')) ?></h2>
<div class="card overflow-x-auto p-0"><table class="table">
  <thead><tr><th><?= e(t('common.time')) ?></th><th><?= e(t('nav.users')) ?></th><th><?= e(t('common.action')) ?></th><th>IP</th></tr></thead>
  <tbody><?php foreach ($audit as $a): ?><tr><td><?= e($a['created_at']) ?></td><td><?= e((string)$a['username']) ?></td><td><?= e($a['action']) ?> <span class="text-slate-400"><?= e((string)$a['target']) ?></span></td><td><?= e((string)$a['ip']) ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
