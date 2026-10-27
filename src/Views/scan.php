<?php
/**
 * GET /scan: the photo of the heap. A file to upload (dropped or chosen), the example
 * heap analysed by the AI, or the instant demo (no AI). Analysing is slow: the form
 * shows the waiting screen while the browser waits for the inventory.
 *
 * @var callable $t @var callable $e @var ?array $workspace @var array{build: int, rebuild: int} $credits @var string $csrf
 */
?>
<section class="wrap app-head" aria-labelledby="scan-title">
  <h1 id="scan-title"><?= $t('scan.title') ?></h1>
  <p class="lead left"><?= $t('scan.lead') ?></p>
  <p class="muted small"><?= $t('scan.credits', ['build' => $credits['build'], 'rebuild' => $credits['rebuild']]) ?></p>
</section>

<div class="wrap scan-grid">
  <div>
    <?php if ($workspace !== null): ?>
      <section class="card resume" aria-labelledby="resume-title">
        <h2 id="resume-title"><?= $t('scan.resume') ?></h2>
        <p class="muted"><?= $t('scan.resumeText', ['count' => count($workspace['pieces'])]) ?></p>
        <div class="row">
          <a class="button" href="/inventory"><?= $t('scan.resumeAction') ?></a>
          <form method="post" action="/scan/reset">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <button class="button ghost" type="submit"><?= $t('scan.restart') ?></button>
          </form>
        </div>
      </section>
    <?php endif ?>

    <!-- The photo: dropped on the zone or chosen; pages.js shows it before it is sent. -->
    <form class="dropzone" method="post" action="/scan" enctype="multipart/form-data"
          data-loading="<?= $t('scan.analysing') ?>" data-seconds="25">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="source" value="upload">
      <label class="drop" data-drop>
        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required data-preview="scan-preview">
        <img id="scan-preview" class="drop-preview" alt="" hidden>
        <span class="drop-icon" aria-hidden="true">
          <svg viewBox="0 0 48 48"><rect x="6" y="10" width="36" height="28" rx="4"/><circle cx="24" cy="24" r="7"/><path d="M17 10l3-4h8l3 4"/></svg>
        </span>
        <span class="drop-title"><?= $t('scan.drop') ?></span>
        <span class="muted small"><?= $t('scan.dropHint') ?></span>
        <span class="button ghost small" aria-hidden="true"><?= $t('scan.choose') ?></span>
        <span class="muted small figure"><?= $t('scan.formats') ?></span>
      </label>
      <button class="button big wide" type="submit"><?= $t('scan.analyse') ?></button>
    </form>

    <!-- No photo at hand: the instant demo, or a real heap for the AI. -->
    <section class="examples" aria-labelledby="examples-title">
      <p id="examples-title" class="eyebrow"><?= $t('scan.examples') ?></p>
      <div class="example-row">
        <form method="post" action="/scan">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="source" value="demo">
          <button class="example" type="submit">
            <span class="example-picture"><img src="/demo/sample-pile.svg" alt="" loading="lazy"><span class="badge"><?= $t('scan.demoBadge') ?></span></span>
            <span class="example-name"><?= $t('scan.demo') ?></span>
            <span class="muted small"><?= $t('scan.demoHint') ?></span>
          </button>
        </form>
        <form method="post" action="/scan" data-loading="<?= $t('scan.analysing') ?>" data-seconds="25">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="source" value="example">
          <button class="example" type="submit">
            <span class="example-picture"><img src="/examples/pile-garden.svg" alt="" loading="lazy"><span class="badge"><?= $t('scan.gardenBadge') ?></span></span>
            <span class="example-name"><?= $t('scan.garden') ?></span>
          </button>
        </form>
      </div>
    </section>
  </div>

  <aside class="card tips" aria-labelledby="tips-title">
    <h2 id="tips-title"><?= $t('scan.tips') ?></h2>
    <ol class="numbered">
      <li><?= $t('scan.tip.flat') ?></li>
      <li><?= $t('scan.tip.above') ?></li>
      <li><?= $t('scan.tip.light') ?></li>
    </ol>
    <img src="/demo/sample-pile.svg" alt="" loading="lazy" class="tips-art">
    <a class="rebuild-link" href="/rebuild"><?= $t('scan.rebuild') ?> <span aria-hidden="true">→</span></a>
  </aside>
</div>
