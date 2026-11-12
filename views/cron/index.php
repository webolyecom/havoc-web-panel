<h1 class="h1"><?= e(t('nav.cron')) ?></h1>

<?php if ($run): ?>
<div class="card mb-6 space-y-2">
  <div class="flex flex-wrap items-center justify-between gap-2">
    <h2 class="h2"><?= e(t('cron.run_result')) ?>: <span class="font-mono text-sm"><?= e(mb_strimwidth($run['label'], 0, 80, '…')) ?></span></h2>
    <span class="badge <?= $run['code'] === 0 ? 'badge-ok' : 'badge-warn' ?>"><?= $run['timeout'] ? e(t('cron.timed_out')) : e(t('cron.exit_code', ['code' => $run['code']])) ?> · <?= e((string)$run['seconds']) ?> s</span>
  </div>
  <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-all rounded bg-slate-900 p-3 font-mono text-xs text-green-300"><?= $run['output'] === '' ? e(t('cron.no_output')) : e($run['output']) ?></pre>
  <?php if ($run['truncated']): ?><p class="text-xs text-slate-500"><?= e(t('cron.truncated')) ?></p><?php endif; ?>
</div>
<?php endif; ?>

<form method="post" action="/cron" class="card mb-6 space-y-4"><?= csrf_field() ?>
  <h2 class="h2"><?= e(t('cron.new')) ?></h2>
  <div class="grid gap-3 sm:grid-cols-6">
    <div class="sm:col-span-2"><label class="label"><?= e(t('cron.label')) ?></label><input class="input" name="label" maxlength="80" placeholder="<?= e(t('cron.label_ph')) ?>"></div>
    <div class="sm:col-span-4"><label class="label"><?= e(t('cron.command')) ?></label><input class="input font-mono" name="command" placeholder="php artisan schedule:run" required maxlength="1000"></div>
  </div>
  <?php $bValue = '0 3 * * *'; require __DIR__ . '/_builder.php'; ?>
  <div class="grid gap-3 sm:grid-cols-6">
    <div class="sm:col-span-2"><label class="label"><?= e(t('cron.domain')) ?></label>
      <select class="input" name="domain_id"><option value="0">— <?= e(t('cron.no_domain')) ?></option><?php foreach ($domains as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
    <?php if (count($owners) > 1): ?><div class="sm:col-span-2"><label class="label"><?= e(t('domains.owner')) ?></label><select class="input" name="owner"><?php foreach ($owners as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['username']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <label class="flex items-end gap-2 pb-2 text-sm sm:col-span-2"><input type="checkbox" name="log_output" value="1" checked> <?= e(t('cron.log_output')) ?></label>
  </div>
  <div class="flex flex-wrap items-center justify-between gap-3">
    <p class="text-xs text-slate-500"><?= e(t('cron.hint')) ?> <?= e(t('cron.tz', ['tz' => $tz])) ?></p>
    <button class="btn"><?= e(t('common.add')) ?></button>
  </div>
</form>

<div class="space-y-3">
<?php foreach ($rows as $j): $x = Havoc\Services\CronService::explain($j['schedule']); ?>
  <div class="card space-y-2 p-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <p class="font-medium"><?= e($j['label'] !== '' ? $j['label'] : t('cron.unnamed')) ?>
          <span class="ml-1 badge <?= $j['enabled'] ? 'badge-ok' : 'badge-warn' ?>"><?= e(t($j['enabled'] ? 'cron.on' : 'cron.off')) ?></span>
          <?php if ($j['log_output']): ?><span class="badge badge-ok"><?= e(t('cron.logging')) ?></span><?php endif; ?></p>
        <p class="mt-1 text-sm"><code class="font-mono"><?= e($j['schedule']) ?></code> · <?= e($x['text']) ?></p>
        <?php if ($j['enabled'] && $x['next']): ?><p class="text-xs text-slate-500"><?= e(t('cron.next')) ?>: <?= e(implode(' · ', $x['next'])) ?></p><?php endif; ?>
        <p class="mt-1 break-all font-mono text-xs text-slate-600 dark:text-slate-400"><?= e($j['command']) ?></p>
        <p class="text-xs text-slate-400"><?= e((string)($j['domain'] ?? t('cron.no_domain'))) ?> · <?= e($j['username']) ?></p>
      </div>
      <div class="flex flex-wrap gap-1">
        <form method="post" action="/cron/<?= (int)$j['id'] ?>/run" data-confirm="<?= e(t('cron.run_confirm')) ?>"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 text-xs dark:border-slate-700">▶ <?= e(t('cron.run_now')) ?></button></form>
        <?php if ($j['log_output']): ?><a class="btn-ghost border border-slate-300 text-xs dark:border-slate-700" href="/cron/<?= (int)$j['id'] ?>/log"><?= e(t('cron.view_log')) ?></a><?php endif; ?>
        <form method="post" action="/cron/<?= (int)$j['id'] ?>/toggle"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 text-xs dark:border-slate-700"><?= e(t($j['enabled'] ? 'cron.pause' : 'cron.resume')) ?></button></form>
        <form method="post" action="/cron/<?= (int)$j['id'] ?>/delete" data-confirm="<?= e(t('common.confirm')) ?>"><?= csrf_field() ?><button class="btn-ghost text-xs text-red-600"><?= e(t('common.delete')) ?></button></form>
      </div>
    </div>
    <details><summary class="cursor-pointer text-sm text-brand"><?= e(t('common.edit')) ?></summary>
      <form method="post" action="/cron/<?= (int)$j['id'] ?>" class="mt-3 space-y-3"><?= csrf_field() ?>
        <div class="grid gap-3 sm:grid-cols-6">
          <div class="sm:col-span-2"><label class="label"><?= e(t('cron.label')) ?></label><input class="input" name="label" maxlength="80" value="<?= e($j['label']) ?>"></div>
          <div class="sm:col-span-4"><label class="label"><?= e(t('cron.command')) ?></label><input class="input font-mono" name="command" value="<?= e($j['command']) ?>" required maxlength="1000"></div>
        </div>
        <?php $bValue = $j['schedule']; require __DIR__ . '/_builder.php'; ?>
        <div class="grid gap-3 sm:grid-cols-6">
          <div class="sm:col-span-2"><label class="label"><?= e(t('cron.domain')) ?></label>
            <select class="input" name="domain_id"><option value="0">— <?= e(t('cron.no_domain')) ?></option><?php foreach ($domains as $d): if ((int)$d['user_id'] !== (int)$j['user_id']) continue; ?><option value="<?= (int)$d['id'] ?>" <?= (int)$d['id'] === (int)$j['domain_id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
          <label class="flex items-end gap-2 pb-2 text-sm sm:col-span-2"><input type="checkbox" name="log_output" value="1" <?= $j['log_output'] ? 'checked' : '' ?>> <?= e(t('cron.log_output')) ?></label>
          <div class="flex items-end sm:col-span-2"><button class="btn w-full"><?= e(t('common.save')) ?></button></div>
        </div>
      </form>
    </details>
  </div>
<?php endforeach; ?>
<?php if (!$rows): ?><p class="card py-8 text-center text-slate-400"><?= e(t('common.empty')) ?></p><?php endif; ?>
</div>
<script src="<?= e(asset('cron.js')) ?>" defer></script>
