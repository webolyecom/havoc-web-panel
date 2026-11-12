<?php
use Havoc\Core\{Branding, I18n, Request};
$brand = Branding::resolve(new Request(), null);
?>
<!doctype html>
<html lang="<?= e(I18n::locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($brand['name']) ?></title>
<script nonce="<?= nonce() ?>">(function(){var l=localStorage.getItem('havoc_theme')||'system';if(l==='dark'||(l==='system'&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.classList.add('dark')})()</script>
<style nonce="<?= nonce() ?>">:root{--brand:<?= e($brand['rgb']) ?>}</style>
<link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<script src="<?= e(asset('app.js')) ?>" defer></script>
</head>
<body class="flex min-h-screen items-center justify-center bg-[#f4f6fb] p-4 dark:bg-ink-950">
<div class="w-full max-w-sm">
  <div class="mb-6 text-center">
    <?php if ($brand['logo']): ?><img src="/brand/<?= (int)$brand['rid'] ?>/logo" alt="" class="h-12 w-12 rounded-lg object-contain"><?php else: ?><span class="inline-flex h-12 w-12 items-center justify-center rounded-xl brand-mark !h-12 !w-12 text-2xl">H</span><?php endif; ?>
    <h1 class="mt-3 text-xl font-bold text-slate-800 dark:text-white"><?= e($brand['name']) ?></h1>
  </div>
  <?php foreach (Havoc\Core\Session::pullFlash() as [$type, $msg]): ?>
    <div class="mb-4 rounded-lg bg-red-100 px-4 py-3 text-sm text-red-800 dark:bg-red-950 dark:text-red-200"><?= e($msg) ?></div>
  <?php endforeach; ?>
  <div class="card"><?= $content ?></div>
  <div class="mt-4 flex justify-center gap-3 text-xs text-slate-500">
    <?php foreach (I18n::SUPPORTED as $code => $name): ?><a href="/lang/<?= e($code) ?>" class="hover:underline"><?= e($name) ?></a><?php endforeach; ?>
  </div>
</div>
</body>
</html>
