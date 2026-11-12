<?php $id = (int)$d['id']; ?>
<div class="mb-6 flex items-center justify-between">
  <h1 class="h1 mb-0"><?= e($d['name']) ?></h1>
  <span class="flex gap-2"><a class="btn" href="/domains/<?= $id ?>/files"><?= e(t('files.title')) ?></a><a class="btn" href="/domains/<?= $id ?>/logs"><?= e(t('domains.logs')) ?></a></span>
</div>
<div class="grid gap-6 lg:grid-cols-2">
  <form method="post" action="/domains/<?= $id ?>/php" class="card space-y-4">
    <?= csrf_field() ?><h2 class="h2">PHP</h2>
    <div><label class="label"><?= e(t('domains.php_version')) ?></label><select class="input" name="php"><?php foreach ($php as $v): ?><option <?= $v === $d['php_version'] ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <label class="flex items-center gap-2"><input type="checkbox" name="opcache" value="1" <?= $d['opcache_enabled'] ? 'checked' : '' ?>> OPcache</label>
    <div><label class="label">OPcache MB</label><input class="input" type="number" min="32" max="1024" name="opcache_memory" value="<?= (int)$d['opcache_memory'] ?>"></div>
    <button class="btn"><?= e(t('common.save')) ?></button>
  </form>

  <form method="post" action="/domains/<?= $id ?>/security" class="card space-y-3">
    <?= csrf_field() ?><h2 class="h2"><?= e(t('domains.security')) ?></h2>
    <label class="flex items-center gap-2"><input type="checkbox" name="force_https" value="1" <?= $d['force_https'] ? 'checked' : '' ?>> <?= e(t('ssl.force')) ?></label>
    <label class="flex items-center gap-2"><input type="checkbox" name="hsts" value="1" <?= $d['hsts'] ? 'checked' : '' ?>> HSTS</label>
    <label class="flex items-center gap-2"><input type="checkbox" name="waf" value="1" <?= $d['waf'] ? 'checked' : '' ?>> ModSecurity / WAF</label>
    <label class="flex items-center gap-2"><input type="checkbox" name="logs_enabled" value="1" <?= $d['logs_enabled'] ? 'checked' : '' ?>> <?= e(t('domains.logs_enabled')) ?></label>
    <button class="btn"><?= e(t('common.save')) ?></button>
  </form>

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

  <form method="post" action="/domains/<?= $id ?>/php" class="card space-y-3">
    <?= csrf_field() ?><input type="hidden" name="ext_form" value="1">
    <input type="hidden" name="php" value="<?= e($d['php_version']) ?>"><input type="hidden" name="opcache" value="<?= $d['opcache_enabled'] ? 1 : '' ?>"><input type="hidden" name="opcache_memory" value="<?= (int)$d['opcache_memory'] ?>">
    <h2 class="h2"><?= e(t('domains.extensions')) ?></h2>
    <div class="grid max-h-56 grid-cols-2 gap-1 overflow-y-auto text-sm sm:grid-cols-3">
      <?php foreach ($ext['available'] as $x): ?><label class="flex items-center gap-2"><input type="checkbox" name="ext[]" value="<?= e($x) ?>" <?= in_array($x, $enabled, true) ? 'checked' : '' ?>> <?= e($x) ?></label><?php endforeach; ?>
    </div>
    <button class="btn"><?= e(t('common.save')) ?></button>
  </form>
</div>

