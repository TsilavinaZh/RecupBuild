<?php
defined('RECUPBUILD') || (http_response_code(404) && exit);
/**
 * GET /: the home page as a workshop drawing. The hero is a cutting mat where a heap
 * of reclaimed wood is measured, laid out as a cutting plan and assembled into a shelf
 * (js/pages.js plays the four moments; without JavaScript the plan shows).
 * Every picture is drawn here, in SVG: no image file.
 *
 * @var callable $t @var callable $e @var ?array $user
 */
use RecupBuild\Core\Session;
use RecupBuild\Models\Users;
use RecupBuild\Services\Drawing;

$user = Session::user();
$start = $user !== null ? '/scan' : '/auth/google?return=' . rawurlencode('/scan');
$n = [Drawing::class, 'n'];

// The pieces of the heap (drawn 0.34 unit per mm) and where each one is at every moment:
// [x, y, rotation, scale along the length, scale across, opacity].
$scale = 0.34;
$pieces = [
    ['id' => 6, 'kind' => 'metal', 'mm' => [700, 500], 'seed' => 71, 'tone' => 0, 'cuts' => [],
     'at' => ['pile' => [372, 300, 9], 'measure' => [410, 330, -2], 'plan' => [381, 311, 0], 'result' => [560, 430, -6, 0.3, 0.3, 0]]],
    ['id' => 5, 'kind' => 'plywood', 'mm' => [600, 400], 'seed' => 61, 'tone' => 0.2, 'cuts' => [[0, 0.62, 7]],
     'at' => ['pile' => [262, 318, -12], 'measure' => [150, 396, 1.5], 'plan' => [142, 368, 0], 'result' => [320, 270, 0, 222 / 204, 340 / 136, 0.4]]],
    ['id' => 7, 'kind' => 'plank', 'mm' => [600, 180], 'seed' => 53, 'tone' => 0.45, 'cuts' => [[0, 0.92, 6]],
     'at' => ['pile' => [336, 252, 21], 'measure' => [150, 268, -2], 'plan' => [142, 256.5, 0], 'result' => [320, 270, 0, 200 / 204, 26 / 61]]],
    ['id' => 1, 'kind' => 'plank', 'mm' => [1200, 140], 'seed' => 11, 'tone' => 0.35, 'cuts' => [[0, 0.49, 1], [0.51, 1, 2]],
     'at' => ['pile' => [318, 222, -16], 'measure' => [250, 58, -2], 'plan' => [244, 60, 0], 'result' => [209, 270, 90, 340 / 408, 22 / 48]]],
    ['id' => 2, 'kind' => 'plank', 'mm' => [1000, 140], 'seed' => 23, 'tone' => 0.58, 'cuts' => [[0, 0.48, 3], [0.5, 0.98, 4]],
     'at' => ['pile' => [342, 272, 8], 'measure' => [220, 122, 1.5], 'plan' => [210, 120, 0], 'result' => [431, 270, 90, 1, 22 / 48]]],
    ['id' => 3, 'kind' => 'plank', 'mm' => [800, 95], 'seed' => 37, 'tone' => 0.22, 'cuts' => [[0, 0.66, 5]],
     'at' => ['pile' => [296, 300, -4], 'measure' => [190, 178, -1], 'plan' => [176, 172, 0], 'result' => [320, 425, 0, 200 / 272, 26 / 32]]],
    ['id' => 4, 'kind' => 'plank', 'mm' => [900, 40], 'seed' => 41, 'tone' => 0.75, 'cuts' => [],
     'at' => ['pile' => [352, 236, 31], 'measure' => [210, 218, 1], 'plan' => [193, 207, 0], 'result' => [320, 113, 0, 200 / 306, 26 / 14]]],
];
$a4 = ['pile' => [560, 420, -14, 1, 1, 0], 'measure' => [562, 96, -4], 'plan' => [562, 90, 0], 'result' => [562, 90, 0, 1, 1, 0]];
$spot = static fn (array $s): array => [$s[0], $s[1], $s[2], $s[3] ?? 1, $s[4] ?? 1, $s[5] ?? 1];
$transform = static fn (array $s): string => 'translate(' . $n($s[0]) . ' ' . $n($s[1]) . ') rotate(' . $n($s[2]) . ') scale(' . $n($s[3], 4) . ' ' . $n($s[4], 4) . ')';
$moments = ['pile', 'measure', 'plan', 'result'];
$how = [
    'scan' => '<rect x="30" y="4" width="36" height="62" rx="6" class="trace"/><path d="M37 52 l8 -6 l7 3 l9 -8" class="trace accent"/><rect x="52" y="16" width="9" height="12" class="trace"/><path d="M36 14 h6 M36 14 v6 M60 58 h-6 M60 58 v-6" class="trace accent"/><path d="M6 36 h18 M72 36 h18" class="trace dashed"/>',
    'inventory' => '<rect x="8" y="14" width="60" height="12" rx="2" class="trace"/><rect x="8" y="34" width="48" height="12" rx="2" class="trace"/><rect x="8" y="54" width="70" height="8" rx="2" class="trace"/><circle cx="78" cy="20" r="7" class="trace accent"/><circle cx="66" cy="40" r="7" class="trace accent"/>',
    'projects' => '<rect x="6" y="10" width="84" height="52" rx="3" class="trace"/><path d="M6 28 h84 M6 46 h84" class="trace dashed"/><rect x="10" y="14" width="34" height="10" class="trace accent"/><rect x="48" y="14" width="22" height="10" class="trace accent"/><rect x="10" y="32" width="50" height="10" class="trace accent"/><path d="M70 50 l12 0 m-5 -4 l5 4 l-5 4" class="trace"/>',
    'plan' => '<path d="M22 66 V8 M74 66 V8" class="trace"/><path d="M22 10 H74 M22 36 H74 M22 62 H74" class="trace accent"/><path d="M22 22 H74" class="trace dashed"/><path d="M80 8 v58 M77 8 h6 M77 66 h6" class="trace"/>',
];
$tags = [
    ['shelf', 0, '<path d="M18 52 V6 M62 52 V6 M18 8 H62 M18 29 H62 M18 50 H62"/>'],
    ['dryer', 2.2, '<path d="M8 46 L26 18 H72 L54 46 Z M8 46 H54 V52 H8 Z M54 46 L72 18 V24 L54 52"/><circle cx="70" cy="7" r="4" class="sun"/>'],
    ['more', 0.6, '<path d="M40 6 V18 M40 38 V50 M14 28 H26 M54 28 H66 M22 10 L30 18 M58 46 L50 38 M58 10 L50 18 M22 46 L30 38"/><circle cx="40" cy="28" r="7" class="sun"/>'],
    ['crate', 2.8, '<path d="M10 18 H70 V50 H10 Z M10 29 H70 M10 40 H70 M18 18 V50 M62 18 V50"/>'],
    ['coop', 1.2, '<path d="M8 26 L40 6 L72 26 M14 22 V52 H66 V22 M34 52 V36 H46 V52"/>'],
];
// The table of the reverse build, as the model read examples/table.jpg:
// [letter, cut dimensions in mm, quantity].
$parts = [['A', '1800 × 900 × 40', 1], ['B', '680 × 70 × 60', 4], ['C', '580 × 60 × 80', 2], ['D', '700 × 70 × 40', 2]];
$batten = Drawing::plank(1196, 18, 88, 0.6);
?>
<!-- Hero: the cutting mat. -->
<section class="wrap hero-home" aria-labelledby="home-title">
  <div class="hero-text">
    <p class="hackathon-pill"><img src="/images/meta.svg" alt="" width="20" height="20"><?= $t('home.badge') ?></p>
    <p class="stamp"><?= $t('home.stamp') ?></p>
    <h1 id="home-title">
      <span class="rise"><?= $t('home.line1') ?></span>
      <span class="dimensioned rise late"><?= $t('home.line2') ?></span>
    </h1>
    <p class="lead left"><?= $t('home.lead') ?></p>
    <div class="hero-actions">
      <?php if ($user !== null): ?>
        <div class="hero-buttons">
          <a class="button big" href="/scan"><?= $t('home.start') ?> <span aria-hidden="true">→</span></a>
          <a class="button ghost big" href="/rebuild"><?= $t('home.rebuild.cta') ?></a>
        </div>
      <?php else: ?>
        <a class="button google big" href="<?= $e($start) ?>">
          <svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
          <?= $t('home.continue') ?>
        </a>
        <a class="hero-rebuild" href="#rebuild-title"><?= $t('home.orRebuild') ?> <span aria-hidden="true">↓</span></a>
        <p class="muted small"><?= $t('home.free', ['free' => Users::FREE_CREDITS]) ?></p>
        <p class="muted small"><?= $t('home.privacy') ?></p>
      <?php endif ?>
    </div>
  </div>

  <div class="hero-scene">
    <figure class="mat" data-scene data-moment="plan">
      <svg viewBox="0 0 640 470" role="img" aria-label="<?= $t('home.scene') ?>">
        <rect x="14" y="14" width="612" height="442" rx="6" fill="none" stroke="#d6c9af" stroke-dasharray="2 6"/>
        <?php foreach ([[14, 14, 1, 1], [626, 14, -1, 1], [14, 456, 1, -1], [626, 456, -1, -1]] as [$x, $y, $dx, $dy]): ?>
          <path d="M<?= $x ?> <?= $y + 18 * $dy ?> V<?= $y ?> H<?= $x + 18 * $dx ?>" fill="none" stroke="#c4622d" stroke-width="2"/>
        <?php endforeach ?>
        <g class="result-layer"><ellipse cx="320" cy="442" rx="170" ry="9" fill="#1f2a24" opacity="0.14"/></g>

        <g class="piece a4" data-piece='<?= $e(json_encode(array_map($spot, $a4))) ?>' transform="<?= $transform($spot($a4['plan'])) ?>">
          <rect x="-35.7" y="-50.5" width="71.4" height="101" fill="#fff" stroke="#7f7b6b" stroke-width="0.8"/>
          <text y="2" text-anchor="middle" class="mono" font-size="10" fill="#4a5750">A4</text>
          <text y="16" text-anchor="middle" class="mono" font-size="6.5" fill="#4a5750">210 × 297</text>
        </g>

        <?php foreach ($pieces as $index => $piece): ?>
          <?php [$w, $h] = [$piece['mm'][0] * $scale, $piece['mm'][1] * $scale]; ?>
          <g class="piece" data-i="<?= $index ?>" data-piece='<?= $e(json_encode(array_map($spot, $piece['at']))) ?>' transform="<?= $transform($spot($piece['at']['plan'])) ?>">
            <?= Drawing::plank($w, $h, $piece['seed'], $piece['tone'], $piece['kind']) ?>
            <g class="measure-layer">
              <rect x="<?= $n(-$w / 2 - 3) ?>" y="<?= $n(-$h / 2 - 3) ?>" width="<?= $n($w + 6) ?>" height="<?= $n($h + 6) ?>" rx="3" fill="none" stroke="#2f6b4f" stroke-width="1.4" stroke-dasharray="5 3"/>
              <g transform="translate(<?= $n(-$w / 2) ?> <?= $n(-$h / 2 - 9) ?>)"><rect x="-1" y="-8" width="60" height="11" rx="2" fill="#2f6b4f"/><text x="3" y="0.5" class="mono" font-size="7.5" fill="#f6f3ec"><?= $piece['mm'][0] ?> × <?= $piece['mm'][1] ?></text></g>
            </g>
            <g class="plan-layer">
              <?php foreach ($piece['cuts'] as [$from, $to, $number]): ?>
                <?php $mid = -$w / 2 + ($from + $to) / 2 * $w; ?>
                <rect x="<?= $n(-$w / 2 + $from * $w + 2) ?>" y="<?= $n(-$h / 2 + 2) ?>" width="<?= $n(($to - $from) * $w - 4) ?>" height="<?= $n($h - 4) ?>" fill="#f6f3ec" fill-opacity="0.28" stroke="#9a4719" stroke-width="1.3" stroke-dasharray="4 2.5"/>
                <circle cx="<?= $n($mid) ?>" cy="0" r="<?= $n(min(9, $h / 2 - 2)) ?>" fill="#1f2a24"/>
                <text x="<?= $n($mid) ?>" y="3" text-anchor="middle" class="mono" font-size="<?= $n(min(10, $h / 2)) ?>" font-weight="700" fill="#f6f3ec"><?= $number ?></text>
              <?php endforeach ?>
            </g>
          </g>
        <?php endforeach ?>

        <g class="result-layer">
          <rect x="470" y="140" width="138" height="30" rx="15" fill="#1f2a24"/>
          <text x="539" y="160" text-anchor="middle" font-size="12.5" font-weight="600" fill="#f6f3ec"><?= $t('home.resultLabel') ?></text>
          <path d="M470 155 H446" stroke="#1f2a24" stroke-width="1.5" stroke-dasharray="3 3"/>
        </g>
        <rect class="sweep" x="20" y="0" width="600" height="2.5" fill="#c4622d"/>
      </svg>
      <figcaption class="moments" role="group" aria-label="<?= $t('home.moments') ?>">
        <?php foreach ($moments as $index => $moment): ?>
          <button type="button" class="moment" data-moment-button="<?= $moment ?>" aria-pressed="<?= $moment === 'plan' ? 'true' : 'false' ?>">
            <span class="figure">0<?= $index + 1 ?></span> <?= $t('home.moment.' . $moment) ?>
          </button>
        <?php endforeach ?>
      </figcaption>
    </figure>
    <dl class="cartouche figure">
      <div><dt><?= $t('home.cartouche.sheet') ?></dt><dd>RB-001</dd></div>
      <div><dt><?= $t('home.cartouche.scale') ?></dt><dd>A4 210 × 297</dd></div>
      <div><dt><?= $t('home.cartouche.kerf') ?></dt><dd><?= $t('home.cartouche.kerfValue') ?></dd></div>
      <div><dt><?= $t('home.cartouche.drawn') ?></dt><dd><?= $t('home.cartouche.drawnValue') ?></dd></div>
    </dl>
  </div>
