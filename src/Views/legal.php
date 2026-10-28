<?php
defined('RECUPBUILD') || (http_response_code(404) && exit);
/**
 * GET /legal: the legal notice. The site and the author are fixed; the host, its
 * address and the contact e-mail are the few facts the admin keeps on /admin/legal.
 *
 * @var callable $t @var callable $e @var array<string, string> $legal
 */
$host = trim((string) ($legal['legal_host'] ?? ''));
$hostUrl = trim((string) ($legal['legal_host_url'] ?? ''));
$contact = trim((string) ($legal['legal_contact'] ?? ''));
?>
<section class="wrap hero" aria-labelledby="legal-title">
  <p class="stamp"><?= $t('legal.stamp') ?></p>
  <h1 id="legal-title"><?= $t('legal.heading') ?></h1>
  <p class="lead"><?= $t('legal.lead') ?></p>
  <p class="muted small legal-siblings">
    <a href="/legal" aria-current="page"><?= $t('legal.title') ?></a> ·
    <a href="/cgu"><?= $t('cgu.title') ?></a> ·
    <a href="/privacy"><?= $t('privacy.title') ?></a>
  </p>
</section>

<div class="wrap about">
  <section class="card" aria-labelledby="legal-site-title">
    <h2 id="legal-site-title"><?= $t('legal.site.title') ?></h2>
    <p><?= $t('legal.site.text') ?></p>
  </section>

  <section class="card" aria-labelledby="legal-host-title">
    <h2 id="legal-host-title"><?= $t('legal.host.title') ?></h2>
    <?php if ($host !== '' && $hostUrl !== ''): ?>
      <p><?= $t('legal.host.text', ['host' => $host]) ?> <a href="<?= $e($hostUrl) ?>" target="_blank" rel="noopener noreferrer"><?= $e($hostUrl) ?></a></p>
    <?php elseif ($host !== ''): ?>
      <p><?= $t('legal.host.text', ['host' => $host]) ?></p>
    <?php else: ?>
      <p class="empty"><?= $t('legal.host.missing') ?></p>
    <?php endif ?>
  </section>

  <section class="card" aria-labelledby="legal-data-title">
    <h2 id="legal-data-title"><?= $t('legal.data.title') ?></h2>
    <p><?= $t('legal.data.text') ?></p>
  </section>

  <section class="card" aria-labelledby="legal-ip-title">
    <h2 id="legal-ip-title"><?= $t('legal.ip.title') ?></h2>
    <p><?= $t('legal.ip.text') ?></p>
  </section>

  <section class="card" aria-labelledby="legal-contact-title">
    <h2 id="legal-contact-title"><?= $t('legal.contact.title') ?></h2>
    <?php if ($contact !== ''): ?>
      <p><a href="mailto:<?= $e($contact) ?>"><?= $e($contact) ?></a></p>
    <?php else: ?>
      <p><a href="https://tendry.mg/" target="_blank" rel="noopener noreferrer">tendry.mg</a></p>
    <?php endif ?>
  </section>
</div>
