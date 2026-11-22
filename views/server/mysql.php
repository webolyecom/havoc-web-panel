<h1 class="h1">MariaDB</h1>
<?php $tabSet = 'mariadb'; $tabCur = '/server/mysql'; include __DIR__ . '/../_subtabs.php'; ?>
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
  <div><h2 class="h2 mb-0"><?= e(t('dbproc.title')) ?></h2><p class="mt-1 text-sm text-slate-500"><?= e(t('dbproc.intro')) ?></p></div>
  <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300"><input type="checkbox" id="dbp-auto" checked> <?= e(t('dbproc.auto')) ?></label>
</div>
<div id="dbp" data-url="/server/mysql.json" data-kill="/server/mysql/kill"
     data-t-stop="<?= e(t('dbproc.stop')) ?>" data-t-close="<?= e(t('dbproc.close')) ?>" data-t-none="<?= e(t('dbproc.none')) ?>"
     data-t-updated="<?= e(t('dbproc.updated')) ?>" data-t-system="<?= e(t('dbproc.system')) ?>" data-t-idle="<?= e(t('dbproc.idle_n')) ?>"
     data-c-stop="<?= e(t('dbproc.confirm_stop')) ?>" data-c-close="<?= e(t('dbproc.confirm_close')) ?>" data-c-sel="<?= e(t('dbproc.confirm_selected')) ?>"
     data-c-long="<?= e(t('dbproc.confirm_long')) ?>" data-c-sleep="<?= e(t('dbproc.confirm_sleep')) ?>">
  <div class="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <div class="card"><p class="text-sm text-slate-500"><?= e(t('dbproc.connections')) ?></p><p class="mt-1 text-2xl font-bold" data-sum="conn">–</p><p class="text-xs text-slate-500" data-sum="conn_sub"></p></div>
    <div class="card"><p class="text-sm text-slate-500"><?= e(t('dbproc.running')) ?></p><p class="mt-1 text-2xl font-bold" data-sum="running">–</p><p class="text-xs text-slate-500" data-sum="running_sub"></p></div>
    <div class="card"><p class="text-sm text-slate-500"><?= e(t('dbproc.longest')) ?></p><p class="mt-1 text-2xl font-bold" data-sum="longest">–</p><p class="truncate text-xs text-slate-500" data-sum="longest_sub"></p></div>
    <div class="card"><p class="text-sm text-slate-500"><?= e(t('dbproc.slow')) ?></p><p class="mt-1 text-2xl font-bold" data-sum="slow">–</p><p class="text-xs text-slate-500" data-sum="slow_sub"></p></div>
  </div>

  <div class="card mb-4 space-y-3">
    <h2 class="h2 mb-0"><?= e(t('dbproc.bulk')) ?></h2>
    <div class="flex flex-wrap items-center gap-2 text-sm">
      <span><?= e(t('dbproc.bulk_long_a')) ?></span>
      <input type="number" class="input w-24" min="1" max="86400" value="60" data-long-sec>
      <span><?= e(t('dbproc.bulk_long_b')) ?></span>
      <select class="input max-w-[14rem]" data-long-db><option value=""><?= e(t('dbproc.all_dbs')) ?></option></select>
      <button type="button" class="btn-danger" data-bulk="long"><?= e(t('dbproc.stop_them')) ?></button>
    </div>
    <div class="flex flex-wrap items-center gap-2 text-sm">
      <span><?= e(t('dbproc.bulk_sleep_a')) ?></span>
      <input type="number" class="input w-24" min="1" max="86400" value="300" data-sleep-sec>
      <span><?= e(t('dbproc.bulk_sleep_b')) ?></span>
      <button type="button" class="btn-ghost border border-slate-300 dark:border-slate-700" data-bulk="sleep"><?= e(t('dbproc.close_them')) ?></button>
    </div>
    <p class="text-xs text-slate-500"><?= e(t('dbproc.bulk_hint')) ?></p>
  </div>

  <div class="card p-0">
    <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 p-3 dark:border-slate-700">
      <input type="search" class="input max-w-xs" data-q placeholder="<?= e(t('dbproc.search')) ?>">
      <select class="input max-w-[14rem]" data-db><option value=""><?= e(t('dbproc.all_dbs')) ?></option></select>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" data-show-idle> <?= e(t('dbproc.show_idle')) ?></label>
      <button type="button" class="btn-danger hidden" data-kill-selected><?= e(t('dbproc.stop_selected')) ?></button>
      <span class="ml-auto text-xs text-slate-400" data-status></span>
    </div>
    <div class="overflow-x-auto">
      <table class="table text-sm">
        <thead><tr>
          <th class="w-8"><input type="checkbox" data-all aria-label="<?= e(t('dbproc.select_all')) ?>"></th>
          <th>ID</th><th><?= e(t('dbproc.user')) ?></th><th><?= e(t('dbproc.db')) ?></th><th><?= e(t('dbproc.command')) ?></th>
          <th class="text-right"><?= e(t('dbproc.time')) ?></th><th><?= e(t('dbproc.state')) ?></th><th class="min-w-[22rem]"><?= e(t('dbproc.query')) ?></th><th></th>
        </tr></thead>
        <tbody data-rows></tbody>
      </table>
    </div>
  </div>
  <p class="mt-3 text-xs text-slate-500"><?= e(t('dbproc.hint')) ?></p>
</div>
<script src="<?= e(asset('mysql.js')) ?>" defer></script>
