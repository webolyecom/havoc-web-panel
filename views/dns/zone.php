<?php $id = (int)$d['id']; ?>
<div class="mb-6 flex flex-wrap items-center justify-between gap-2">
  <h1 class="h1 mb-0"><?= e($d['name']) ?> · DNS</h1>
  <span class="flex items-center gap-3"><a class="text-sm text-brand hover:underline" href="/dns">← <?= e(t('nav.dns')) ?></a>
    <form method="post" action="/dns/<?= $id ?>/<?= $d['dns_enabled'] ? 'disable' : 'enable' ?>" <?= $d['dns_enabled'] ? 'data-confirm="' . e(t('common.confirm')) . '"' : '' ?>><?= csrf_field() ?>
      <button class="<?= $d['dns_enabled'] ? 'btn-danger' : 'btn' ?>"><?= e(t($d['dns_enabled'] ? 'dns.disable' : 'dns.enable')) ?></button></form></span>
</div>
<?php if (!$d['dns_enabled']): ?>
  <p class="card text-sm text-slate-500"><?= e(t('dns.off_hint')) ?></p>
<?php else: ?>
<p class="mb-4 text-sm text-slate-500"><?= e(t('dns.delegate')) ?> <code><?= e(implode(', ', $ns)) ?></code></p>
<form method="post" action="/dns/<?= $id ?>/records" class="card mb-6 grid gap-3 sm:grid-cols-6"><?= csrf_field() ?>
  <div><label class="label"><?= e(t('dns.type')) ?></label><select class="input" name="type"><?php foreach ($types as $ty): ?><option><?= e($ty) ?></option><?php endforeach; ?></select></div>
  <div><label class="label"><?= e(t('common.name')) ?></label><input class="input" name="name" value="@" required></div>
  <div class="sm:col-span-2"><label class="label"><?= e(t('dns.content')) ?></label><input class="input font-mono" name="content" required maxlength="2048"></div>
  <div><label class="label">TTL</label><input class="input" type="number" name="ttl" value="3600" min="60" max="604800"></div>
  <div><label class="label"><?= e(t('dns.prio')) ?></label><input class="input" type="number" name="prio" value="10" min="0" max="65535"></div>
  <div class="sm:col-span-6 flex items-center justify-between gap-3"><p class="text-xs text-slate-500"><?= e(t('dns.format_hint')) ?></p><button class="btn"><?= e(t('common.add')) ?></button></div>
</form>
<div class="card overflow-x-auto p-0"><table class="table">
  <thead><tr><th><?= e(t('common.name')) ?></th><th><?= e(t('dns.type')) ?></th><th><?= e(t('dns.content')) ?></th><th>TTL</th><th><?= e(t('dns.prio')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($records as $r): ?>
    <tr><td class="font-mono"><?= e($r['name']) ?></td><td><span class="badge badge-ok"><?= e($r['type']) ?></span></td>
      <td class="max-w-md break-all font-mono text-xs"><?= e($r['content']) ?></td><td><?= (int)$r['ttl'] ?></td><td><?= in_array($r['type'], ['MX', 'SRV'], true) ? (int)$r['prio'] : '—' ?></td>
      <td class="text-right"><form method="post" action="/dns/<?= $id ?>/records/<?= (int)$r['id'] ?>/delete" data-confirm="<?= e(t('common.confirm')) ?>"><?= csrf_field() ?><button class="btn-ghost text-xs text-red-600"><?= e(t('common.delete')) ?></button></form></td></tr>
  <?php endforeach; ?>
  <?php if (!$records): ?><tr><td colspan="6" class="py-8 text-center text-slate-400"><?= e(t('common.empty')) ?></td></tr><?php endif; ?>
  </tbody></table></div>
<?php endif; ?>
