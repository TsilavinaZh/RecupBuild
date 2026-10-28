<?php
defined('RECUPBUILD') || (http_response_code(404) && exit);
/**
 * GET /pricing. Free is open (and turned on at the first sign-in); Pro and Custom
 * are announced, their buttons disabled, a strip of masking tape across them.
 *
 * @var callable $t @var callable $e @var ?array{build: int, rebuild: int} $credits @var int $free @var string $proPrice
 */
$check = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 10.5l4 4 8-9"/></svg>';
?>
<section class="wrap hero" aria-labelledby="pricing-title">
  <p class="stamp"><?= $t('pricing.eyebrow') ?></p>
  <h1 id="pricing-title"><?= $t('pricing.heading') ?></h1>
  <p class="lead"><?= $t('pricing.lead', ['free' => $free]) ?></p>
</section>

<section class="wrap offers" aria-label="<?= $t('pricing.title') ?>">
  <!-- Free: open to every account, turned on at the first sign-in. -->
  <article class="offer offer-free">
    <p class="offer-state available"><span class="dot" aria-hidden="true"></span><?= $t('pricing.available') ?> · <?= $t('pricing.auto') ?></p>
    <h2><?= $t('pricing.free.name') ?></h2>
    <p class="price"><span class="figure"><?= $t('pricing.free.price') ?></span></p>
    <p class="muted"><?= $t('pricing.free.for') ?></p>
    <ul class="features">
      <li><?= $check ?><?= $t('pricing.free.builds', ['free' => $free]) ?></li>
      <li><?= $check ?><?= $t('pricing.free.rebuilds', ['free' => $free]) ?></li>
      <li><?= $check ?><?= $t('pricing.free.history') ?></li>
      <li><?= $check ?><?= $t('pricing.free.extras') ?></li>
    </ul>
    <?php if ($credits !== null): ?>
      <p class="current"><?= $t('pricing.free.current') ?></p>
      <p class="muted small"><?= $t('pricing.free.left', ['build' => $credits['build'], 'rebuild' => $credits['rebuild']]) ?></p>
      <a class="button wide" href="/scan"><?= $t('nav.scan') ?></a>
    <?php else: ?>
      <a class="button wide" href="/auth/google?return=<?= rawurlencode('/scan') ?>"><?= $t('pricing.free.start') ?></a>
    <?php endif ?>
  </article>

  <!-- Pro: announced. -->
  <article class="offer offer-soon" aria-describedby="pro-soon">
    <span class="tape" id="pro-soon"><?= $t('pricing.soon') ?></span>
    <h2><?= $t('pricing.pro.name') ?></h2>
    <p class="price"><span class="figure"><?= $e($proPrice) ?></span> <span class="muted"><?= $t('pricing.perMonth') ?></span></p>
    <p class="muted"><?= $t('pricing.pro.for') ?></p>
    <ul class="features">
      <li><?= $check ?><?= $t('pricing.pro.volume') ?></li>
      <li><?= $check ?><?= $t('pricing.pro.priority') ?></li>
      <li><?= $check ?><?= $t('pricing.pro.history') ?></li>
      <li><?= $check ?><?= $t('pricing.pro.support') ?></li>
    </ul>
    <button class="button wide ghost" type="button" disabled><?= $t('pricing.soon') ?></button>
  </article>

  <!-- Custom: announced. -->
  <article class="offer offer-soon" aria-describedby="custom-soon">
    <span class="tape" id="custom-soon"><?= $t('pricing.soon') ?></span>
    <h2><?= $t('pricing.custom.name') ?></h2>
    <p class="price"><span class="figure words"><?= $t('pricing.custom.price') ?></span> <span class="muted"><?= $t('pricing.onQuote') ?></span></p>
    <p class="muted"><?= $t('pricing.custom.for') ?></p>
    <ul class="features">
      <li><?= $check ?><?= $t('pricing.custom.team') ?></li>
      <li><?= $check ?><?= $t('pricing.custom.volume') ?></li>
      <li><?= $check ?><?= $t('pricing.custom.onboarding') ?></li>
      <li><?= $check ?><?= $t('pricing.custom.local') ?></li>
    </ul>
    <button class="button wide ghost" type="button" disabled><?= $t('pricing.soon') ?></button>
  </article>
</section>

<section class="wrap faq" aria-labelledby="faq-title">
  <h2 id="faq-title"><?= $t('pricing.faq.title') ?></h2>
  <?php foreach (['build', 'rebuild', 'scan'] as $item): ?>
    <details>
      <summary><?= $t("pricing.faq.{$item}.q") ?></summary>
      <p><?= $t("pricing.faq.{$item}.a") ?></p>
    </details>
  <?php endforeach ?>
</section>