</section>

<!-- The 3-minute pitch, on YouTube (privacy-enhanced player: no cookie until it plays). -->
<section class="wrap section pitch" aria-labelledby="pitch-title">
  <div class="pitch-intro">
    <p class="eyebrow"><?= $t('home.video.eyebrow') ?></p>
    <h2 id="pitch-title" class="section-title"><?= $t('home.video.title') ?></h2>
    <p class="lead left"><?= $t('home.video.text') ?></p>
  </div>
  <div class="pitch-frame">
    <iframe src="https://www.youtube-nocookie.com/embed/sO_UkvMmugU?rel=0" title="<?= $t('home.video.frameTitle') ?>"
            loading="lazy" allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
            referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
  </div>
</section>

<!-- Two ways in: from a heap of pieces, or from an object seen somewhere. -->
<section class="wrap section modes" aria-labelledby="modes-title">
  <p class="eyebrow"><?= $t('home.modes.eyebrow') ?></p>
  <h2 id="modes-title" class="section-title"><?= $t('home.modes.title') ?></h2>
  <div class="mode-grid">
    <?php foreach (['build' => ['/demo/pile.jpg', '/scan'], 'rebuild' => ['/examples/table.jpg', '/rebuild']] as $mode => [$picture, $href]): ?>
      <article class="mode mode-<?= $mode ?>" data-reveal>
        <div class="mode-art"><img src="<?= $picture ?>" alt="" width="1000" height="760" loading="lazy"></div>
        <p class="mode-tag figure"><?= $mode === 'build' ? '01' : '02' ?> · <?= $mode === 'build' ? 'BUILD' : 'REBUILD' ?></p>
        <h3><?= $t("home.modes.{$mode}.title") ?></h3>
        <p><?= $t("home.modes.{$mode}.text") ?></p>
        <ol class="mode-flow">
          <?php foreach ([1, 2, 3] as $step): ?><li><?= $t("home.modes.{$mode}.step{$step}") ?></li><?php endforeach ?>
        </ol>
        <a class="button <?= $mode === 'rebuild' ? 'light' : '' ?>" href="<?= $mode === 'build' ? $e($start) : $href ?>"><?= $t("home.modes.{$mode}.cta") ?> <span aria-hidden="true">→</span></a>
      </article>
    <?php endforeach ?>
  </div>
