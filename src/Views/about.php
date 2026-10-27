<?php
/**
 * GET /about: the story, the Meta hackathon, how it works, what happens to the data.
 *
 * @var callable $t
 */
?>
<section class="wrap hero" aria-labelledby="about-title">
  <p class="stamp"><?= $t('about.stamp') ?></p>
  <h1 id="about-title"><?= $t('about.heading') ?></h1>
  <p class="lead"><?= $t('about.lead') ?></p>
</section>

<div class="wrap about">
  <!-- The Meta hackathon, first: where the project comes from. -->
  <section class="card hackathon-card" aria-labelledby="hackathon-title">
    <span class="meta-badge" aria-hidden="true">
      <svg viewBox="0 0 48 48"><path d="M8 30c0-9 4.5-16 9.5-16 3.7 0 6.4 3.6 9.7 9.4l2.4 4.2C33 33.4 35 35 37.3 35c2.3 0 3.7-2.2 3.7-6 0-7.2-3.2-13-7-13-2.6 0-4.6 2.3-6.8 5.8" fill="none" stroke="#0866ff" stroke-width="4" stroke-linecap="round"/><path d="M8 30c0 3.8 1.5 5.9 4 5.9 2.6 0 4.4-1.8 7.5-7.5l3.4-6.3" fill="none" stroke="#0866ff" stroke-width="4" stroke-linecap="round"/></svg>
    </span>
    <div>
      <h2 id="hackathon-title"><?= $t('about.hackathon.title') ?></h2>
      <p><?= $t('about.hackathon.text') ?></p>
    </div>
  </section>

  <section class="card" aria-labelledby="idea-title">
    <h2 id="idea-title"><?= $t('about.idea.title') ?></h2>
    <p><?= $t('about.idea.text') ?></p>
    <img src="/examples/bookcase.svg" alt="" width="1000" height="760" loading="lazy" class="about-art">
  </section>

  <section class="card" aria-labelledby="how-title">
    <h2 id="how-title"><?= $t('about.how.title') ?></h2>
    <ol class="numbered">
      <li><?= $t('about.how.scan') ?></li>
      <li><?= $t('about.how.engine') ?></li>
      <li><?= $t('about.how.server') ?></li>
    </ol>
  </section>

  <section class="card" aria-labelledby="data-title">
    <h2 id="data-title"><?= $t('about.data.title') ?></h2>
    <ul class="checks">
      <li><?= $t('about.data.photo') ?></li>
      <li><?= $t('about.data.google') ?></li>
      <li><?= $t('about.data.demo') ?></li>
    </ul>
  </section>

  <section class="card warning-card" aria-labelledby="limits-title">
    <h2 id="limits-title"><?= $t('about.limits.title') ?></h2>
    <p><?= $t('about.limits.text') ?></p>
  </section>
</div>
