<?php $id = (int)$d['id']; ?>
<?php
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
