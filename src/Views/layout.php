<?php
/**
 * The frame of every page: header, the steps of the app, flash message, page, footer,
 * and the waiting screen the slow forms show while the AI works.
 *
 * @var callable $t @var callable $e @var string $locale @var string $title @var string $content
 * @var ?array $user @var ?string $flash @var string $current @var ?string $step @var bool $wide
 */
$initials = $user !== null ? implode('', array_map(static fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)), array_slice(preg_split('/\s+/', trim($user['name'])) ?: [], 0, 2))) : '';
$here = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';
$active = static fn (string ...$prefixes): string => array_filter($prefixes, static fn (string $p): bool => $here === $p || ($p !== '/' && str_starts_with($here, $p))) !== [] ? 'aria-current="page"' : '';
$steps = ['scan', 'inventory', 'projects', 'plan'];
$stepIndex = $step === null ? -1 : (int) array_search($step, $steps, true);
$isError = $flash !== null && str_starts_with($flash, 'error.');
?>
<!doctype html>
<html lang="<?= $e($locale) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="color-scheme" content="light">
  <meta name="theme-color" content="#F6F3EC">
  <meta name="description" content="<?= $t('footer.tagline') ?>">
  <title><?= $title ?> · RecupBuild</title>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <link rel="preload" href="/fonts/space-grotesk-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/css/pages.css">
  <script src="/js/pages.js" defer></script>
</head>
<body class="<?= $wide ? 'wide' : '' ?>">
  <a class="skip" href="#main"><?= $t('skip') ?></a>

  <header class="top">
    <div class="wrap top-row">
      <a class="brand" href="/" aria-label="<?= $t('nav.home') ?>">
        <img src="/favicon.svg" alt="" width="30" height="30">
        <span>RecupBuild</span>
      </a>
      <nav class="nav" id="site-nav" aria-label="RecupBuild">
        <a href="/scan" <?= $active('/scan', '/inventory', '/projects', '/plans') ?>><?= $t('nav.scan') ?></a>
        <a href="/rebuild" <?= $active('/rebuild') ?>><?= $t('nav.rebuild') ?></a>
        <?php if ($user !== null): ?><a href="/history" <?= $active('/history') ?>><?= $t('nav.history') ?></a><?php endif ?>
        <a href="/pricing" <?= $active('/pricing') ?>><?= $t('nav.pricing') ?></a>
        <a href="/about" <?= $active('/about') ?>><?= $t('nav.about') ?></a>
      </nav>
      <div class="top-tools">
        <div class="langs" role="group" aria-label="<?= $t('nav.language') ?>">
          <?php foreach (['fr' => 'FR', 'en' => 'EN', 'mg' => 'MG'] as $code => $label): ?>
            <a href="?lang=<?= $code ?>" hreflang="<?= $code ?>" <?= $locale === $code ? 'aria-current="true"' : '' ?>><?= $label ?></a>
          <?php endforeach ?>
        </div>
        <?php if ($user !== null): ?>
          <a class="avatar-link" href="/profile" aria-label="<?= $t('nav.profile') ?>" <?= $current === 'profile' ? 'aria-current="page"' : '' ?>>
            <?php if (!empty($user['picture'])): ?>
              <img class="avatar" src="<?= $e($user['picture']) ?>" alt="" width="34" height="34" referrerpolicy="no-referrer">
            <?php else: ?>
              <span class="avatar initials" aria-hidden="true"><?= $e($initials) ?></span>
            <?php endif ?>
          </a>
        <?php else: ?>
          <a class="button small" href="/auth/google?return=<?= rawurlencode('/scan') ?>"><?= $t('nav.signIn') ?></a>
        <?php endif ?>
        <button class="menu-toggle" type="button" aria-controls="site-nav" aria-expanded="false" data-menu>
          <span class="sr-only"><?= $t('nav.menu') ?></span><span aria-hidden="true"></span>
        </button>
      </div>
    </div>
  </header>

  <?php if ($stepIndex >= 0): ?>
    <nav class="wrap stepper" aria-label="<?= $t('steps.label') ?>">
      <ol>
        <?php foreach ($steps as $index => $name): ?>
          <li class="<?= $index < $stepIndex ? 'done' : ($index === $stepIndex ? 'now' : '') ?>" <?= $index === $stepIndex ? 'aria-current="step"' : '' ?>>
            <span class="figure"><?= $index < $stepIndex ? '✓' : '0' . ($index + 1) ?></span><?= $t('steps.' . $name) ?>
          </li>
        <?php endforeach ?>
      </ol>
    </nav>
  <?php endif ?>

  <?php if ($flash !== null): ?>
    <p class="wrap flash <?= $isError ? 'error' : '' ?>" role="<?= $isError ? 'alert' : 'status' ?>"><?= $t($flash) ?></p>
  <?php endif ?>

  <main id="main" tabindex="-1">
    <?= $content ?>
  </main>

  <footer class="bottom">
    <div class="wrap bottom-row">
      <div>
        <p class="brand small-brand"><img src="/favicon.svg" alt="" width="22" height="22"> RecupBuild</p>
        <p class="muted"><?= $t('footer.tagline') ?></p>
      </div>
      <div class="bottom-notes">
        <p class="hackathon"><span class="meta-dot" aria-hidden="true"></span><?= $t('footer.hackathon') ?></p>
        <p class="warning"><?= $t('footer.warning') ?></p>
      </div>
    </div>
  </footer>

  <!-- Shown by pages.js while a slow form waits for the AI: the browser keeps it on screen until the next page arrives. -->
  <div class="waiting" id="waiting" hidden role="status" aria-live="polite"
       data-elapsed="<?= $t('loading.elapsed') ?>" data-expected="<?= $t('loading.expected') ?>" data-overdue="<?= $t('loading.overdue') ?>">
    <div class="waiting-card">
      <svg class="waiting-saw" viewBox="0 0 280 70" aria-hidden="true">
        <rect x="14" y="14" width="250" height="40" rx="2" fill="#c99a66"/>
        <path d="M14 24 Q80 21 140 25 T264 23 M14 34 Q70 37 120 33 T264 35 M14 44 Q90 41 160 45 T264 43" fill="none" stroke="#7b4f2a" stroke-width="1" opacity="0.35"/>
        <line class="waiting-cut" x1="14" y1="34" x2="264" y2="34" stroke="#c4622d" stroke-width="2.5" stroke-dasharray="6 4"/>
      </svg>
      <p class="waiting-label"></p>
      <div class="waiting-bar"><span></span></div>
      <p class="waiting-time figure"></p>
    </div>
  </div>
</body>
</html>
