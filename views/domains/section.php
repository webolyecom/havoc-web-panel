<?php
require __DIR__ . '/../_nav.php';        // $navIcon
require __DIR__ . '/_sections.php';      // $siteSections, $siteHref
$id = (int)$d['id'];
[$icon, $titleKey] = $siteSections[$section];
?>
<div class="mb-5">
  <a href="/domains/<?= $id ?>" class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-brand"><?= $navIcon('arrow-left') ?> <?= e($d['name']) ?></a>
  <div class="mt-2 flex flex-wrap items-center gap-3">
    <span class="flex h-10 w-10 items-center justify-center rounded-xl <?= $section === 'delete' ? 'bg-red-500/10 text-red-600' : 'bg-brand/10 text-brand' ?>"><?= $navIcon($icon) ?></span>
    <h1 class="h1 mb-0"><?= e(t($titleKey)) ?></h1>
  </div>
</div>
<nav class="mb-6 flex gap-1.5 overflow-x-auto pb-1 text-sm lg:flex-wrap lg:overflow-visible" aria-label="<?= e($d['name']) ?>">
  <?php foreach ($siteSections as $k => [$ic, $tk]): if ($k === 'search' && !$searchOn) continue; ?>
    <a href="<?= e($siteHref($d, $k)) ?>" class="flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1 <?= $k === $section ? 'border-brand bg-brand/10 text-brand' : 'border-slate-200 text-slate-600 hover:border-brand/50 hover:text-brand dark:border-slate-700 dark:text-slate-300' ?>"<?= $k === $section ? ' aria-current="page"' : '' ?>><?= $navIcon($ic) ?><span class="whitespace-nowrap"><?= e(t($tk)) ?></span></a>
  <?php endforeach; ?>
</nav>
<?php include __DIR__ . '/sections/' . $section . '.php'; ?>
