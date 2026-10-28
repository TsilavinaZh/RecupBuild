<?php
defined('RECUPBUILD') || (http_response_code(404) && exit);
/**
 * GET /admin: the dashboard of the back office — the site at a glance, then the
 * newest accounts, plans and reverse builds. The sidebar comes from _nav.php.
 *
 * @var callable $t @var callable $e @var string $locale
 * @var array{users: int, plans: int, rebuilds: int, builds_left: int, rebuilds_left: int} $stats
 * @var list<array> $accounts @var list<array> $plans @var list<array> $rebuilds
 * @var array{build: int, rebuild: int} $free
 * @var list<array{day: string, plans: int, rebuilds: int}> $activity
 * @var list<array{name: string, plans: int, rebuilds: int}> $top
 */
$section = 'dashboard';
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
/** A text of the language files, unescaped (escaped where printed), or the fallback. */
$words = static fn (string $key, string $fallback): string => ($text = html_entity_decode($t($key), ENT_QUOTES, 'UTF-8')) === $key ? $fallback : $text;

// --- the activity chart (SVG drawn on the server: no library, no script) -----
$busy = 0;
foreach ($activity as $slot) {
    $busy = max($busy, $slot['plans'], $slot['rebuilds']);
}
$chart = ['w' => 640, 'h' => 180, 'left' => 30, 'right' => 10, 'top' => 14, 'bottom' => 30];
$chart['plotW'] = $chart['w'] - $chart['left'] - $chart['right'];
$chart['plotH'] = $chart['h'] - $chart['top'] - $chart['bottom'];
$slotCount = max(1, count($activity));
$groupW = $chart['plotW'] / $slotCount;
$barW = min(14.0, $groupW * 0.3);
$scale = $busy > 0 ? $chart['plotH'] / $busy : 0.0;

