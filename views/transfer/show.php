<?php $busy = (bool)array_filter($items, fn($i) => in_array($i['status'], ['queued', 'running'], true)); ?>
<?php if ($busy): ?><div data-autorefresh></div><?php endif; ?>
<a href="/transfers" class="mb-2 inline-block text-sm text-slate-500 hover:text-brand">&larr; <?= e(t('nav.transfers')) ?></a>
<h1 class="h1"><?= e(t('transfer.progress_title')) ?></h1>
<p class="mb-6 text-sm text-slate-500"><?= e(t('transfer.from', ['url' => $t['source_url']])) ?></p>
<div class="space-y-4">
<?php foreach ($items as $i): $r = json_decode((string)$i['result'], true) ?: []; ?>
  <div class="card space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 class="h2 mb-0"><?= e($i['domain']) ?></h2>
      <?php $cls = ['done' => 'badge-ok', 'failed' => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300'][$i['status']] ?? 'badge-warn'; ?>
      <span class="badge <?= $cls ?>"><?= e(t('transfer.is_' . $i['status'])) ?><?= $i['status'] === 'running' && $i['step'] ? ' · ' . e(t('transfer.step_' . $i['step'])) : '' ?></span>
    </div>
    <div class="h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"><div class="h-2 rounded-full <?= $i['status'] === 'failed' ? 'bg-red-500' : 'bg-brand' ?> transition-all" data-pct="<?= (int)$i['progress'] ?>"></div></div>
    <?php if ($i['status'] === 'done'): $chk = $r['check'] ?? []; ?>
      <div class="grid gap-3 rounded-lg bg-slate-50 p-4 text-sm dark:bg-white/[0.03] sm:grid-cols-2">
        <div><p class="font-semibold"><?= e(t('transfer.next')) ?></p>
          <ol class="mt-1 list-inside list-decimal space-y-1 text-slate-600 dark:text-slate-300">
            <li><?= e(t('transfer.next_test', ['ip' => $r['ip'] ?? '', 'domain' => $i['domain']])) ?><br><code class="text-xs"><?= e(($r['ip'] ?? '') . ' ' . $i['domain']) ?></code></li>
            <li><?= e(t('transfer.next_dns', ['ip' => $r['ip'] ?? ''])) ?></li>
            <li><?= e(t('transfer.next_ssl')) ?></li>
          </ol></div>
        <div class="space-y-1">
          <p><?= e(t('transfer.owner')) ?>: <b><?= e($r['owner'] ?? '') ?></b><?= !empty($r['owner_created']) ? ' (' . e(t('transfer.owner_new')) . ')' : '' ?></p>
          <?php foreach ($r['databases'] ?? [] as $db): ?><p><?= e(t('transfer.database')) ?>: <span class="font-mono"><?= e($db['old']) ?></span> → <span class="font-mono"><?= e($db['new']) ?></span></p><?php endforeach; ?>
          <p><?= e(t('transfer.answers')) ?>: HTTP <b><?= (int)($chk['http'] ?? 0) ?: '—' ?></b> · HTTPS <b><?= (int)($chk['https'] ?? 0) ?: '—' ?></b></p>
          <?php if (!empty($i['did'])): ?><p><a class="link" href="/domains/<?= (int)$i['did'] ?>"><?= e(t('transfer.open_site')) ?> &rarr;</a></p><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
    <?php foreach ($r['warnings'] ?? [] as $w): ?><p class="text-sm text-amber-700 dark:text-amber-300">⚠ <?= e($w) ?></p><?php endforeach; ?>
    <?php if ($i['status'] === 'failed'): ?>
      <p class="text-sm text-red-600"><?= e($r['error'] ?? '') ?></p>
      <form method="post" action="/transfers/<?= (int)$t['id'] ?>/items/<?= (int)$i['id'] ?>/retry"><?= csrf_field() ?><button class="btn"><?= e(t('transfer.retry')) ?></button>
        <span class="ml-2 text-xs text-slate-500"><?= e(t('transfer.rolled_back')) ?></span></form>
    <?php endif; ?>
    <?php if ($i['log'] !== ''): ?><details><summary class="cursor-pointer text-xs text-slate-500"><?= e(t('transfer.log')) ?></summary>
      <pre class="mt-2 max-h-64 overflow-auto rounded bg-slate-900 p-3 text-xs text-green-300"><?= e($i['log']) ?></pre></details><?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