</section>

<!-- How it works: along the tape. -->
<section class="band" aria-labelledby="how-title">
  <div class="wrap">
    <p class="eyebrow"><?= $t('home.how.eyebrow') ?></p>
    <h2 id="how-title" class="section-title"><?= $t('home.how.title') ?></h2>
    <ol class="tape-steps" data-reveal>
      <?php foreach ($how as $key => $glyph): ?>
        <li class="tape-step">
          <span class="mark figure" aria-hidden="true"><?= array_search($key, array_keys($how), true) * 25 ?><small>cm</small></span>
          <svg viewBox="0 0 96 72" class="glyph" aria-hidden="true"><?= $glyph ?></svg>
          <h3><?= $t("home.how.{$key}.title") ?></h3>
          <p class="muted"><?= $t("home.how.{$key}.text") ?></p>
        </li>
      <?php endforeach ?>
    </ol>
  </div>
</section>

<!-- Projects: the tags on the wall. -->
<section class="wrap section" aria-labelledby="projects-title">
  <p class="eyebrow"><?= $t('home.projects.eyebrow') ?></p>
  <h2 id="projects-title" class="section-title"><?= $t('home.projects.title') ?></h2>
  <p class="lead left"><?= $t('home.projects.lead') ?></p>
  <div class="tags">
    <svg class="batten" viewBox="0 0 1200 26" preserveAspectRatio="none" aria-hidden="true"><g transform="translate(600 13)"><?= $batten ?></g></svg>
    <ul class="tag-row">
      <?php foreach ($tags as [$key, $drop, $glyph]): ?>
        <li class="tag-wrap <?= $key === 'more' ? 'invented' : '' ?> drop-<?= (int) round($drop * 10) ?>">
          <span class="string" aria-hidden="true"></span>
          <article class="tag" tabindex="0">
            <span class="hole" aria-hidden="true"></span>
            <svg viewBox="0 0 80 56" class="tag-glyph" aria-hidden="true"><?= $glyph ?></svg>
            <h3><?= $key === 'more' ? $t('home.projects.more') : $t('project.' . $key) ?></h3>
            <p><?= $key === 'more' ? $t('home.projects.moreText') : $t('home.projects.' . $key) ?></p>
          </article>
        </li>
      <?php endforeach ?>
    </ul>
  </div>
