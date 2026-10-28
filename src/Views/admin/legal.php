<?php
defined('RECUPBUILD') || (http_response_code(404) && exit);
/**
 * GET /admin/legal: the few facts the legal notice (/legal) shows — who hosts the
 * site and where to write. The rest of the notice lives in the language files.
 *
 * @var callable $t @var callable $e @var array<string, string> $settings @var string $csrf
 */
$section = 'legal';
?>
<div class="wrap admin-shell">
  <?php require __DIR__ . '/_nav.php' ?>

  <div class="admin-main">
    <section class="admin-head" aria-labelledby="legal-title">
      <p class="stamp"><?= $t('admin.stamp') ?></p>
      <h1 id="legal-title"><?= $t('admin.legal.title') ?></h1>
      <p class="muted"><?= $t('admin.legal.lead') ?></p>
    </section>

    <section class="card" aria-labelledby="notice-title">
      <div class="card-head">
        <h2 id="notice-title"><?= $t('admin.legal.notice') ?></h2>
        <a class="button small ghost" href="/legal"><?= $t('admin.legal.preview') ?></a>
      </div>
      <p class="muted"><?= $t('admin.legal.hint') ?></p>
      <form method="post" action="/admin/legal" class="admin-form">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <label for="legal-host">
          <?= $t('admin.legal.host') ?>
          <input id="legal-host" type="text" name="legal_host" maxlength="120"
                 value="<?= $e($settings['legal_host']) ?>">
        </label>
        <label for="legal-host-url">
          <?= $t('admin.legal.hostUrl') ?>
          <input id="legal-host-url" type="text" name="legal_host_url" maxlength="120"
                 value="<?= $e($settings['legal_host_url']) ?>" placeholder="https://…">
        </label>
        <label for="legal-contact">
          <?= $t('admin.legal.contact') ?>
          <input id="legal-contact" type="email" name="legal_contact" maxlength="120"
                 value="<?= $e($settings['legal_contact']) ?>" placeholder="vous@example.com">
          <span class="hint"><?= $t('admin.legal.contactHint') ?></span>
        </label>
        <button class="button" type="submit"><?= $t('admin.save') ?></button>
      </form>
    </section>
  </div>
</div>
