<?php
/**
 * GET /about: the story of the project (built at the Meta Global AI Developer
 * Hackathon), who made it, how it works and what happens to the data.
 *
 * @var callable $t
 */
$skills = ['PHP', 'Laravel', 'JavaScript', 'TypeScript', 'Python', 'React', 'Node.js', 'SQLite', 'MySQL', 'IA / ML', 'Docker', 'Figma'];
$links = [
    ['https://tendry.mg/', 'tendry.mg'],
    ['https://github.com/TsilavinaZh/TsilavinaZH', 'GitHub'],
    ['https://www.linkedin.com/in/tendry-tsilavina-razanamalala-9a6aa82ab/', 'LinkedIn'],
    ['mailto:contact@tendry.mg', 'contact@tendry.mg'],
];
?>
<section class="wrap hero" aria-labelledby="about-title">
  <p class="stamp"><?= $t('about.stamp') ?></p>
  <h1 id="about-title"><?= $t('about.heading') ?></h1>
  <p class="lead"><?= $t('about.lead') ?></p>
</section>

<div class="wrap about">
  <!-- The hackathon, first: where the project comes from. -->
  <section class="card hackathon-card" aria-labelledby="hackathon-title">
    <ul class="hosts">
      <li><img src="/images/meta.svg" alt="" width="48" height="48"><span>Meta</span></li>
      <li><img src="/images/cerebral-valley.svg" alt="" width="48" height="48"><span>Cerebral Valley</span></li>
    </ul>
    <div>
      <p class="eyebrow"><?= $t('about.hackathon.eyebrow') ?></p>
      <h2 id="hackathon-title"><?= $t('about.hackathon.title') ?></h2>
      <p class="muted small"><?= $t('about.hackathon.meta') ?></p>
      <p class="hackathon-text"><?= $t('about.hackathon.text') ?></p>
      <p class="hackathon-text"><?= $t('about.hackathon.models') ?></p>
    </div>
  </section>

  <!-- Who made it. -->
  <section class="card author" aria-labelledby="author-title">
    <img class="author-photo" src="/images/tendry.webp" alt="<?= $t('about.author.photoAlt') ?>" width="128" height="128">
    <div>
      <p class="eyebrow"><?= $t('about.author.eyebrow') ?></p>
      <h2 id="author-title">Tendry Tsilavina RAZANAMALALA</h2>
      <p class="muted"><?= $t('about.author.role') ?></p>
      <p class="author-bio"><?= $t('about.author.bio') ?></p>
      <ul class="chips" aria-label="<?= $t('about.author.skills') ?>">
        <?php foreach ($skills as $skill): ?><li><?= htmlspecialchars($skill) ?></li><?php endforeach ?>
      </ul>
      <ul class="author-links">
        <?php foreach ($links as [$href, $label]): ?>
          <li><a href="<?= htmlspecialchars($href) ?>" <?= str_starts_with($href, 'http') ? 'target="_blank" rel="noopener"' : '' ?>><?= htmlspecialchars($label) ?></a></li>
        <?php endforeach ?>
      </ul>
    </div>
  </section>

  <section class="card" aria-labelledby="idea-title">
    <h2 id="idea-title"><?= $t('about.idea.title') ?></h2>
    <p><?= $t('about.idea.text') ?></p>
    <img src="/examples/bookcase.svg" alt="" width="1000" height="760" loading="lazy" class="about-art">
  </section>

  <section class="card" aria-labelledby="how-title">
    <h2 id="how-title"><?= $t('about.how.title') ?></h2>
    <ol class="about-steps">
      <li><?= $t('about.how.scan') ?></li>
      <li><?= $t('about.how.engine') ?></li>
      <li><?= $t('about.how.server') ?></li>
    </ol>
  </section>

  <section class="card" aria-labelledby="data-title">
    <h2 id="data-title"><?= $t('about.data.title') ?></h2>
    <ul class="about-data">
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
