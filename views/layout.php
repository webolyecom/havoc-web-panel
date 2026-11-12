<?php
use Havoc\Core\{Auth, Branding, I18n, Request};
$u = Auth::user();
$brand = Branding::resolve(new Request(), $u);
$path = (new Request())->path;
$theme = $u['theme'] ?? 'system';
require __DIR__ . '/_nav.php';
// pages that live under a domain belong to their own menu item
$navPath = preg_replace(['#^/domains/\d+/files(/.*)?$#', '#^/domains/\d+/logs(/.*)?$#'], ['/files', '/logs'], $path);
$pmaInstalled = is_file('/opt/havoc-pma/index.php');
$rank = ['user' => 0, 'reseller' => 1, 'admin' => 2];
?>
<!doctype html>
<html lang="<?= e(I18n::locale()) ?>" data-theme="<?= e($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= e(Havoc\Core\Csrf::token()) ?>">
<meta name="csp-nonce" content="<?= nonce() ?>">
<title><?= e($brand['name']) ?></title>
<script nonce="<?= nonce() ?>">(function(){var t=document.documentElement.dataset.theme,l=localStorage.getItem('havoc_theme')||t;if(l==='dark'||(l==='system'&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.classList.add('dark');try{if(localStorage.getItem('havoc_nav_compact')==='1')document.documentElement.classList.add('nav-compact')}catch(e){}})()</script>
<style nonce="<?= nonce() ?>">:root{--brand:<?= e($brand['rgb']) ?>}</style>
<link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<script src="<?= e(asset('app.js')) ?>" defer></script>
</head>
<body class="min-h-screen bg-[#f4f6fb] text-slate-800 dark:bg-ink-950 dark:text-slate-200">
<div class="flex min-h-screen">
  <div class="fixed inset-0 z-30 hidden bg-ink-950/60 backdrop-blur-sm md:hidden" id="sidebar-backdrop"></div>
  <aside class="sidebar fixed inset-y-0 left-0 z-40 flex w-[264px] -translate-x-full flex-col transition-transform duration-200 md:sticky md:top-0 md:h-screen md:translate-x-0" id="sidebar">
    <div class="flex items-center gap-3 px-4 pb-4 pt-5">
      <?php if ($brand['logo']): ?><img src="/brand/<?= (int)$brand['rid'] ?>/logo" alt="" class="h-9 w-9 shrink-0 rounded-xl bg-white/5 object-contain p-0.5"><?php else: ?><span class="brand-mark">H</span><?php endif; ?>
      <div class="nav-label min-w-0 leading-tight">
        <div class="truncate text-[15px] font-semibold text-white"><?= e($brand['name']) ?></div>
        <div class="text-[11px] text-ink-400">v<?= e((string)Havoc\Core\Config::get('app.version')) ?></div>
      </div>
      <button class="nav-label ml-auto rounded-md p-1.5 text-ink-400 hover:bg-white/5 hover:text-white md:hidden" id="menu-close" aria-label="<?= e(t('common.close')) ?>"><svg viewBox="0 0 20 20" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button>
    </div>
    <div class="nav-label px-3 pb-3">
      <label class="nav-search">
        <svg viewBox="0 0 20 20" class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M9 15A6 6 0 1 0 9 3a6 6 0 0 0 0 12Zm4.5-1.5L17 17"/></svg>
        <input type="search" id="nav-filter" placeholder="<?= e(t('nav.search')) ?>" autocomplete="off" class="min-w-0 flex-1 bg-transparent text-[13px] text-white placeholder:text-ink-400 focus:outline-none">
        <kbd class="hidden rounded border border-white/10 px-1.5 text-[10px] text-ink-400 md:inline">Ctrl K</kbd>
      </label>
    </div>
    <nav class="nav-scroll flex-1 overflow-y-auto px-3 pb-3" id="side-nav">
      <?php foreach ($navGroups as [$gid, $glabel, $items]):
        $vis = array_values(array_filter($items, fn($i) => $u && $rank[$u['role']] >= $rank[$i[3]] && ($i[0] !== '/phpmyadmin/' || $pmaInstalled)));
        if (!$vis) continue;
        $isOn = fn($href) => $href === '/' ? $navPath === '/' : ($navPath === rtrim($href, '/') || str_starts_with($navPath, rtrim($href, '/') . '/'));
        // the most specific matching item wins (e.g. /databases/tuning over /databases)
        $best = null;
        foreach ($vis as $i) { if ($isOn($i[0]) && ($best === null || strlen($i[0]) > strlen($best))) $best = $i[0]; }
        $links = '';
        foreach ($vis as $i) {
            $label = t($i[1]);
            $links .= '<a href="' . e($i[0]) . '" class="nav-link' . ($i[0] === $best ? ' nav-active" aria-current="page' : '') . '" title="' . e($label) . '"' . (!empty($i[4]) ? ' target="_blank" rel="noopener"' : '') . '>'
                . '<span class="nav-ico">' . $navIcon($i[2]) . '</span><span class="nav-label truncate">' . e($label) . '</span>'
                . (!empty($i[4]) ? '<svg viewBox="0 0 20 20" class="nav-label ml-auto h-3.5 w-3.5 text-ink-500" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M8 5h7v7M15 5l-9 9"/></svg>' : '') . '</a>';
        }
        if ($glabel === null): echo '<div class="nav-items">' . $links . '</div>'; else: ?>
        <details class="nav-group group mt-4" data-nav-group="<?= e($gid) ?>" <?= $best !== null ? 'open data-active' : 'open' ?>>
          <summary class="nav-summary">
            <span class="nav-label"><?= e(t($glabel)) ?></span><svg viewBox="0 0 20 20" class="nav-label h-3 w-3 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="m8 5 5 5-5 5"/></svg>
          </summary>
          <div class="nav-items mt-1"><?= $links ?></div>
        </details>
      <?php endif; endforeach; ?>
      <p class="nav-label hidden px-3 py-6 text-center text-xs text-ink-400" id="nav-empty"><?= e(t('nav.no_match')) ?></p>
    </nav>
    <?php if ($u): ?>
    <div class="border-t border-white/[0.06] p-3">
      <div class="flex items-center gap-2.5 rounded-xl px-2 py-2">
        <a href="/settings" class="flex min-w-0 flex-1 items-center gap-2.5 rounded-lg <?= str_starts_with($path, '/settings') ? 'text-white' : '' ?>" title="<?= e(t('nav.settings')) ?>">
          <span class="avatar"><?= e(strtoupper(mb_substr((string)$u['username'], 0, 1))) ?></span>
          <span class="nav-label min-w-0 leading-tight">
            <span class="block truncate text-[13px] font-medium text-white"><?= e($u['username']) ?></span>
            <span class="block text-[11px] text-ink-400"><?= e(t('role.' . $u['role'])) ?></span>
          </span>
        </a>
        <a href="/settings" class="nav-label rounded-lg p-2 text-ink-400 hover:bg-white/5 hover:text-white" title="<?= e(t('nav.settings')) ?>"><?= $navIcon('cog') ?></a>
        <form method="post" action="/logout" class="nav-label"><?= csrf_field() ?><button class="rounded-lg p-2 text-ink-400 hover:bg-white/5 hover:text-rose-300" title="<?= e(t('auth.logout')) ?>"><?= $navIcon('logout') ?></button></form>
      </div>
      <button class="nav-link mt-1 hidden w-full md:flex" id="nav-compact" title="<?= e(t('nav.collapse')) ?>" data-collapse="<?= e(t('nav.collapse')) ?>" data-expand="<?= e(t('nav.expand')) ?>"><span class="nav-ico"><?= $navIcon('panel') ?></span><span class="nav-label"><?= e(t('nav.collapse')) ?></span></button>
    </div>
    <?php endif; ?>
  </aside>
  <div class="flex min-w-0 flex-1 flex-col">
    <header class="sticky top-0 z-20 flex items-center gap-3 border-b border-slate-200/70 bg-white/80 px-4 py-2.5 backdrop-blur dark:border-white/[0.06] dark:bg-ink-950/80 md:px-8">
      <button class="btn-ghost -ml-2 md:hidden" id="menu-btn" aria-label="Menu"><svg viewBox="0 0 20 20" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 6h14M3 10h14M3 14h14"/></svg></button>
      <div class="ml-auto flex items-center gap-1.5">
        <div class="flex rounded-lg bg-slate-100 p-0.5 dark:bg-white/5">
        <?php foreach (I18n::SUPPORTED as $code => $name): ?>
          <a href="/lang/<?= e($code) ?>" class="rounded-md px-2 py-1 text-[11px] font-semibold <?= I18n::locale() === $code ? 'bg-white text-slate-900 shadow-sm dark:bg-ink-700 dark:text-white' : 'text-slate-500 hover:text-slate-800 dark:hover:text-white' ?>"><?= e(strtoupper($code)) ?></a>
        <?php endforeach; ?>
        </div>
        <button class="btn-ghost" id="theme-btn" title="<?= e(t('common.theme')) ?>"><svg viewBox="0 0 20 20" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M10 17a7 7 0 1 0 0-14 7 7 0 0 0 0 14Z"/><path d="M10 3a7 7 0 0 0 0 14z" fill="currentColor"/></svg></button>
      </div>
    </header>
    <main class="mx-auto w-full max-w-6xl flex-1 p-4 md:p-8">
      <?php $q = $u ? Havoc\Core\DB::one('SELECT state, ratios FROM usage_accounts WHERE user_id = ?', [$u['id']]) : null;
      if ($q && $q['state'] !== 'ok'): ?>
        <a href="/limits" class="mb-4 block rounded-lg px-4 py-3 text-sm <?= $q['state'] === 'over' ? 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200' : 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200' ?>"><?= e(t('quota.banner_' . $q['state'])) ?></a>
      <?php endif; ?>
      <?php foreach (Havoc\Core\Session::pullFlash() as [$type, $msg]): ?>
        <div class="mb-4 rounded-lg px-4 py-3 text-sm <?= $type === 'error' ? 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200' : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' ?>"><?= e($msg) ?></div>
      <?php endforeach; ?>
      <?= $content ?>
    </main>
    <footer class="px-4 py-4 text-center text-xs text-slate-400 dark:text-ink-500">Havoc WEB Panel v<?= e((string)Havoc\Core\Config::get('app.version')) ?></footer>
  </div>
</div>
</body>
</html>
