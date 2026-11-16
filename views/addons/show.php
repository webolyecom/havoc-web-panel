<?php
$titles = ['redis' => 'Redis', 'meilisearch' => 'Meilisearch', 'memcached' => 'Memcached', 'fail2ban' => 'Fail2ban', 'nodejs' => 'Node.js'];
$d = $st;
$busy = in_array($d['job'] ?? '', ['active', 'activating'], true);
?>
<a href="/addons" class="mb-2 inline-block text-sm text-slate-500 hover:text-brand">&larr; <?= e(t('nav.addons_all')) ?></a>
<h1 class="h1"><?= e($titles[$name]) ?></h1>
<p class="mb-6 max-w-3xl text-sm text-slate-500"><?= e(t('addon.d_' . $name)) ?></p>
<?php if ($secret): ?>
<div class="mb-4 rounded-lg bg-amber-100 p-4 text-sm text-amber-900"><?= e(t('db.once')) ?><br><b><?= e($secret['label']) ?>:</b> <code class="break-all"><?= e($secret['value']) ?></code></div>
<?php endif; ?>
<div class="max-w-4xl space-y-6">
<?php if (in_array($name, ['memcached', 'fail2ban', 'nodejs'], true)): ?>
  <?php if ($busy): ?><div data-autorefresh></div><?php endif; ?>
  <?php if (!empty($d['log']) && ($busy || ($d['job'] ?? '') === 'failed')): ?>
  <div class="card"><p class="mb-2 text-sm font-medium"><?= e(t($busy ? 'stack.working' : 'addon.failed')) ?></p>
    <pre class="max-h-60 overflow-auto rounded bg-slate-900 p-3 text-xs text-green-300"><?= e($d['log']) ?></pre></div>
  <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/_' . $name . '.php'; ?>
<?php if (in_array($name, ['memcached', 'fail2ban', 'nodejs'], true) && !empty($d['installed']) && !$busy): ?>
  <form method="post" action="/addons/<?= e($name) ?>/uninstall" class="card flex flex-wrap items-center gap-3" data-confirm="<?= e(t('addon.remove_confirm_' . $name)) ?>"><?= csrf_field() ?>
    <div class="mr-auto"><p class="font-medium"><?= e(t('addon.remove_title', ['name' => $titles[$name]])) ?></p><p class="text-xs text-slate-500"><?= e(t('addon.remove_hint_' . $name)) ?></p></div>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="purge" value="1"> <?= e(t('addon.purge_' . $name)) ?></label>
    <button class="btn-danger"><?= e(t('addon.remove', ['name' => $titles[$name]])) ?></button>
  </form>
<?php endif; ?>
</div>
