<?php
/**
 * GET /projects: four projects for this wood, each with a picture of the assembled
 * object (drawn by Services\Drawing from its layout), its size and whether it fits.
 * Choosing one plans it (the model writes the steps: the waiting screen shows).
 *
 * @var callable $t @var callable $e @var list<array> $cards @var string $source @var bool $demo
 * @var list<array> $pieces @var string $csrf
 */
use RecupBuild\Services\Drawing;

$metal = [];
foreach ($pieces as $piece) {
    $metal[(int) $piece['id']] = $piece['kind'] === 'sheet_metal';
}
/** A text of the language files, unescaped (it is escaped where printed), or the fallback. */
$words = static fn (string $key, string $fallback): string => ($text = html_entity_decode($t($key), ENT_QUOTES, 'UTF-8')) === $key ? $fallback : $text;
?>
<section class="wrap app-head" aria-labelledby="projects-title">
  <h1 id="projects-title"><?= $t('projects.title') ?></h1>
  <p class="lead left"><?= $t('projects.lead') ?></p>
  <p class="pill-note <?= $source === 'engine' ? 'warn' : '' ?>"><?= $t('projects.' . $source) ?></p>
</section>

<?php
// What can be built first; the card keeps its index (the form posts it).
$order = array_keys($cards);
usort($order, static fn (int $a, int $b): int => (($cards[$a]['result']['plan'] ?? null) === null) <=> (($cards[$b]['result']['plan'] ?? null) === null) ?: $a <=> $b);
?>
<ul class="wrap project-grid">
  <?php foreach ($order as $index): ?>
    <?php $card = $cards[$index]; ?>
    <?php
      $definition = $card['definition'];
      $result = $card['result'];
      $plan = $result['plan'] ?? null;
      $id = (string) $definition['id'];
      $name = $definition['name'] ?? $words('project.' . $id, $id);
      $text = $definition['description'] ?? $words('home.projects.' . $id, '');
      $status = $plan === null ? 'impossible' : (count($result['iterations']) > 1 ? 'adjusted' : 'possible');
      $sizes = [];
      foreach ($plan['placements'] ?? [] as $placement) {
          $sizes[$placement['number']] = $placement + ['metal' => $metal[$placement['piece_id']] ?? false];
      }
      $labels = [];
      foreach ($definition['params'] ?? [] as $key => $spec) {
          $labels[$key] = $spec['label'] ?? $words('param.' . $key, $key);
      }
    ?>
    <li class="project <?= $status ?>">
      <div class="project-picture">
        <?php if ($plan !== null && !empty($card['assembly'])): ?>
          <?= Drawing::isometric($card['assembly'], $sizes, $name) ?>
        <?php else: ?>
          <span class="no-picture" aria-hidden="true">✕</span>
        <?php endif ?>
      </div>
      <div class="project-body">
        <div class="project-head">
          <h2><?= $e($name) ?></h2>
          <span class="status <?= $status ?>"><?= $t('projects.' . $status) ?></span>
        </div>
        <?php if ($text !== ''): ?><p class="muted"><?= $e($text) ?></p><?php endif ?>
        <?php if ($plan !== null): ?>
          <p class="figure small"><?= $e(implode(' · ', array_map(
              static fn (string $key, int|float $value): string => ($labels[$key] ?? $key) . ' ' . $value . ' mm',
              array_keys($plan['params']),
              $plan['params'],
          ))) ?></p>
          <form method="post" action="/projects/choose" <?= $demo ? '' : 'data-loading="' . $t('projects.planning') . '" data-seconds="25"' ?>>
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="card" value="<?= $index ?>">
            <button class="button wide" type="submit"><?= $t('projects.choose') ?></button>
          </form>
        <?php else: ?>
          <p class="reason"><?= $t('reason.' . ($result['reason'] ?? 'not_enough_wood')) ?></p>
        <?php endif ?>
      </div>
    </li>
  <?php endforeach ?>
</ul>

<p class="wrap"><a class="button ghost" href="/inventory"><?= $t('projects.back') ?></a></p>