</section>

<!-- The reverse build, shown as it works: the photo read by the AI, and the sheet it gives back. -->
<section class="reverse" aria-labelledby="rebuild-title">
  <div class="wrap">
    <div class="reverse-intro">
      <p class="eyebrow"><?= $t('home.rebuild.eyebrow') ?></p>
      <h2 id="rebuild-title" class="section-title"><?= $t('home.rebuild.title') ?></h2>
      <p class="lead left"><?= $t('home.rebuild.text') ?></p>
    </div>
    <div class="reverse-grid">
      <figure class="reverse-photo" data-reveal>
        <div class="scan-frame">
          <img src="/examples/table.jpg" alt="<?= $t('home.rebuild.photoAlt') ?>" width="495" height="244" loading="lazy">
          <span class="scan-line" aria-hidden="true"></span>
          <?php foreach ($parts as [$letter]): ?><span class="pin pin-<?= strtolower($letter) ?> figure" aria-hidden="true"><?= $letter ?></span><?php endforeach ?>
        </div>
        <figcaption><span class="live-dot" aria-hidden="true"></span><?= $t('home.rebuild.scanning') ?></figcaption>
      </figure>

      <div class="reverse-sheet" data-reveal>
        <p class="sheet-head figure"><span><?= $t('home.rebuild.sheet') ?></span><span>RB-R01</span></p>
        <h3><?= $t('home.rebuild.parts') ?></h3>
        <table class="parts">
          <thead><tr><th scope="col"></th><th scope="col"><?= $t('home.rebuild.part') ?></th><th scope="col" class="figure"><?= $t('home.rebuild.dims') ?></th><th scope="col"><?= $t('home.rebuild.qty') ?></th></tr></thead>
          <tbody>
            <?php foreach ($parts as [$letter, $dims, $qty]): ?>
              <tr><td><span class="pin-key figure"><?= $letter ?></span></td><td><?= $t("home.rebuild.part{$letter}") ?></td><td class="figure"><?= $dims ?></td><td class="figure">× <?= $qty ?></td></tr>
            <?php endforeach ?>
          </tbody>
        </table>
        <h3><?= $t('home.rebuild.tools') ?></h3>
        <ul class="chips"><?php foreach ([1, 2, 3, 4, 5] as $tool): ?><li><?= $t("home.rebuild.tool{$tool}") ?></li><?php endforeach ?></ul>
        <h3><?= $t('home.rebuild.steps') ?></h3>
        <ol class="mode-flow">
          <?php foreach ([1, 2, 3] as $step): ?><li><?= $t("home.rebuild.step{$step}") ?></li><?php endforeach ?>
        </ol>
        <a class="button" href="/rebuild"><?= $t('home.rebuild.cta') ?> <span aria-hidden="true">→</span></a>
      </div>
    </div>
  </div>
