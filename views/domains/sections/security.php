<?php $id = (int)$d['id']; ?>
<div class="max-w-2xl">
  <form method="post" action="/domains/<?= $id ?>/security" class="card space-y-3">
    <?= csrf_field() ?>
    <label class="flex items-center gap-2"><input type="checkbox" name="force_https" value="1" <?= $d['force_https'] ? 'checked' : '' ?>> <?= e(t('ssl.force')) ?></label>
    <label class="flex items-center gap-2"><input type="checkbox" name="hsts" value="1" <?= $d['hsts'] ? 'checked' : '' ?>> HSTS</label>
    <label class="flex items-center gap-2"><input type="checkbox" name="waf" value="1" <?= $d['waf'] ? 'checked' : '' ?>> ModSecurity / WAF</label>
    <label class="flex items-center gap-2"><input type="checkbox" name="logs_enabled" value="1" <?= $d['logs_enabled'] ? 'checked' : '' ?>> <?= e(t('domains.logs_enabled')) ?></label>
    <button class="btn"><?= e(t('common.save')) ?></button>
  </form>
</div>
