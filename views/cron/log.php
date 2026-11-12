<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
  <h1 class="h1 mb-0"><?= e(t('cron.view_log')) ?>: <?= e($j['label'] !== '' ? $j['label'] : t('cron.unnamed')) ?></h1>
  <a class="text-sm text-brand hover:underline" href="/cron">← <?= e(t('nav.cron')) ?></a>
</div>
<p class="mb-3 break-all font-mono text-xs text-slate-500"><?= e($j['schedule']) ?> · <?= e($j['command']) ?></p>
<?php if (!$log['exists']): ?>
  <p class="card text-slate-400"><?= e(t('cron.log_empty')) ?></p>
<?php else: ?>
  <p class="mb-2 text-xs text-slate-500"><?= e(t('cron.last_write')) ?>: <?= e((new DateTimeImmutable('@' . (int)$log['mtime']))->setTimezone($tz)->format('Y-m-d H:i:s T')) ?></p>
  <pre class="card h-[65vh] overflow-auto whitespace-pre-wrap break-all bg-slate-900 p-4 font-mono text-xs text-green-300 dark:bg-black"><?= e($log['data']) ?></pre>
<?php endif; ?>
