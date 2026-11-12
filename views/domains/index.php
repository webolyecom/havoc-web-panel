<h1 class="h1"><?= e(t('nav.domains')) ?></h1>
<?php if (Havoc\Core\Auth::user()['role'] === 'admin'): ?>
<form method="post" action="/domains/settings" class="card mb-4 flex flex-wrap items-center gap-4 text-sm"><?= csrf_field() ?>
  <label class="flex items-center gap-2"><input type="checkbox" name="ssl_auto" value="1" <?= $sslAuto ? 'checked' : '' ?>> <?= e(t('ssl.auto_setting')) ?></label>
  <label class="flex items-center gap-2"><input type="checkbox" name="ssl_auto_https" value="1" <?= $sslAutoHttps ? 'checked' : '' ?>> <?= e(t('ssl.auto_https_setting')) ?></label>
  <button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('common.save')) ?></button>
</form>
<?php endif; ?>
<?php if (array_filter($domains, fn($x) => ($x['ssl_auto'] ?? '') === 'running')): ?><div data-autorefresh></div><?php endif; ?>
<form method="post" action="/domains" class="card mb-6 grid gap-3 sm:grid-cols-6">
  <?= csrf_field() ?>
  <div class="sm:col-span-2"><label class="label"><?= e(t('domains.name')) ?></label><input class="input" name="name" placeholder="example.com" required></div>
  <div><label class="label"><?= e(t('domains.type')) ?></label>
    <select class="input" name="type"><?php foreach (['main', 'addon', 'sub', 'alias'] as $ty): ?><option value="<?= $ty ?>"><?= e(t('domains.type_' . $ty)) ?></option><?php endforeach; ?></select></div>
  <div><label class="label"><?= e(t('domains.parent')) ?></label>
    <select class="input" name="parent"><option value="0">—</option><?php foreach ($mains as $m): ?><option value="<?= (int)$m['id'] ?>"><?= e($m['name']) ?></option><?php endforeach; ?></select></div>
  <div><label class="label">PHP</label><select class="input" name="php"><?php foreach ($php as $v): ?><option <?= $v === '8.2' ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
  <?php if (count($owners) > 1): ?><div><label class="label"><?= e(t('domains.owner')) ?></label><select class="input" name="owner"><?php foreach ($owners as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['username']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <div class="flex items-end"><button class="btn w-full"><?= e(t('domains.add')) ?></button></div>
  <p class="text-xs text-slate-500 sm:col-span-6"><?= e(t('domains.type_hint')) ?></p>
</form>
<div class="card overflow-x-auto p-0"><table class="table">
  <thead><tr><th><?= e(t('domains.name')) ?></th><th><?= e(t('domains.type')) ?></th><th><?= e(t('domains.owner')) ?></th><th>PHP</th><th>SSL</th><th><?= e(t('common.status')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($domains as $d): $alias = $d['type'] === 'alias'; ?>
    <tr><td class="font-medium"><a class="text-brand hover:underline" href="/domains/<?= (int)($alias ? $d['parent_id'] : $d['id']) ?>"><?= e($d['name']) ?></a></td>
      <td><span class="badge badge-ok"><?= e(t('domains.type_' . $d['type'])) ?></span></td>
      <td><?= e($d['username']) ?></td><td><?= $alias ? '—' : e($d['php_version']) ?></td>
      <td><?php if ($alias): ?>—<?php elseif ($d['ssl_mode'] !== 'none'): ?><span class="badge badge-ok"><?= e($d['ssl_mode']) ?></span>
        <?php elseif (in_array($d['ssl_auto'], ['running', 'pending'], true)): ?><span class="badge badge-warn" title="<?= e(Havoc\Services\SslService::describe($d)[1]) ?>"><?= e(t('ssl.st_' . $d['ssl_auto'])) ?></span>
        <?php elseif ($d['ssl_auto'] === 'failed'): ?><span class="badge badge-warn" title="<?= e(Havoc\Services\SslService::describe($d)[1]) ?>"><?= e(t('ssl.st_failed')) ?></span><?php else: ?>—<?php endif; ?></td>
      <td><span class="badge <?= $d['status'] === 'active' ? 'badge-ok' : 'badge-warn' ?>"><?= e($d['status']) ?></span></td>
      <td class="space-x-3 text-right"><?php if (!$alias): ?><a class="text-sm text-slate-500 hover:underline" href="/domains/<?= (int)$d['id'] ?>/files"><?= e(t('files.title')) ?></a><a class="text-sm text-slate-500 hover:underline" href="/domains/<?= (int)$d['id'] ?>/logs"><?= e(t('domains.logs')) ?></a><?php endif; ?>
        <?php if ($alias): ?><form method="post" action="/domains/<?= (int)$d['id'] ?>/delete" class="inline" data-confirm="<?= e(t('common.confirm')) ?>"><?= csrf_field() ?><button class="text-sm text-red-600 hover:underline"><?= e(t('common.delete')) ?></button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$domains): ?><tr><td colspan="7" class="py-8 text-center text-slate-400"><?= e(t('common.empty')) ?></td></tr><?php endif; ?>
  </tbody></table></div>
