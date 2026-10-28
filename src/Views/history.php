<?php
defined('RECUPBUILD') || (http_response_code(404) && exit);
/**
 * GET /history: every plan and every rebuilt object of the user, newest first. Deleting
 * one is confirmed in a dialog first.
 *
 * @var callable $t @var callable $e @var string $locale @var list<array> $plans @var list<array> $rebuilds @var string $csrf
 */
$words = static fn (string $key, string $fallback): string => ($text = html_entity_decode($t($key), ENT_QUOTES, 'UTF-8')) === $key ? $fallback : $text;
$date = static fn (string $iso): string => date($locale === 'en' ? 'M j, Y · H:i' : 'd/m/Y · H:i', strtotime($iso) ?: time());
/** A delete button and its confirmation dialog. */
$deleter = static function (string $kind, int $id, string $name) use ($t, $e, $csrf): string {
    $dialog = $kind . '-' . $id;
    $title = $kind === 'plan' ? $t('profile.deleteTitle') : $t('history.deleteRebuildTitle');
    $text = $kind === 'plan' ? $t('profile.deleteText', ['name' => $name]) : $t('history.deleteRebuildText', ['name' => $name]);
    $action = $kind === 'plan' ? '/profile/plans/' . $id . '/delete' : '/profile/rebuilds/' . $id . '/delete';

    return '<button class="button small ghost danger" type="button" data-confirm="' . $dialog . '">' . $t('profile.delete') . '</button>'
        . '<dialog class="confirm" id="' . $dialog . '" aria-labelledby="' . $dialog . '-title"><form method="post" action="' . $action . '">'
        . '<input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="back" value="/history">'
        . '<span class="confirm-icon danger" aria-hidden="true">!</span><h3 id="' . $dialog . '-title">' . $title . '</h3><p>' . $text . '</p>'
        . '<div class="confirm-actions"><button class="button ghost" type="button" data-close>' . $t('dialog.cancel') . '</button>'
        . '<button class="button danger-fill" type="submit">' . $t('profile.deleteConfirm') . '</button></div></form></dialog>';
};
?>
<section class="wrap app-head" aria-labelledby="history-title">
  <h1 id="history-title"><?= $t('history.title') ?></h1>
  <p class="lead left"><?= $t('history.lead') ?></p>
</section>

<div class="wrap history">
  <section class="card" aria-labelledby="history-plans">
    <h2 id="history-plans"><?= $t('history.plans') ?></h2>
    <?php if ($plans === []): ?>
      <p class="empty"><?= $t('history.empty') ?></p>
      <a class="button" href="/scan"><?= $t('nav.scan') ?></a>
    <?php else: ?>
      <ul class="plan-list">
        <?php foreach ($plans as $plan): ?>
          <?php $name = $plan['project_name'] ?? $words('project.' . $plan['project_id'], $plan['project_id']); ?>
          <li>
            <?php if (!empty($plan['assembly'])): ?>
              <!-- The finished object, still: a small three-quarter picture of the plan. -->
              <figure class="thumb" aria-hidden="true"><?= \RecupBuild\Services\Drawing::isometric($plan['assembly'], $plan['sizes']) ?></figure>
            <?php endif ?>
            <div class="plan-info">
              <a class="plan-name" href="/plans/<?= (int) $plan['id'] ?>"><?= $e($name) ?></a>
              <p class="muted small"><?= $e($date($plan['created_at'])) ?> · <?= $t('profile.parts', ['count' => $plan['parts']]) ?></p>
            </div>
            <div class="plan-actions">
              <a class="button small" href="/plans/<?= (int) $plan['id'] ?>"><?= $t('profile.open') ?></a>
              <?= $deleter('plan', (int) $plan['id'], $name) ?>
            </div>
          </li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>
  </section>

  <section class="card" aria-labelledby="history-rebuilds">
    <h2 id="history-rebuilds"><?= $t('history.rebuilds') ?></h2>
    <?php if ($rebuilds === []): ?>
      <p class="empty"><?= $t('history.empty') ?></p>
      <a class="button" href="/rebuild"><?= $t('nav.rebuild') ?></a>
    <?php else: ?>
      <ul class="plan-list">
        <?php foreach ($rebuilds as $rebuild): ?>
          <li>
            <a class="thumb" href="/rebuilds/<?= (int) $rebuild['id'] ?>"><img src="/rebuilds/<?= (int) $rebuild['id'] ?>/photo" alt="" width="72" height="54" loading="lazy"></a>
            <div class="plan-info">
              <a class="plan-name" href="/rebuilds/<?= (int) $rebuild['id'] ?>"><?= $e($rebuild['name']) ?></a>
              <p class="muted small"><?= $e($date($rebuild['created_at'])) ?></p>
            </div>
            <div class="plan-actions">
              <a class="button small" href="/rebuilds/<?= (int) $rebuild['id'] ?>"><?= $t('profile.open') ?></a>
              <?= $deleter('rebuild', (int) $rebuild['id'], $rebuild['name']) ?>
            </div>
          </li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>
  </section>
</div>