<?php
$iniDefs = Havoc\Ctl\PhpIni::USER;
$isAdmin = Havoc\Core\Auth::user()['role'] === 'admin';
$snippets = [
    'WordPress' => "location / {\n    try_files \$uri \$uri/ /index.php?\$args;\n}\nlocation = /xmlrpc.php { deny all; }\nlocation ~* /wp-content/uploads/.*\\.php\$ { deny all; }",
    'Laravel' => "location / {\n    try_files \$uri \$uri/ /index.php?\$query_string;\n}",
    t('nginx.sn_cache') => "location ~* \\.(css|js|png|jpe?g|gif|webp|avif|svg|ico|woff2?)\$ {\n    expires 30d;\n    add_header Cache-Control \"public\";\n    access_log off;\n}",
    t('nginx.sn_hidden') => "location ~ /\\.(git|env|svn|hg) { deny all; }\nlocation ~* \\.(sql|bak|log|ini)\$ { deny all; }",
    t('nginx.sn_www') => "if (\$host !~* ^www\\.) {\n    return 301 \$scheme://www.\$host\$request_uri;\n}",
    t('nginx.sn_nowww') => "if (\$host ~* ^www\\.(.+)\$) {\n    return 301 \$scheme://\$1\$request_uri;\n}",
];
?>
<div class="mt-6 grid gap-6">
  <form method="post" action="/domains/<?= $id ?>/php-ini" class="card space-y-4" id="php-ini"><?= csrf_field() ?>
    <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="h2"><?= e(t('phpini.title')) ?> <span class="text-sm font-normal text-slate-400">PHP <?= e($d['php_version']) ?></span></h2>
      <button class="btn"><?= e(t('common.save')) ?></button></div>
    <p class="text-xs text-slate-500"><?= e(t('phpini.hint')) ?></p>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <?php foreach ($iniDefs as $k => [$type, $x]): $name = 'ini_' . str_replace('.', '__', $k); $val = (string)($ini[$k] ?? ''); ?>
        <label class="block"><span class="label font-mono text-xs"><?= e($k) ?></span>
        <?php if ($type === 'bool' || $type === 'enum'): $opts = $type === 'bool' ? ['On', 'Off'] : $x; ?>
          <select class="input" name="<?= e($name) ?>"><option value=""><?= e(t('phpini.default')) ?></option>
            <?php foreach ($opts as $o): ?><option value="<?= e($o) ?>" <?= $val === $o ? 'selected' : '' ?>><?= e($o === '' ? '(empty)' : $o) ?></option><?php endforeach; ?></select>
        <?php else: ?>
          <input class="input" name="<?= e($name) ?>" value="<?= e($val) ?>" placeholder="<?= e(t('phpini.default')) ?>" <?= $type === 'tz' ? 'list="tz-list"' : '' ?>>
        <?php endif; ?></label>
      <?php endforeach; ?>
    </div>
    <datalist id="tz-list"><?php foreach (DateTimeZone::listIdentifiers() as $tz): ?><option value="<?= e($tz) ?>"></option><?php endforeach; ?></datalist>
    <?php if ($isAdmin): ?>
      <label class="block"><span class="label"><?= e(t('phpini.admin_extra')) ?></span>
        <textarea class="input font-mono text-xs" name="ini_admin" rows="4" spellcheck="false" placeholder="opcache.jit = tracing&#10;max_input_nesting_level = 128"><?= e((string)($d['php_ini_admin'] ?? '')) ?></textarea></label>
    <?php endif; ?>
  </form>

  <form method="post" action="/domains/<?= $id ?>/nginx" class="card space-y-3" id="nginx-rules"><?= csrf_field() ?>
    <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="h2"><?= e(t('nginx.title')) ?></h2><button class="btn"><?= e(t('common.save')) ?></button></div>
    <p class="text-xs text-slate-500"><?= e(t($isAdmin ? 'nginx.hint_admin' : 'nginx.hint_user')) ?></p>
    <?php $wsActive = Havoc\Services\WebServerService::active(); if ($wsActive !== 'nginx'): ?>
      <p class="rounded-lg bg-amber-100 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200"><?= e(t('nginx.inactive', ['name' => Havoc\Services\WebServerService::NAMES[$wsActive]])) ?></p>
    <?php endif; ?>
    <div class="flex flex-wrap gap-1.5">
      <span class="text-xs text-slate-500"><?= e(t('nginx.insert')) ?>:</span>
      <?php foreach ($snippets as $label => $code): ?><button type="button" class="rounded-full border border-slate-300 px-2.5 py-0.5 text-xs hover:border-brand hover:text-brand dark:border-slate-700" data-snippet="<?= e($code) ?>" data-snippet-target="rules-<?= $id ?>"><?= e($label) ?></button><?php endforeach; ?>
    </div>
    <textarea class="input font-mono text-xs leading-5" name="rules" id="rules-<?= $id ?>" rows="14" spellcheck="false" placeholder="# rewrite ^/old$ /new permanent;"><?= e($rules) ?></textarea>
  </form>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-2">
  <div class="card space-y-3">
    <h2 class="h2"><?= e(t('domains.aliases')) ?></h2>
    <?php foreach ($aliases as $a): ?>
      <form method="post" action="/domains/<?= (int)$a['id'] ?>/delete" class="flex items-center justify-between text-sm" data-confirm="<?= e(t('common.confirm')) ?>"><?= csrf_field() ?>
        <span><?= e($a['name']) ?></span><button class="btn-ghost text-xs text-red-600"><?= e(t('common.delete')) ?></button></form>
    <?php endforeach; ?>
    <?php if (!$aliases): ?><p class="text-sm text-slate-400"><?= e(t('common.empty')) ?></p><?php endif; ?>
    <form method="post" action="/domains" class="flex gap-2"><?= csrf_field() ?>
      <input type="hidden" name="type" value="alias"><input type="hidden" name="parent" value="<?= $id ?>"><input type="hidden" name="owner" value="<?= (int)$d['user_id'] ?>"><input type="hidden" name="php" value="<?= e($d['php_version']) ?>">
      <input class="input" name="name" placeholder="alias.com" required><button class="btn"><?= e(t('common.add')) ?></button></form>
  </div>
  <div class="card space-y-3">
    <h2 class="h2"><?= e(t('domains.redirects')) ?></h2>
    <?php foreach ($redirects as $r): ?>
      <form method="post" action="/domains/<?= $id ?>/redirects/<?= (int)$r['id'] ?>/delete" class="flex items-center justify-between gap-2 text-sm"><?= csrf_field() ?>
        <span class="min-w-0 break-all"><code><?= e($r['source']) ?></code> → <code><?= e($r['target']) ?></code> <span class="text-slate-400"><?= (int)$r['code'] ?></span></span>
        <button class="btn-ghost text-xs text-red-600"><?= e(t('common.delete')) ?></button></form>
    <?php endforeach; ?>
    <?php if (!$redirects): ?><p class="text-sm text-slate-400"><?= e(t('common.empty')) ?></p><?php endif; ?>
    <form method="post" action="/domains/<?= $id ?>/redirects" class="grid gap-2 sm:grid-cols-5"><?= csrf_field() ?>
      <input class="input sm:col-span-2" name="source" placeholder="/old, /dir/* or /" required>
      <input class="input sm:col-span-2" name="target" placeholder="https://example.com/new" required>
      <select class="input" name="code"><?php foreach ([301, 302, 307, 308] as $c): ?><option><?= $c ?></option><?php endforeach; ?></select>
      <button class="btn sm:col-span-5"><?= e(t('common.add')) ?></button></form>
    <p class="text-xs text-slate-500"><?= e(t('domains.redirect_hint')) ?></p>
  </div>
</div>
<form method="post" action="/domains/<?= $id ?>/delete" class="mt-8" data-confirm="<?= e(t('common.confirm')) ?>"><?= csrf_field() ?>
  <label class="mr-3 text-sm"><input type="checkbox" name="purge" value="1"> <?= e(t('domains.purge')) ?></label>
  <button class="btn-danger"><?= e(t('common.delete')) ?></button></form>
