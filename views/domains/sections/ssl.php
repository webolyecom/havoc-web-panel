<?php $id = (int)$d['id']; ?>
<div class="max-w-3xl">
  <div class="card space-y-4">
    <?php [$sslCls, $sslTxt] = Havoc\Services\SslService::describe($d); ?>
    <?php if ($d['ssl_auto'] === 'running'): ?><div data-autorefresh></div><?php endif; ?>
    <h2 class="h2">SSL <span class="badge <?= $d['ssl_mode'] === 'none' ? 'badge-warn' : 'badge-ok' ?>"><?= e($d['ssl_mode']) ?></span></h2>
    <?php if ($sslTxt !== ''): ?><p class="text-sm"><span class="badge <?= e($sslCls) ?>">Let's Encrypt</span> <?= e($sslTxt) ?></p><?php endif; ?>
    <p class="text-xs text-slate-500"><?= e(t('ssl.names')) ?>: <?= e(implode(', ', Havoc\Services\DomainService::serverNames($d))) ?></p>
    <?php if ($d['ssl_auto'] !== 'running'): ?>
    <form method="post" action="/domains/<?= $id ?>/ssl"><?= csrf_field() ?><input type="hidden" name="mode" value="letsencrypt"><button class="btn"><?= e(t($d['ssl_mode'] === 'letsencrypt' ? 'ssl.letsencrypt_again' : 'ssl.letsencrypt')) ?></button></form>
    <?php endif; ?>
    <details><summary class="cursor-pointer text-sm text-brand"><?= e(t('ssl.custom')) ?> / Cloudflare Origin</summary>
      <form method="post" action="/domains/<?= $id ?>/ssl" class="mt-3 space-y-2"><?= csrf_field() ?>
        <select class="input" name="mode"><option value="custom">Custom</option><option value="cloudflare">Cloudflare Origin</option></select>
        <textarea class="input font-mono text-xs" name="cert" rows="4" placeholder="-----BEGIN CERTIFICATE-----" required></textarea>
        <textarea class="input font-mono text-xs" name="key" rows="4" placeholder="-----BEGIN PRIVATE KEY-----" required></textarea>
        <textarea class="input font-mono text-xs" name="chain" rows="2" placeholder="CA chain (optional)"></textarea>
        <button class="btn"><?= e(t('ssl.install')) ?></button></form></details>
  </div>
</div>
