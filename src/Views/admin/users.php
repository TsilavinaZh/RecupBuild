<?php
defined('RECUPBUILD') || (http_response_code(404) && exit);
/**
 * GET /admin/users: every account, what they built, what is left of their free AI
 * credits, and a small form per account to set those credits again.
 *
 * @var callable $t @var callable $e @var string $locale @var list<array> $accounts @var string $csrf
 */
$section = 'users';
$date = static function (?string $iso) use ($locale): string {
    if ($iso === null || $iso === '' || ($time = strtotime($iso)) === false) {
        return '';
    }
    if (class_exists(\IntlDateFormatter::class)) {
        $formatter = new \IntlDateFormatter($locale === 'en' ? 'en' : 'fr', \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE);

        return (string) $formatter->format($time);
    }

    return date('d/m/Y', $time);
};
/** The Google photo, or the first letters of the name when there is none. */
$initials = static function (string $name): string {
    $words = array_slice(preg_split('/\s+/', trim($name)) ?: [], 0, 2);

    return implode('', array_map(static fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)), $words));
};
?>
<div class="wrap admin-shell">
  <?php require __DIR__ . '/_nav.php' ?>

  <div class="admin-main">
    <section class="admin-head" aria-labelledby="users-title">
      <p class="stamp"><?= $t('admin.stamp') ?></p>
      <h1 id="users-title"><?= $t('admin.users.title') ?></h1>
      <p class="muted"><?= $t('admin.users.lead') ?></p>
    </section>

    <section class="card" aria-labelledby="accounts-title">
      <div class="card-head">
        <h2 id="accounts-title"><?= $t('admin.accounts') ?></h2>
        <span class="muted small"><?= $t('admin.usersCount', ['count' => count($accounts)]) ?></span>
      </div>
      <?php if ($accounts === []): ?>
        <p class="empty"><?= $t('admin.empty') ?></p>
      <?php else: ?>
        <div class="table-scroll">
          <table class="inventory">
            <thead>
              <tr>
                <th scope="col"><?= $t('admin.col.user') ?></th>
                <th scope="col"><?= $t('admin.col.last') ?></th>
                <th scope="col"><?= $t('admin.col.plans') ?></th>
                <th scope="col"><?= $t('admin.col.rebuilds') ?></th>
                <th scope="col"><?= $t('admin.col.credits') ?></th>
                <th scope="col"><span class="sr-only"><?= $t('admin.col.actions') ?></span></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($accounts as $account): ?>
                <?php
                  $id = (int) $account['id'];
                  $name = (string) ($account['name'] ?? '');
                  $picture = trim((string) ($account['picture'] ?? ''));
                ?>
                <tr>
                  <td>
                    <div class="user-cell">
                      <?php if ($picture !== ''): ?>
                        <img class="avatar" src="<?= $e($picture) ?>" alt="" width="34" height="34" loading="lazy" referrerpolicy="no-referrer">
                      <?php else: ?>
                        <span class="avatar initials" aria-hidden="true"><?= $e($initials($name)) ?></span>
                      <?php endif ?>
                      <div>
                        <p class="plan-name"><?= $e($name) ?><?= !empty($account['is_admin']) ? ' · ' . $t('admin.role') : '' ?></p>
                        <p class="muted small"><?= $e((string) ($account['email'] ?? '—')) ?><br><?= $t('admin.since', ['date' => $date($account['created_at'] ?? null)]) ?></p>
                      </div>
                    </div>
                  </td>
                  <td class="figure small"><?= $e($date($account['last_login_at'] ?? null)) ?></td>
                  <td class="figure"><?= (int) $account['plans'] ?></td>
                  <td class="figure"><?= (int) $account['rebuilds'] ?></td>
                  <td class="figure small"><?= $t('admin.creditsShort', ['build' => (int) $account['build_credits'], 'rebuild' => (int) $account['rebuild_credits']]) ?></td>
                  <td>
                    <button class="button small" type="button" data-confirm="manage-<?= $id ?>"><?= $t('admin.manage') ?></button>
                  </td>
                </tr>

                <!-- The floatbox to manage one account: its AI credits. -->
                <dialog class="confirm manage" id="manage-<?= $id ?>" aria-labelledby="manage-title-<?= $id ?>">
                  <form method="post" action="/admin/users/<?= $id ?>/credits" class="admin-credits">
                    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                    <div class="manage-head">
                      <?php if ($picture !== ''): ?>
                        <img class="avatar big" src="<?= $e($picture) ?>" alt="" width="88" height="88" loading="lazy" referrerpolicy="no-referrer">
                      <?php else: ?>
                        <span class="avatar big initials" aria-hidden="true"><?= $e($initials($name)) ?></span>
                      <?php endif ?>
                      <div>
                        <h3 id="manage-title-<?= $id ?>"><?= $t('admin.manageTitle', ['name' => $name]) ?></h3>
                        <p class="muted small"><?= $e((string) ($account['email'] ?? '—')) ?></p>
                        <p class="muted small"><?= $t('admin.since', ['date' => $date($account['created_at'] ?? null)]) ?> · <?= $t('admin.manageActivity', ['plans' => (int) $account['plans'], 'rebuilds' => (int) $account['rebuilds']]) ?></p>
                      </div>
                    </div>
                    <div class="manage-fields">
                      <label class="field">
                        <span><?= $t('admin.buildsLabel') ?></span>
                        <input class="figure" type="number" name="build" min="0" max="999"
                               value="<?= (int) $account['build_credits'] ?>" required>
                      </label>
                      <label class="field">
                        <span><?= $t('admin.rebuildsLabel') ?></span>
                        <input class="figure" type="number" name="rebuild" min="0" max="999"
                               value="<?= (int) $account['rebuild_credits'] ?>" required>
                      </label>
                    </div>
                    <p class="muted small"><?= $t('admin.creditsHint') ?></p>
                    <div class="confirm-actions">
                      <button class="button ghost" type="button" data-close><?= $t('dialog.cancel') ?></button>
                      <button class="button" type="submit"><?= $t('admin.save') ?></button>
                    </div>
                  </form>
                </dialog>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
      <?php endif ?>
    </section>
  </div>
</div>
