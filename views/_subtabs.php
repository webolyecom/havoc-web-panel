<?php
/**
 * Tabs above pages that share one menu entry. Set $tabSet ('web' | 'mariadb') and $tabCur (href of this page) before including.
 */
$tabSets = [
    'web' => [['/server/webserver', 'nav.t_ws_server'], ['/server/webserver/settings', 'nav.t_ws_perf']],
    'mariadb' => [['/databases/tuning', 'nav.t_db_settings'], ['/server/mysql', 'nav.t_db_live']],
];
?>
<nav class="mb-5 flex gap-1 overflow-x-auto border-b border-slate-200 text-sm dark:border-slate-800" aria-label="<?= e(t($tabSet === 'web' ? 'nav.webserver' : 'nav.mariadb')) ?>">
  <?php foreach ($tabSets[$tabSet] as [$href, $key]): $on = $href === $tabCur; ?>
    <a href="<?= e($href) ?>" class="-mb-px shrink-0 whitespace-nowrap border-b-2 px-3 py-2 font-medium <?= $on ? 'border-brand text-brand' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800 dark:hover:text-slate-200' ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= e(t($key)) ?></a>
  <?php endforeach; ?>
</nav>
