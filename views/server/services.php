<h1 class="h1"><?= e(t('nav.services')) ?></h1>
<div class="card overflow-x-auto p-0"><table class="table">
  <thead><tr><th><?= e(t('common.name')) ?></th><th><?= e(t('common.status')) ?></th><th>Boot</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($services as $s): ?>
    <tr><td class="font-medium"><?= e($s['name']) ?></td>
      <td><span class="badge <?= $s['active'] === 'active' ? 'badge-ok' : 'badge-warn' ?>"><?= e($s['active']) ?></span></td>
      <td><?= e($s['enabled']) ?></td>
      <td class="space-x-1 whitespace-nowrap text-right">
        <?php foreach (($s['protected'] ? ['restart'] : ['start', 'stop', 'restart']) as $op): ?>
          <form method="post" action="/services/<?= e($s['name']) ?>/<?= $op ?>" class="inline"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 text-xs dark:border-slate-700"><?= e(t('svc.' . $op)) ?></button></form>
        <?php endforeach; ?>
      </td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
