<?php
defined('RECUPBUILD') || (http_response_code(404) && exit);
/**
 * GET /privacy: the privacy policy, as numbered articles. The three legal pages
 * (legal, cgu, privacy) link to one another.
 *
 * @var callable $t @var callable $e
 */
$articles = range(1, 8);
?>
<section class="wrap hero" aria-labelledby="privacy-title">
  <p class="stamp"><?= $t('privacy.stamp') ?></p>
  <h1 id="privacy-title"><?= $t('privacy.heading') ?></h1>
  <p class="lead"><?= $t('privacy.lead') ?></p>
  <p class="muted small legal-siblings">
    <a href="/legal"><?= $t('legal.title') ?></a> ·
    <a href="/cgu"><?= $t('cgu.title') ?></a> ·
    <a href="/privacy" aria-current="page"><?= $t('privacy.title') ?></a>
  </p>
</section>

<div class="wrap about">
  <?php foreach ($articles as $number): ?>
    <?php $key = 'privacy.a' . $number ?>
    <section class="card" aria-labelledby="privacy-a<?= $number ?>-title">
      <p class="eyebrow"><?= $t('privacy.article') ?> <?= $number ?></p>
      <h2 id="privacy-a<?= $number ?>-title"><?= $t($key . '.title') ?></h2>
      <p><?= $t($key . '.text') ?></p>
    </section>
  <?php endforeach ?>
</div>
