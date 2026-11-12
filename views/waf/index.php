<?php $admin = Havoc\Core\Auth::user()['role'] === 'admin'; ?>
<h1 class="h1"><?= e(t('nav.waf')) ?></h1>
<div class="card mb-6 space-y-3">
  <div class="flex flex-wrap items-center gap-2 text-sm">
    <span class="badge <?= $status['module'] ? 'badge-ok' : 'badge-warn' ?>"><?= e(t('waf.module')) ?>: <?= e(t($status['module'] ? 'waf.present' : 'waf.missing')) ?></span>
    <span class="badge <?= $status['crs'] ? 'badge-ok' : 'badge-warn' ?>">OWASP CRS: <?= e(t($status['crs'] ? 'waf.present' : 'waf.missing')) ?></span>
    <span class="badge <?= $mode === 'on' ? 'badge-ok' : 'badge-warn' ?>"><?= e(t('waf.global')) ?>: <?= e(t('waf.mode_' . $mode)) ?></span>
    <?php if (($status['server'] ?? '') !== 'nginx'): ?><span class="text-amber-600"><?= e(t('waf.nginx_only')) ?></span><?php endif; ?>
    <a class="ml-auto btn" href="/waf/log"><?= e(t('waf.log')) ?></a>
  </div>
  <?php if ($admin): ?>
  <div class="flex flex-wrap items-end gap-3 border-t border-slate-100 pt-3 dark:border-slate-800">
    <form method="post" action="/waf/mode" class="flex items-end gap-2"><?= csrf_field() ?>
      <div><label class="label"><?= e(t('waf.global')) ?></label>
        <select class="input" name="mode"><?php foreach (['off', 'detect', 'on'] as $m): ?><option value="<?= $m ?>" <?= $m === $mode ? 'selected' : '' ?>><?= e(t('waf.mode_' . $m)) ?></option><?php endforeach; ?></select></div>
      <button class="btn"><?= e(t('common.save')) ?></button></form>
    <?php if (!$status['module'] || !$status['crs']): ?>
      <form method="post" action="/waf/install" data-confirm="<?= e(t('waf.install_confirm')) ?>"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('waf.install')) ?></button></form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <p class="text-xs text-slate-500"><?= e(t('waf.hint')) ?></p>
</div>

<h2 class="h2 mb-3"><?= e(t('nav.domains')) ?></h2>
<div class="card mb-6 overflow-x-auto p-0"><table class="table">
  <thead><tr><th><?= e(t('domains.name')) ?></th><th><?= e(t('domains.owner')) ?></th><th>WAF</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($domains as $d): ?>
    <tr><td class="font-medium"><?= e($d['name']) ?></td><td><?= e($d['username']) ?></td>
      <td><span class="badge <?= $d['waf'] ? 'badge-ok' : 'badge-warn' ?>"><?= e(t($d['waf'] ? 'cron.on' : 'cron.off')) ?></span></td>
      <td class="text-right"><form method="post" action="/waf/domains/<?= (int)$d['id'] ?>"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 text-xs dark:border-slate-700"><?= e(t($d['waf'] ? 'waf.turn_off' : 'waf.turn_on')) ?></button></form></td></tr>
  <?php endforeach; ?>
  <?php if (!$domains): ?><tr><td colspan="4" class="py-6 text-center text-slate-400"><?= e(t('common.empty')) ?></td></tr><?php endif; ?>
  </tbody></table></div>

<h2 class="h2 mb-3"><?= e(t('waf.rules')) ?></h2>
<form method="post" action="/waf/rules" class="card mb-4 grid gap-3 sm:grid-cols-6"><?= csrf_field() ?>
  <div class="sm:col-span-2"><label class="label"><?= e(t('waf.scope')) ?></label>
    <select class="input" name="domain_id"><?php if ($admin): ?><option value="0"><?= e(t('waf.all_domains')) ?></option><?php endif; ?><?php foreach ($domains as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
  <div class="sm:col-span-2"><label class="label"><?= e(t('waf.type')) ?></label>
    <select class="input" name="type"><option value="exclude"><?= e(t('waf.t_exclude')) ?></option><option value="block"><?= e(t('waf.t_block')) ?></option></select></div>
  <div class="sm:col-span-2"><label class="label"><?= e(t('waf.value')) ?></label><input class="input font-mono" name="value" required maxlength="100" placeholder="942100 · /wp-admin · sqlmap"></div>
  <div class="sm:col-span-2"><label class="label"><?= e(t('waf.target')) ?></label>
    <select class="input" name="target"><option value="uri">URI</option><option value="ua">User-Agent</option><option value="args"><?= e(t('waf.args')) ?></option></select></div>
  <div class="sm:col-span-2"><label class="label"><?= e(t('waf.op')) ?></label>
    <select class="input" name="op"><option value="contains"><?= e(t('waf.contains')) ?></option><option value="beginswith"><?= e(t('waf.begins')) ?></option></select></div>
  <div class="flex items-end sm:col-span-2"><button class="btn w-full"><?= e(t('common.add')) ?></button></div>
  <p class="text-xs text-slate-500 sm:col-span-6"><?= e(t('waf.rules_hint')) ?></p>
</form>
<div class="card overflow-x-auto p-0"><table class="table">
  <thead><tr><th><?= e(t('waf.scope')) ?></th><th><?= e(t('waf.type')) ?></th><th><?= e(t('waf.value')) ?></th><th><?= e(t('common.status')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rules as $r): ?>
    <tr><td><?= e($r['domain'] ?? t('waf.all_domains')) ?></td>
      <td><?= $r['type'] === 'exclude' ? e(t('waf.t_exclude')) : e(t('waf.t_block') . ' · ' . $r['target'] . ' ' . $r['op']) ?></td>
      <td class="font-mono text-xs"><?= e($r['value']) ?></td>
      <td><form method="post" action="/waf/rules/<?= (int)$r['id'] ?>/toggle"><?= csrf_field() ?><button class="badge <?= $r['enabled'] ? 'badge-ok' : 'badge-warn' ?>"><?= e(t($r['enabled'] ? 'cron.on' : 'cron.off')) ?></button></form></td>
      <td class="text-right"><form method="post" action="/waf/rules/<?= (int)$r['id'] ?>/delete" data-confirm="<?= e(t('common.confirm')) ?>"><?= csrf_field() ?><button class="btn-ghost text-xs text-red-600"><?= e(t('common.delete')) ?></button></form></td></tr>
  <?php endforeach; ?>
  <?php if (!$rules): ?><tr><td colspan="5" class="py-6 text-center text-slate-400"><?= e(t('common.empty')) ?></td></tr><?php endif; ?>
  </tbody></table></div>
