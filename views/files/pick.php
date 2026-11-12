<h1 class="h1"><?= e(t('files.title')) ?></h1>
<p class="mb-4 text-sm text-slate-500"><?= e(t('fm.pick_site')) ?></p>
<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
  <?php foreach ($domains as $d): ?>
    <a href="/domains/<?= (int)$d['id'] ?>/files" class="card flex items-center justify-between gap-3 transition hover:border-brand">
      <span class="min-w-0"><span class="block truncate font-medium"><?= e($d['name']) ?></span><span class="text-xs text-slate-400"><?= e($d['username']) ?> · <?= e(t('domains.type_' . $d['type'])) ?></span></span>
      <span class="text-brand">→</span>
    </a>
  <?php endforeach; ?>
  <?php if (!$domains): ?><p class="card text-slate-400"><?= e(t('common.empty')) ?></p><?php endif; ?>
</div>
