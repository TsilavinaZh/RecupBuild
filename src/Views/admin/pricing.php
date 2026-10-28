<?php
defined('RECUPBUILD') || (http_response_code(404) && exit);
/**
 * GET /admin/pricing: the parameters of the offers — how many free credits every
 * new account gets, and the price shown on the Pro card of /pricing.
 *
 * @var callable $t @var callable $e @var array<string, string> $settings @var string $csrf
 */
$section = 'pricing';
?>
<div class="wrap admin-shell">
  <?php require __DIR__ . '/_nav.php' ?>

  <div class="admin-main">
    <section class="admin-head" aria-labelledby="pricing-title">
      <p class="stamp"><?= $t('admin.stamp') ?></p>
      <h1 id="pricing-title"><?= $t('admin.pricing.title') ?></h1>
      <p class="muted"><?= $t('admin.pricing.lead') ?></p>
    </section>

    <section class="card" aria-labelledby="offer-title">
      <div class="card-head">
        <h2 id="offer-title"><?= $t('admin.pricing.freeOffer') ?></h2>
        <span class="pill"><?= $t('pricing.free.name') ?></span>
      </div>
      <p class="muted"><?= $t('admin.pricing.freeHint') ?></p>
      <form method="post" action="/admin/pricing" class="admin-form">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <label for="free-builds">
          <?= $t('admin.pricing.freeBuilds') ?>
          <input class="figure" id="free-builds" type="number" name="free_build_credits" min="0" max="999"
                 value="<?= $e($settings['free_build_credits']) ?>" required>
          <span class="hint"><?= $t('admin.pricing.freeBuildsHint') ?></span>
        </label>
        <label for="free-rebuilds">
          <?= $t('admin.pricing.freeRebuilds') ?>
          <input class="figure" id="free-rebuilds" type="number" name="free_rebuild_credits" min="0" max="999"
                 value="<?= $e($settings['free_rebuild_credits']) ?>" required>
          <span class="hint"><?= $t('admin.pricing.freeRebuildsHint') ?></span>
        </label>
        <button class="button" type="submit"><?= $t('admin.save') ?></button>
      </form>
    </section>

    <section class="card" aria-labelledby="pro-title">
      <div class="card-head">
        <h2 id="pro-title"><?= $t('admin.pricing.pro') ?></h2>
        <span class="pill"><?= $t('pricing.soon') ?></span>
      </div>
      <p class="muted"><?= $t('admin.pricing.proHint') ?></p>
      <form method="post" action="/admin/pricing" class="admin-form">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="free_build_credits" value="<?= $e($settings['free_build_credits']) ?>">
        <input type="hidden" name="free_rebuild_credits" value="<?= $e($settings['free_rebuild_credits']) ?>">
        <label for="pro-price">
          <?= $t('admin.pricing.proPrice') ?>
          <input id="pro-price" type="text" name="pro_price" maxlength="20"
                 value="<?= $e($settings['pro_price']) ?>" required>
          <span class="hint"><?= $t('admin.pricing.proPriceHint') ?></span>
        </label>
        <button class="button" type="submit"><?= $t('admin.save') ?></button>
      </form>
    </section>
  </div>
</div>