// --- the top builders chart (horizontal bars, same colours) ------------------
$topMax = 1;
foreach ($top as $builder) {
    $topMax = max($topMax, $builder['plans'] + $builder['rebuilds']);
}
$topRowH = 34;
$topNameW = 150;
$topBarW = 400;
?>
<div class="wrap admin-shell">
  <?php require __DIR__ . '/_nav.php' ?>

  <div class="admin-main">
    <section class="admin-head" aria-labelledby="dashboard-title">
      <p class="stamp"><?= $t('admin.stamp') ?></p>
      <h1 id="dashboard-title"><?= $t('admin.dashboard.title') ?></h1>
      <p class="muted"><?= $t('admin.dashboard.lead') ?></p>
    </section>

    <!-- The whole site at a glance. -->
    <section class="card" aria-labelledby="overview-title">
      <div class="card-head">
        <h2 id="overview-title"><?= $t('admin.overview') ?></h2>
        <span class="pill"><?= $t('admin.usersCount', ['count' => $stats['users']]) ?></span>
      </div>
      <ul class="admin-stats">
        <li>
          <span class="figure"><?= $stats['users'] ?></span>
          <span><?= $t('admin.stat.users') ?></span>
        </li>
        <li>
          <span class="figure"><?= $stats['plans'] ?></span>
          <span><?= $t('admin.stat.plans') ?></span>
        </li>
        <li>
          <span class="figure"><?= $stats['rebuilds'] ?></span>
          <span><?= $t('admin.stat.rebuilds') ?></span>
        </li>
        <li>
          <span class="figure"><?= $stats['builds_left'] ?></span>
          <span><?= $t('admin.stat.buildsLeft') ?></span>
        </li>
        <li>
          <span class="figure"><?= $stats['rebuilds_left'] ?></span>
          <span><?= $t('admin.stat.rebuildsLeft') ?></span>
        </li>
      </ul>
      <p class="muted small"><?= $t('admin.dashboard.freeOffer', ['build' => $free['build'], 'rebuild' => $free['rebuild']]) ?></p>
    </section>

    <!-- Two charts, drawn as SVG by the server: the same ink as the rest of the site. -->
    <div class="admin-two">
      <section class="card" aria-labelledby="activity-title">
        <div class="card-head">
          <h2 id="activity-title"><?= $t('admin.dashboard.activity') ?></h2>
          <span class="muted small"><?= $t('admin.dashboard.activityDays', ['count' => count($activity)]) ?></span>
        </div>
        <?php if ($busy === 0): ?>
          <p class="empty"><?= $t('admin.dashboard.activityEmpty') ?></p>
        <?php else: ?>
          <p class="chart-legend">
            <span><i class="chart-key plans" aria-hidden="true"></i><?= $t('admin.dashboard.chartPlans') ?></span>
            <span><i class="chart-key rebuilds" aria-hidden="true"></i><?= $t('admin.dashboard.chartRebuilds') ?></span>
          </p>
          <svg class="chart" viewBox="0 0 <?= $chart['w'] ?> <?= $chart['h'] ?>" role="img"
               aria-label="<?= $t('admin.dashboard.activityAria', ['max' => $busy]) ?>">
            <!-- the millimetre-paper grid, like the rest of the site -->
            <rect x="<?= $chart['left'] ?>" y="<?= $chart['top'] ?>" width="<?= $chart['plotW'] ?>" height="<?= $chart['plotH'] ?>"
                  fill="none" stroke="var(--line)" stroke-width="1"/>
            <?php foreach ([0.5, 1] as $ratio): ?>
              <line x1="<?= $chart['left'] ?>" y1="<?= $chart['top'] + $chart['plotH'] * (1 - $ratio) ?>"
                    x2="<?= $chart['left'] + $chart['plotW'] ?>" y2="<?= $chart['top'] + $chart['plotH'] * (1 - $ratio) ?>"
                    stroke="var(--line)" stroke-width="1" stroke-dasharray="3 4"/>
              <text class="chart-tick" x="<?= $chart['left'] - 6 ?>" y="<?= $chart['top'] + $chart['plotH'] * (1 - $ratio) + 3 ?>"
                    text-anchor="end"><?= (int) round($busy * $ratio) ?></text>
            <?php endforeach ?>
            <text class="chart-tick" x="<?= $chart['left'] - 6 ?>" y="<?= $chart['top'] + $chart['plotH'] + 3 ?>" text-anchor="end">0</text>
            <?php foreach ($activity as $index => $slot): ?>
              <?php
                $center = $chart['left'] + $index * $groupW + $groupW / 2;
                $plansH = $slot['plans'] * $scale;
                $rebuildsH = $slot['rebuilds'] * $scale;
                $label = date('d/m', strtotime($slot['day'] . ' UTC'));
              ?>
              <?php if ($slot['plans'] > 0): ?>
                <rect class="bar-plans" x="<?= $center - $barW - 1.5 ?>" y="<?= $chart['top'] + $chart['plotH'] - $plansH ?>"
                      width="<?= $barW ?>" height="<?= $plansH ?>" rx="2"/>
              <?php endif ?>
              <?php if ($slot['rebuilds'] > 0): ?>
                <rect class="bar-rebuilds" x="<?= $center + 1.5 ?>" y="<?= $chart['top'] + $chart['plotH'] - $rebuildsH ?>"
                      width="<?= $barW ?>" height="<?= $rebuildsH ?>" rx="2"/>
              <?php endif ?>
              <?php if ($index % 2 === 0 || $index === $slotCount - 1): ?>
                <text class="chart-tick" x="<?= $center ?>" y="<?= $chart['h'] - 10 ?>" text-anchor="middle"><?= $e($label) ?></text>
              <?php endif ?>
            <?php endforeach ?>
          </svg>
        <?php endif ?>
      </section>

      <section class="card" aria-labelledby="top-title">
        <div class="card-head">
          <h2 id="top-title"><?= $t('admin.dashboard.top') ?></h2>
          <span class="muted small"><?= $t('admin.dashboard.topCount', ['count' => count($top)]) ?></span>
        </div>
        <?php if ($top === []): ?>
          <p class="empty"><?= $t('admin.dashboard.topEmpty') ?></p>
        <?php else: ?>
          <p class="chart-legend">
            <span><i class="chart-key plans" aria-hidden="true"></i><?= $t('admin.dashboard.chartPlans') ?></span>
            <span><i class="chart-key rebuilds" aria-hidden="true"></i><?= $t('admin.dashboard.chartRebuilds') ?></span>
          </p>
          <svg class="chart" viewBox="0 0 <?= $chart['w'] ?> <?= count($top) * $topRowH + 8 ?>" role="img"
               aria-label="<?= $t('admin.dashboard.topAria') ?>">
            <?php foreach ($top as $index => $builder): ?>
              <?php
                $y = $index * $topRowH + 6;
                $total = $builder['plans'] + $builder['rebuilds'];
                $barMax = $topBarW * $total / $topMax;
                $plansW = $total > 0 ? $barMax * $builder['plans'] / $total : 0.0;
                $rebuildsW = $barMax - $plansW;
              ?>
              <text class="chart-name" x="0" y="<?= $y + 15 ?>"><?= $e(mb_substr($builder['name'], 0, 18)) ?></text>
              <rect x="<?= $topNameW ?>" y="<?= $y + 4 ?>" width="<?= $topBarW ?>" height="16" rx="3" fill="var(--sand)"/>
              <?php if ($plansW > 0): ?>
                <rect class="bar-plans" x="<?= $topNameW ?>" y="<?= $y + 4 ?>" width="<?= $plansW ?>" height="16" rx="3"/>
              <?php endif ?>
              <?php if ($rebuildsW > 0): ?>
                <rect class="bar-rebuilds" x="<?= $topNameW + $plansW ?>" y="<?= $y + 4 ?>" width="<?= $rebuildsW ?>" height="16" rx="3"/>
              <?php endif ?>
              <text class="chart-name" x="<?= $topNameW + $topBarW + 10 ?>" y="<?= $y + 15 ?>"><?= $total ?></text>
            <?php endforeach ?>
          </svg>
        <?php endif ?>
      </section>
    </div>

    <!-- The newest accounts. -->
    <section class="card" aria-labelledby="recent-users-title">
      <div class="card-head">
        <h2 id="recent-users-title"><?= $t('admin.dashboard.recentUsers') ?></h2>
        <a class="button small ghost" href="/admin/users"><?= $t('admin.nav.users') ?></a>
      </div>
      <?php if ($accounts === []): ?>
        <p class="empty"><?= $t('admin.empty') ?></p>
      <?php else: ?>
        <ul class="plan-list">
          <?php foreach ($accounts as $account): ?>
            <li>
              <div class="plan-info">
                <p class="plan-name"><?= $e($account['name']) ?><?= !empty($account['is_admin']) ? ' · ' . $t('admin.role') : '' ?></p>
                <p class="muted small"><?= $e((string) ($account['email'] ?? '—')) ?> · <?= $t('admin.since', ['date' => $date($account['created_at'] ?? null)]) ?></p>
              </div>
              <p class="figure small"><?= $t('admin.creditsShort', ['build' => (int) $account['build_credits'], 'rebuild' => (int) $account['rebuild_credits']]) ?></p>
            </li>
          <?php endforeach ?>
        </ul>
      <?php endif ?>
    </section>

    <!-- The newest plans and reverse builds: what the AI has been doing. -->
    <div class="admin-two">
      <section class="card" aria-labelledby="recent-plans-title">
        <div class="card-head">
          <h2 id="recent-plans-title"><?= $t('admin.dashboard.recentPlans') ?></h2>
          <span class="muted small"><?= $t('admin.plansCount', ['count' => count($plans)]) ?></span>
        </div>
        <?php if ($plans === []): ?>
          <p class="empty"><?= $t('admin.dashboard.noPlans') ?></p>
        <?php else: ?>
          <ul class="plan-list">
            <?php foreach ($plans as $plan): ?>
              <li>
                <div class="plan-info">
                  <p class="plan-name"><?= $e($plan['project_name'] ?? $words('project.' . $plan['project_id'], $plan['project_id'])) ?></p>
                  <p class="muted small"><?= $e($plan['user_name']) ?> · <?= $e($date($plan['created_at'])) ?></p>
                </div>
                <a class="button small ghost" href="/plans/<?= (int) $plan['id'] ?>"><?= $t('profile.open') ?></a>
              </li>
            <?php endforeach ?>
          </ul>
        <?php endif ?>
      </section>

      <section class="card" aria-labelledby="recent-rebuilds-title">
        <div class="card-head">
          <h2 id="recent-rebuilds-title"><?= $t('admin.dashboard.recentRebuilds') ?></h2>
          <span class="muted small"><?= $t('admin.rebuildsCount', ['count' => count($rebuilds)]) ?></span>
        </div>
        <?php if ($rebuilds === []): ?>
          <p class="empty"><?= $t('admin.dashboard.noRebuilds') ?></p>
        <?php else: ?>
          <ul class="plan-list">
            <?php foreach ($rebuilds as $rebuild): ?>
              <li>
                <div class="plan-info">
                  <p class="plan-name"><?= $e($rebuild['name']) ?></p>
                  <p class="muted small"><?= $e($rebuild['user_name']) ?> · <?= $e($date($rebuild['created_at'])) ?></p>
                </div>
                <a class="button small ghost" href="/rebuilds/<?= (int) $rebuild['id'] ?>"><?= $t('profile.open') ?></a>
              </li>
            <?php endforeach ?>
          </ul>
        <?php endif ?>
      </section>
    </div>
  </div>
</div>
