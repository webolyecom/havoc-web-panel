<?php $id = (int)$d['id']; ?>
<div class="grid gap-6 lg:grid-cols-[minmax(0,22rem)_1fr]">
  <form method="post" action="/domains/<?= $id ?>/php" class="card space-y-4">
    <?= csrf_field() ?><h2 class="h2">PHP</h2>
    <div><label class="label"><?= e(t('domains.php_version')) ?></label><select class="input" name="php"><?php foreach ($php as $v): ?><option <?= $v === $d['php_version'] ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <label class="flex items-center gap-2"><input type="checkbox" name="opcache" value="1" <?= $d['opcache_enabled'] ? 'checked' : '' ?>> OPcache</label>
    <div><label class="label">OPcache MB</label><input class="input" type="number" min="32" max="1024" name="opcache_memory" value="<?= (int)$d['opcache_memory'] ?>"></div>
    <button class="btn"><?= e(t('common.save')) ?></button>
  </form>
  <form method="post" action="/domains/<?= $id ?>/php" class="card space-y-3">
    <?= csrf_field() ?><input type="hidden" name="ext_form" value="1">
    <input type="hidden" name="php" value="<?= e($d['php_version']) ?>"><input type="hidden" name="opcache" value="<?= $d['opcache_enabled'] ? 1 : '' ?>"><input type="hidden" name="opcache_memory" value="<?= (int)$d['opcache_memory'] ?>">
    <h2 class="h2"><?= e(t('domains.extensions')) ?></h2>
    <div class="grid max-h-[28rem] grid-cols-2 gap-1 overflow-y-auto text-sm sm:grid-cols-3 lg:grid-cols-4">
      <?php foreach ($ext['available'] as $x): ?><label class="flex items-center gap-2"><input type="checkbox" name="ext[]" value="<?= e($x) ?>" <?= in_array($x, $enabled, true) ? 'checked' : '' ?>> <?= e($x) ?></label><?php endforeach; ?>
    </div>
    <button class="btn"><?= e(t('common.save')) ?></button>
  </form>
</div>
