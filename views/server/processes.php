<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
  <h1 class="h1 mb-0"><?= e(t('proc.title')) ?></h1>
  <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300"><input type="checkbox" id="proc-auto" checked> <?= e(t('proc.auto')) ?></label>
</div>
<div id="proc" data-url="/server/processes.json" data-signal="/server/processes/signal"
     data-confirm-term="<?= e(t('proc.confirm_term')) ?>" data-confirm-kill="<?= e(t('proc.confirm_kill')) ?>"
     data-end="<?= e(t('proc.end')) ?>" data-force="<?= e(t('proc.force')) ?>" data-none="<?= e(t('proc.none')) ?>" data-updated="<?= e(t('proc.updated')) ?>">
  <div class="mb-4 grid gap-4 sm:grid-cols-4">
    <div class="card"><p class="text-sm text-slate-500"><?= e(t('proc.count')) ?></p><p class="mt-1 text-2xl font-bold" data-sum="count">–</p></div>
    <div class="card"><p class="text-sm text-slate-500"><?= e(t('proc.cpu_total')) ?></p><p class="mt-1 text-2xl font-bold" data-sum="cpu">–</p></div>
    <div class="card"><p class="text-sm text-slate-500"><?= e(t('proc.mem_total')) ?></p><p class="mt-1 text-2xl font-bold" data-sum="mem">–</p></div>
    <div class="card"><p class="text-sm text-slate-500"><?= e(t('proc.busiest')) ?></p><p class="mt-1 truncate text-lg font-bold" data-sum="top">–</p></div>
  </div>
  <div class="card mb-4 overflow-x-auto p-0">
    <table class="table"><thead><tr><th><?= e(t('proc.user')) ?></th><th class="text-right"><?= e(t('proc.procs')) ?></th><th class="text-right">CPU</th><th class="text-right"><?= e(t('proc.memory')) ?></th></tr></thead>
      <tbody data-users></tbody></table>
  </div>
  <div class="card p-0">
    <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 p-3 dark:border-slate-700">
      <input type="search" class="input max-w-xs" data-q placeholder="<?= e(t('proc.search')) ?>">
      <select class="input max-w-[14rem]" data-user><option value=""><?= e(t('proc.all_users')) ?></option></select>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" data-kernel> <?= e(t('proc.kernel')) ?></label>
      <span class="ml-auto text-xs text-slate-400" data-status></span>
    </div>
    <div class="overflow-x-auto">
      <table class="table text-sm">
        <thead><tr>
          <th><button data-sort="pid">PID</button></th><th><button data-sort="user"><?= e(t('proc.user')) ?></button></th>
          <th class="text-right"><button data-sort="cpu">CPU %</button></th><th class="text-right"><button data-sort="mem">MEM %</button></th>
          <th class="text-right"><button data-sort="rss">RSS</button></th><th class="text-right"><button data-sort="time"><?= e(t('proc.runtime')) ?></button></th>
          <th><?= e(t('proc.state')) ?></th><th><button data-sort="cmd"><?= e(t('proc.command')) ?></button></th><th></th>
        </tr></thead>
        <tbody data-rows></tbody>
      </table>
    </div>
  </div>
  <p class="mt-3 text-xs text-slate-500"><?= e(t('proc.hint')) ?></p>
</div>
<script src="<?= e(asset('processes.js')) ?>" defer></script>
