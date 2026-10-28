<?php
defined('RECUPBUILD') || (http_response_code(404) && exit);
/**
 * GET /cgu: the terms of use, as numbered articles. The three legal pages
 * (legal, cgu, privacy) link to one another.
 *
 * @var callable $t @var callable $e
 */
$articles = range(1, 10);
?>
<section class="wrap hero" aria-labelledby="cgu-title">
  <p class="stamp"><?= $t('cgu.stamp') ?></p>
  <h1 id="cgu-title"><?= $t('cgu.heading') ?></h1>
  <p class="lead"><?= $t('cgu.lead') ?></p>
  <p class="muted small legal-siblings">
    <a href="/legal"><?= $t('legal.title') ?></a> ·
    <a href="/cgu" aria-current="page"><?= $t('cgu.title') ?></a> ·
    <a href="/privacy"><?= $t('privacy.title') ?></a>
  </p>
</section>

<div class="wrap about">
  <?php foreach ($articles as $number): ?>
    <?php $key = 'cgu.a' . $number ?>
    <section class="card" aria-labelledby="cgu-a<?= $number ?>-title">
      <p class="eyebrow"><?= $t('cgu.article') ?> <?= $number ?></p>
      <h2 id="cgu-a<?= $number ?>-title"><?= $t($key . '.title') ?></h2>
      <p><?= $t($key . '.text') ?></p>
    </section>
  <?php endforeach ?>
</div>
