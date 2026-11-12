<?php
/** Schedule builder. Expects $bValue (cron expression) and $presets. Works without JS (plain expression field). */
$bValue = $bValue ?? '0 3 * * *';
?>
<div class="space-y-3" data-cron-builder>
  <div class="flex flex-wrap gap-1.5">
    <?php foreach ($presets as $expr => $key): ?>
      <button type="button" class="rounded-full border border-slate-300 px-2.5 py-1 text-xs hover:border-brand hover:text-brand dark:border-slate-700" data-cron-preset="<?= e($expr) ?>"><?= e(t($key)) ?></button>
    <?php endforeach; ?>
  </div>
  <div class="hidden grid-cols-2 gap-2 sm:grid-cols-6" data-cron-fields>
    <div class="col-span-2"><label class="label"><?= e(t('cron.frequency')) ?></label>
      <select class="input" data-f="mode">
        <option value="minutes"><?= e(t('cron.m_minutes')) ?></option><option value="hours"><?= e(t('cron.m_hours')) ?></option>
        <option value="day"><?= e(t('cron.m_day')) ?></option><option value="week"><?= e(t('cron.m_week')) ?></option>
        <option value="month"><?= e(t('cron.m_month')) ?></option><option value="custom"><?= e(t('cron.m_custom')) ?></option>
      </select></div>
    <div data-show="minutes"><label class="label"><?= e(t('cron.every')) ?></label>
      <select class="input" data-f="nmin"><?php foreach ([1, 2, 3, 5, 10, 15, 20, 30] as $n): ?><option value="<?= $n ?>"><?= e(t('cron.n_minutes', ['n' => $n])) ?></option><?php endforeach; ?></select></div>
    <div data-show="hours"><label class="label"><?= e(t('cron.every')) ?></label>
      <select class="input" data-f="nhour"><?php foreach ([1, 2, 3, 4, 6, 8, 12] as $n): ?><option value="<?= $n ?>"><?= e(t('cron.n_hours', ['n' => $n])) ?></option><?php endforeach; ?></select></div>
    <div data-show="hours"><label class="label"><?= e(t('cron.at_minute')) ?></label><input class="input" type="number" min="0" max="59" value="0" data-f="minute"></div>
    <div data-show="week" class="col-span-2 sm:col-span-6"><label class="label"><?= e(t('cron.on_days')) ?></label>
      <div class="flex flex-wrap gap-3 text-sm"><?php foreach ([1, 2, 3, 4, 5, 6, 0] as $d): ?><label class="flex items-center gap-1"><input type="checkbox" value="<?= $d ?>" data-f="dow" <?= $d === 1 ? 'checked' : '' ?>> <?= e(t('cron.dow_' . $d)) ?></label><?php endforeach; ?></div></div>
    <div data-show="month"><label class="label"><?= e(t('cron.on_day')) ?></label>
      <select class="input" data-f="dom"><?php for ($i = 1; $i <= 31; $i++): ?><option><?= $i ?></option><?php endfor; ?></select></div>
    <div data-show="day week month"><label class="label"><?= e(t('cron.at_time')) ?></label><input class="input" type="time" value="03:00" data-f="time"></div>
  </div>
  <div><label class="label"><?= e(t('cron.schedule')) ?> <span class="font-normal text-slate-400">(<?= e(t('cron.expr_hint')) ?>)</span></label>
    <input class="input font-mono" name="schedule" value="<?= e($bValue) ?>" required maxlength="120" data-f="expr" autocomplete="off"></div>
  <p class="text-xs text-slate-500" data-cron-preview></p>
</div>
