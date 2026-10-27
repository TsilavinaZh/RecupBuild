<?php
/**
 * Any page that does not exist, or a plan that is not (or no longer) the user's.
 *
 * @var callable $t
 */
?>
<section class="wrap hero" aria-labelledby="notfound-title">
  <p class="stamp figure">404</p>
  <h1 id="notfound-title"><?= $t('notfound.title') ?></h1>
  <p class="lead"><?= $t('notfound.text') ?></p>
  <p><a class="button" href="/"><?= $t('notfound.home') ?></a></p>
</section>
