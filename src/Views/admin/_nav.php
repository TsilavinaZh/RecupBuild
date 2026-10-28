<?php
defined('RECUPBUILD') || (http_response_code(404) && exit);
/**
 * The sidebar of the back office: four pages, the same cards and colours as the
 * rest of the site. Each admin view requires it with its own `$section`.
 *
 * @var callable $t @var string $section
 */
$nav = [
    'dashboard' => ['/admin', 'admin.nav.dashboard'],
    'users' => ['/admin/users', 'admin.nav.users'],
    'pricing' => ['/admin/pricing', 'admin.nav.pricing'],
    'legal' => ['/admin/legal', 'admin.nav.legal'],
];
?>
<nav class="admin-side" aria-label="<?= $t('admin.nav.label') ?>">
  <p class="admin-side-title"><?= $t('admin.nav.label') ?></p>
  <?php foreach ($nav as $name => [$href, $key]): ?>
    <a href="<?= $href ?>" <?= $section === $name ? 'aria-current="page"' : '' ?>><?= $t($key) ?></a>
  <?php endforeach ?>
  <a class="admin-side-exit" href="/"><?= $t('admin.nav.exit') ?></a>
</nav>
