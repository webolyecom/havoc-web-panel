<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
  <h1 class="h1 mb-0"><?= e(t($type === 'error' ? 'logs.recent_errors' : ($type === 'php' ? 'logs.recent_php' : 'logs.recent_access'))) ?></h1>
  <span class="flex gap-3 text-sm"><?php foreach (['error', 'php', 'access'] as $t): ?><a class="<?= $t === $type ? 'font-semibold text-brand' : 'text-slate-500 hover:underline' ?>" href="/logs/recent?type=<?= $t ?>"><?= e(t('logs.' . $t)) ?></a><?php endforeach; ?>
    <a class="text-brand hover:underline" href="/logs">← <?= e(t('logs.all_logs')) ?></a></span>
</div>
<div class="space-y-4">
<?php foreach ($logs as $dom => $x): ?>
  <div class="card space-y-2 p-4">
    <div class="flex items-center justify-between gap-2"><h2 class="font-semibold"><?= e($dom) ?></h2>
      <span class="text-xs text-slate-500"><?= e(date('Y-m-d H:i:s', $x['mtime'])) ?> · <a class="text-brand hover:underline" href="/logs/view?domain_id=<?= (int)($ids[$dom] ?? 0) ?>&amp;type=<?= e($type) ?>"><?= e(t('logs.open')) ?></a></span></div>
    <pre class="max-h-72 overflow-auto whitespace-pre-wrap break-all rounded bg-slate-900 p-3 font-mono text-xs text-slate-200"><?= e($x['data']) ?></pre>
  </div>
<?php endforeach; ?>
<?php if (!$logs): ?><p class="card py-8 text-center text-slate-400"><?= e(t('logs.none_recent')) ?></p><?php endif; ?>
</div>