</section>

<!-- Why: the dimensions that matter, on the dark side of the bench. -->
<section class="why" aria-labelledby="why-title">
  <div class="wrap">
    <p class="eyebrow light"><?= $t('home.why.eyebrow') ?></p>
    <h2 id="why-title" class="section-title"><?= $t('home.why.title') ?></h2>
    <ul class="figures">
      <?php foreach (['scale', 'pieces', 'phone', 'check'] as $item): ?>
        <li data-reveal>
          <span class="dimension" aria-hidden="true"></span>
          <p class="figure big-figure"><?= $t("home.why.{$item}.figure") ?></p>
          <h3><?= $t("home.why.{$item}.title") ?></h3>
          <p><?= $t("home.why.{$item}.text") ?></p>
        </li>
      <?php endforeach ?>
    </ul>
  </div>
</section>

<!-- Call to action: cut into the end of a log. -->
<section class="wrap section cta" aria-labelledby="cta-title">
  <svg class="end-grain" viewBox="-210 -210 420 420" aria-hidden="true" data-reveal>
    <circle r="205" fill="#9c6a3c"/><circle r="196" fill="#c99a66"/>
    <?php foreach (Drawing::rings(200, 14, 7) as $index => $d): ?>
      <path d="<?= $d ?>" class="ring" stroke-width="<?= $index % 3 === 0 ? 2.4 : 1.2 ?>"/>
    <?php endforeach ?>
    <circle r="6" fill="#7b4f2a"/>
    <path d="M0 0 L46 -28 L70 -60 M0 0 L-38 44 L-52 92 M0 0 L-60 -18" class="crack"/>
  </svg>
  <div>
    <h2 id="cta-title" class="section-title big"><?= $t('home.cta.title') ?></h2>
    <p class="lead left"><?= $t('home.cta.text') ?></p>
    <a class="button big" href="<?= $e($start) ?>"><?= $user !== null ? $t('home.start') : $t('home.continue') ?> <span aria-hidden="true">→</span></a>
  </div>
</section>
