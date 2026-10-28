<?php
defined('RECUPBUILD') || (http_response_code(404) && exit);
/**
 * POST /projects/choose when even the smaller sizes leave parts without wood.
 *
 * @var callable $t @var array $result
 */
?>
<section class="wrap app-head" aria-labelledby="failed-title">
  <h1 id="failed-title"><?= $t('plan.failedTitle') ?></h1>
  <p class="lead left"><?= $t('plan.failedText') ?></p>
  <p class="reason"><?= $t('reason.' . ($result['reason'] ?? 'not_enough_wood')) ?></p>
  <div class="row">
    <a class="button" href="/projects"><?= $t('plan.another') ?></a>
    <a class="button ghost" href="/inventory"><?= $t('projects.back') ?></a>
  </div>
</section>
