<?php if ($live): ?><div data-autorefresh></div><?php endif; ?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
  <h1 class="h1 mb-0"><?= e(t('nav.waf')) ?> · <?= e(t('waf.log')) ?></h1>
  <form method="get" action="/waf/log" class="flex items-center gap-2">
    <select class="input w-auto" name="domain_id"><option value="0"><?= e(t('waf.all_domains')) ?></option><?php foreach ($domains as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$d['id'] === $fid ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select>
    <label class="flex items-center gap-1 text-sm"><input type="checkbox" name="live" value="1" <?= $live ? 'checked' : '' ?>> <?= e(t('waf.live')) ?></label>
    <button class="btn"><?= e(t('waf.apply_filter')) ?></button>
    <a class="text-sm text-brand hover:underline" href="/waf">← <?= e(t('nav.waf')) ?></a></form>
</div>
<div class="card overflow-x-auto p-0"><table class="table">
  <thead><tr><th><?= e(t('common.time')) ?></th><th>IP</th><th><?= e(t('domains.name')) ?></th><th><?= e(t('waf.request')) ?></th><th>HTTP</th><th><?= e(t('waf.matched')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($entries as $x): ?>
    <tr><td class="whitespace-nowrap text-xs"><?= e($x['time']) ?></td><td class="font-mono text-xs"><?= e($x['ip']) ?></td><td><?= e($x['host']) ?></td>
      <td class="max-w-xs break-all font-mono text-xs"><?= e($x['method']) ?> <?= e($x['uri']) ?></td>
      <td><span class="badge <?= $x['status'] >= 400 ? 'badge-warn' : 'badge-ok' ?>"><?= (int)$x['status'] ?></span></td>
      <td class="max-w-sm text-xs"><?php foreach ($x['rules'] as $r): ?><div><b><?= e($r['id']) ?></b> <?= e($r['msg']) ?></div><?php endforeach; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$entries): ?><tr><td colspan="6" class="py-8 text-center text-slate-400"><?= e(t('waf.no_events')) ?></td></tr><?php endif; ?>
  </tbody></table></div>
