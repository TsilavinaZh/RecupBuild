<?php
defined('RECUPBUILD') || (http_response_code(404) && exit);
/**
 * GET /profile: the account, what is left of the free offer, the saved plans. Deleting
 * a plan and signing out are confirmed in a dialog first (js/pages.js).
 *
 * @var callable $t @var callable $e @var string $locale @var array $account
 * @var array{build: int, rebuild: int} $credits @var int $free @var list<array> $plans @var string $csrf
 */
$date = static function (?string $iso) use ($locale): string {
    if ($iso === null || $iso === '') {
        return '';
    }
    $time = strtotime($iso);
    if ($time === false) {
        return '';
    }
    if (class_exists(\IntlDateFormatter::class)) {
        $formatter = new \IntlDateFormatter($locale === 'en' ? 'en' : 'fr', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE);

        return (string) $formatter->format($time);
    }

    return date('d/m/Y', $time);
};
$name = (string) ($account['name'] ?? '');
$initials = implode('', array_map(static fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)), array_slice(preg_split('/\s+/', trim($name)) ?: [], 0, 2)));
$meters = ['build' => 'profile.buildsHint', 'rebuild' => 'profile.rebuildsHint'];
/** A text of the language files, unescaped (it is escaped where printed), or the fallback. */
$words = static fn (string $key, string $fallback): string => ($text = html_entity_decode($t($key), ENT_QUOTES, 'UTF-8')) === $key ? $fallback : $text;
?>
<section class="wrap profile-head" aria-labelledby="profile-title">
  <?php if (!empty($account['picture'])): ?>
    <img class="avatar big" src="<?= $e($account['picture']) ?>" alt="" width="88" height="88" referrerpolicy="no-referrer">
  <?php else: ?>
    <span class="avatar big initials" aria-hidden="true"><?= $e($initials) ?></span>
  <?php endif ?>
  <div>
    <p class="stamp"><?= $t('profile.title') ?></p>
    <h1 id="profile-title"><?= $e($name) ?></h1>
    <?php if (!empty($account['email'])): ?><p class="muted"><?= $e($account['email']) ?> · <?= $t('profile.google') ?></p><?php endif ?>
    <p class="muted small">
      <?= $t('profile.memberSince', ['date' => $date($account['created_at'] ?? null)]) ?> ·
      <?= $t('profile.lastLogin', ['date' => $date($account['last_login_at'] ?? null)]) ?>
    </p>
  </div>
</section>

<div class="wrap profile-grid">
  <!-- The free offer, as two measuring tapes running down. -->
  <section class="card" aria-labelledby="offer-title">
    <div class="card-head">
      <h2 id="offer-title"><?= $t('profile.offer') ?></h2>
      <span class="pill"><?= $t('profile.freePlan') ?></span>
    </div>
    <?php foreach ($meters as $kind => $hint): ?>
      <?php $left = max(0, min($free, $credits[$kind])); ?>
      <div class="meter">
        <div class="meter-row">
          <span class="meter-name"><?= $t($kind === 'build' ? 'profile.builds' : 'profile.rebuilds') ?></span>
          <span class="figure"><?= $t('profile.left', ['left' => $left, 'total' => $free]) ?></span>
        </div>
        <div class="tape-meter" role="meter" aria-valuemin="0" aria-valuemax="<?= $free ?>" aria-valuenow="<?= $left ?>"
             aria-label="<?= $t($kind === 'build' ? 'profile.builds' : 'profile.rebuilds') ?>">
          <span class="tape-fill" data-fill="<?= $free > 0 ? round($left / $free, 3) : 0 ?>"></span>
        </div>
        <p class="muted small"><?= $t($hint) ?></p>
      </div>
    <?php endforeach ?>
    <?php if ($credits['build'] <= 0 && $credits['rebuild'] <= 0): ?>
      <p class="notice"><?= $t('profile.empty') ?></p>
    <?php endif ?>
    <a class="button wide ghost" href="/pricing"><?= $t('profile.seePricing') ?></a>
  </section>

  <!-- The saved plans. -->
  <section class="card plans" aria-labelledby="plans-title">
    <div class="card-head">
      <h2 id="plans-title"><?= $t('profile.plans') ?></h2>
      <span class="muted small"><?= $t('profile.plansCount', ['count' => count($plans)]) ?></span>
    </div>
    <?php if ($plans === []): ?>
      <p class="empty"><?= $t('profile.noPlans') ?></p>
      <a class="button" href="/scan"><?= $t('profile.startApp') ?></a>
    <?php else: ?>
      <ul class="plan-list">
        <?php foreach ($plans as $plan): ?>
          <?php
            // Invented projects carry their own name and size labels; built-in ones are in the language files.
            $planName = $plan['project_name'] ?? $words('project.' . $plan['project_id'], $plan['project_id']);
            $labels = (array) $plan['labels'];
          ?>
          <li>
            <div class="plan-info">
              <p class="plan-name"><?= $e($planName) ?></p>
              <p class="muted small"><?= $e($date($plan['created_at'])) ?> · <?= $t('profile.parts', ['count' => $plan['parts']]) ?></p>
              <p class="figure small"><?= $e(implode(' · ', array_map(
                  static fn (string $key, int|float $value): string => ($labels[$key] ?? $words('param.' . $key, $key)) . ' ' . $value . ' mm',
                  array_keys($plan['params']),
                  $plan['params'],
              ))) ?></p>
            </div>
            <div class="plan-actions">
              <a class="button small" href="/plans/<?= (int) $plan['id'] ?>"><?= $t('profile.open') ?></a>
              <button class="button small ghost danger" type="button" data-confirm="delete-<?= (int) $plan['id'] ?>"><?= $t('profile.delete') ?></button>
            </div>
            <dialog class="confirm" id="delete-<?= (int) $plan['id'] ?>" aria-labelledby="delete-title-<?= (int) $plan['id'] ?>">
              <form method="post" action="/profile/plans/<?= (int) $plan['id'] ?>/delete">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                <span class="confirm-icon danger" aria-hidden="true">!</span>
                <h3 id="delete-title-<?= (int) $plan['id'] ?>"><?= $t('profile.deleteTitle') ?></h3>
                <p><?= $t('profile.deleteText', ['name' => $planName]) ?></p>
                <div class="confirm-actions">
                  <button class="button ghost" type="button" data-close><?= $t('dialog.cancel') ?></button>
                  <button class="button danger-fill" type="submit"><?= $t('profile.deleteConfirm') ?></button>
                </div>
              </form>
            </dialog>
          </li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>
  </section>

  <!-- The account: signing out, confirmed first. -->
  <section class="card account" aria-labelledby="account-title">
    <h2 id="account-title"><?= $t('profile.account') ?></h2>
    <button class="button ghost wide" type="button" data-confirm="logout"><?= $t('profile.logout') ?></button>
    <dialog class="confirm" id="logout" aria-labelledby="logout-title">
      <form method="post" action="/profile/logout">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <span class="confirm-icon" aria-hidden="true">&rarr;</span>
        <h3 id="logout-title"><?= $t('profile.logoutTitle') ?></h3>
        <p><?= $t('profile.logoutText') ?></p>
        <div class="confirm-actions">
          <button class="button ghost" type="button" data-close><?= $t('dialog.cancel') ?></button>
          <button class="button" type="submit"><?= $t('profile.logoutConfirm') ?></button>
        </div>
      </form>
    </dialog>
  </section>
</div>
