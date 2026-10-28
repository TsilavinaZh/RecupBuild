<?php
/**
 * GET /rebuild: the photo of a finished object (uploaded, or one of the drawn examples).
 *
 * @var callable $t @var callable $e @var array{build: int, rebuild: int} $credits @var list<string> $examples @var string $csrf
 */
?>
<section class="wrap app-head" aria-labelledby="rebuild-title">
  <p class="stamp"><?= $t('rebuild.stamp') ?></p>
  <h1 id="rebuild-title"><?= $t('rebuild.title') ?></h1>
  <p class="lead left"><?= $t('rebuild.lead') ?></p>
  <p class="muted small"><?= $t('rebuild.credits', ['rebuild' => $credits['rebuild']]) ?></p>
</section>

<div class="wrap scan-grid">
  <div>
    <form class="dropzone" method="post" action="/rebuild" enctype="multipart/form-data" data-loading="<?= $t('rebuild.analysing') ?>" data-seconds="20">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <label class="drop" data-drop>
        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required data-preview="rebuild-preview">
        <img id="rebuild-preview" class="drop-preview" alt="" hidden>
        <span class="drop-icon" aria-hidden="true">
          <svg viewBox="0 0 48 48"><path d="M8 20h32v6H8zM12 26v14M36 26v14M16 20V12h16v8"/></svg>
        </span>
        <span class="drop-title"><?= $t('rebuild.drop') ?></span>
        <span class="muted small"><?= $t('scan.dropHint') ?></span>
        <span class="button ghost small" aria-hidden="true"><?= $t('scan.choose') ?></span>
        <span class="muted small figure"><?= $t('scan.formats') ?></span>
      </label>
      <button class="button big wide" type="submit"><?= $t('rebuild.analyse') ?></button>
    </form>

    <section class="examples" aria-labelledby="examples-title">
      <p id="examples-title" class="eyebrow"><?= $t('rebuild.examples') ?></p>
      <div class="example-row three">
        <?php foreach ($examples as $example): ?>
          <form method="post" action="/rebuild" data-loading="<?= $t('rebuild.analysing') ?>" data-seconds="20">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="example" value="<?= $e($example) ?>">
            <button class="example" type="submit" aria-label="<?= $t('rebuild.useExample', ['name' => html_entity_decode($t('rebuild.example.' . $example), ENT_QUOTES, 'UTF-8')]) ?>">
              <span class="example-picture"><img src="/examples/<?= $e($example) ?>.jpg" alt="" loading="lazy"></span>
              <span class="example-name"><?= $t('rebuild.example.' . $example) ?></span>
            </button>
          </form>
        <?php endforeach ?>
      </div>
    </section>
  </div>

  <aside class="card tips" aria-label="<?= $t('rebuild.title') ?>">
    <ul class="checks">
      <li><?= $t('rebuild.promise.parts') ?></li>
      <li><?= $t('rebuild.promise.tools') ?></li>
      <li><?= $t('rebuild.promise.steps') ?></li>
      <li><?= $t('rebuild.promise.estimate') ?></li>
    </ul>
  </aside>
</div>
