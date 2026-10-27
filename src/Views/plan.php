<?php
/**
 * GET /plans/{id}: the result. The object in 3D (public/js/assembly3d.js, with a still
 * picture until it loads), the final sizes, the cutting plan drawn on the real wood of
 * the photo, the cut parts, the list, the steps and how the engine got there.
 *
 * @var callable $t @var callable $e @var array $plan @var ?array $assembly
 * @var ?array{url: string, width: int, height: int} $photo @var array<int, list<array>> $outlines
 */
use RecupBuild\Services\Drawing;

$n = [Drawing::class, 'n'];
$definition = $plan['definition'];
$result = $plan['result'];
$cut = $result['plan'];
$id = (string) $definition['id'];
$words = static fn (string $key, string $fallback): string => ($text = html_entity_decode($t($key), ENT_QUOTES, 'UTF-8')) === $key ? $fallback : $text;
$name = $definition['name'] ?? $words('project.' . $id, $id);
$demo = ($plan['photo'] ?? null) === 'demo';

// Stock pieces by id, with their outline on the photo when there is one.
$stock = [];
foreach ($plan['pieces'] as $piece) {
    $stock[(int) $piece['id']] = $piece + ['outline' => $outlines[(int) $piece['id']] ?? []];
}
$partLabel = static function (string $part) use ($definition, $words): string {
    foreach ($definition['parts'] ?? [] as $spec) {
        if ($spec['name'] === $part && !empty($spec['label'])) {
            return $spec['label'];
        }
    }

    return $words('part.' . $part, $part);
};
$paramLabel = static fn (string $key): string => $definition['params'][$key]['label'] ?? $words('param.' . $key, $key);
$sizeText = static fn (array $p): string => $n((float) $p['length_mm']) . ' × ' . $n((float) $p['width_mm']) . ' × ' . $n((float) $p['thickness_mm']) . ' mm';

$steps = is_array($result['steps'] ?? null) && $result['steps'] !== [] ? $result['steps'] : null;
if ($steps === null) {
    $prefix = in_array($id, ['shelf', 'crate', 'dryer', 'coop'], true) ? $id : 'generic';
    $steps = array_map(static fn (int $i): string => $words("assembly.{$prefix}.{$i}", ''), [1, 2, 3, 4]);
}

// What the 3D view needs, as JSON for public/js/assembly3d.js.
$sizes = [];
foreach ($cut['placements'] as $placement) {
    $sizes[$placement['number']] = $placement + ['metal' => ($stock[$placement['piece_id']]['kind'] ?? '') === 'sheet_metal'];
}
$scene = json_encode([
    'placements' => $cut['placements'],
    'assembly' => $assembly,
    'pieces' => array_values($stock),
    'photo' => $photo,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
<section class="wrap app-head" aria-labelledby="plan-title">
  <h1 id="plan-title"><?= $t('plan.heading', ['name' => $name]) ?></h1>
  <p class="warning-line"><?= $t('plan.warning') ?></p>
  <?php if ($demo): ?><p class="pill-note"><?= $t('plan.demo') ?></p><?php endif ?>
</section>

<div class="wrap result">
  <?php if ($assembly !== null): ?>
    <!-- The finished object: the still picture until three.js takes over. -->
    <section class="card" aria-labelledby="render-title">
      <h2 id="render-title"><?= $t('plan.render') ?></h2>
      <p class="muted small"><?= $t('plan.renderHint') ?></p>
      <div class="viewer" data-scene3d="<?= $e($scene) ?>" role="img" aria-label="<?= $t('plan.renderAlt') ?>">
        <?= Drawing::isometric($assembly, $sizes) ?>
      </div>
      <script type="module" src="/js/assembly3d.js"></script>
    </section>
  <?php endif ?>

  <section class="card" aria-labelledby="sizes-title">
    <h2 id="sizes-title"><?= $t('plan.sizes') ?></h2>
    <dl class="sizes">
      <?php foreach ($cut['params'] as $key => $value): ?>
        <div><dt><?= $e($paramLabel($key)) ?></dt><dd class="figure"><?= $n((float) $value, 0) ?> mm</dd></div>
      <?php endforeach ?>
    </dl>
  </section>

  <!-- The cutting plan: one drawing per piece of stock, on its real wood when the photo is kept. -->
  <section class="card" aria-labelledby="cut-title">
    <h2 id="cut-title"><?= $t('plan.cut') ?></h2>
    <p class="muted small"><?= $t('plan.cutHint') ?></p>
    <?php foreach ($cut['piece_ids'] as $pieceId): ?>
      <?php
        $piece = $stock[$pieceId] ?? null;
        if ($piece === null) {
            continue;
        }
        $length = max((float) $piece['length_mm'], (float) $piece['width_mm']);
        $width = min((float) $piece['length_mm'], (float) $piece['width_mm']);
        $matrix = $photo !== null ? Drawing::photoMatrix($piece['outline'], $length, $width) : null;
        $parts = array_values(array_filter($cut['placements'], static fn (array $p): bool => $p['piece_id'] === $pieceId));
        $numberSize = max($width / 11, 24);
      ?>
      <figure class="cut-piece">
        <figcaption class="figure"><strong><?= $t('plan.piece', ['id' => $pieceId]) ?></strong> <span class="muted"><?= $n($length, 0) ?> × <?= $n($width, 0) ?> mm</span></figcaption>
        <svg viewBox="0 0 <?= $n($length) ?> <?= $n($width) ?>" role="img" aria-label="<?= $t('plan.piece', ['id' => $pieceId]) ?>">
          <?php if ($matrix !== null): ?>
            <defs>
              <clipPath id="clip-<?= $pieceId ?>"><rect width="<?= $n($length) ?>" height="<?= $n($width) ?>"/></clipPath>
              <?php foreach ($parts as $part): ?>
                <clipPath id="clip-<?= $pieceId ?>-<?= $part['number'] ?>"><rect x="<?= $n($part['x'] + 2) ?>" y="<?= $n($part['y'] + 2) ?>" width="<?= $n(max($part['length_mm'] - 4, 1)) ?>" height="<?= $n(max($part['width_mm'] - 4, 1)) ?>"/></clipPath>
              <?php endforeach ?>
            </defs>
            <g clip-path="url(#clip-<?= $pieceId ?>)">
              <image href="<?= $e($photo['url']) ?>" width="<?= $photo['width'] ?>" height="<?= $photo['height'] ?>" transform="<?= $matrix ?>" preserveAspectRatio="none"/>
              <rect width="<?= $n($length) ?>" height="<?= $n($width) ?>" fill="#fff" fill-opacity="0.6"/>
            </g>
            <?php foreach ($parts as $part): ?>
              <g clip-path="url(#clip-<?= $pieceId ?>-<?= $part['number'] ?>)"><image href="<?= $e($photo['url']) ?>" width="<?= $photo['width'] ?>" height="<?= $photo['height'] ?>" transform="<?= $matrix ?>" preserveAspectRatio="none"/></g>
            <?php endforeach ?>
          <?php endif ?>
          <rect x="1" y="1" width="<?= $n($length - 2) ?>" height="<?= $n($width - 2) ?>" fill="<?= $matrix !== null ? 'none' : '#f6f3ec' ?>" stroke="#1f2a24" stroke-width="4"/>
          <?php foreach ($parts as $part): ?>
            <rect x="<?= $n($part['x'] + 2) ?>" y="<?= $n($part['y'] + 2) ?>" width="<?= $n(max($part['length_mm'] - 4, 1)) ?>" height="<?= $n(max($part['width_mm'] - 4, 1)) ?>"
                  fill="<?= $matrix !== null ? 'none' : '#e9c9ae' ?>" stroke="#2f6b4f" stroke-width="3"/>
            <text x="<?= $n($part['x'] + $part['length_mm'] / 2) ?>" y="<?= $n($part['y'] + $part['width_mm'] / 2) ?>" class="cut-number"
                  font-size="<?= $n($numberSize) ?>" stroke-width="<?= $n($numberSize / 6) ?>" text-anchor="middle" dominant-baseline="central"><?= $part['number'] ?></text>
          <?php endforeach ?>
        </svg>
      </figure>
    <?php endforeach ?>
    <p class="muted small"><?= $t('plan.kerf', ['kerf' => $cut['kerf_mm']]) ?></p>
  </section>

  <section class="card" aria-labelledby="parts-title">
    <h2 id="parts-title"><?= $t('plan.parts') ?></h2>
    <ul class="part-grid">
      <?php foreach ($cut['placements'] as $part): ?>
        <?php
          $piece = $stock[$part['piece_id']] ?? null;
          $matrix = $photo !== null && $piece !== null
              ? Drawing::photoMatrix($piece['outline'], max((float) $piece['length_mm'], (float) $piece['width_mm']), min((float) $piece['length_mm'], (float) $piece['width_mm']))
              : null;
        ?>
        <li>
          <span class="part-picture">
            <svg viewBox="<?= $n($part['x']) ?> <?= $n($part['y']) ?> <?= $n($part['length_mm']) ?> <?= $n($part['width_mm']) ?>" aria-hidden="true">
              <?php if ($matrix !== null): ?>
                <clipPath id="part-<?= $part['number'] ?>"><rect x="<?= $n($part['x']) ?>" y="<?= $n($part['y']) ?>" width="<?= $n($part['length_mm']) ?>" height="<?= $n($part['width_mm']) ?>"/></clipPath>
                <g clip-path="url(#part-<?= $part['number'] ?>)"><image href="<?= $e($photo['url']) ?>" width="<?= $photo['width'] ?>" height="<?= $photo['height'] ?>" transform="<?= $matrix ?>" preserveAspectRatio="none"/></g>
              <?php else: ?>
                <rect x="<?= $n($part['x']) ?>" y="<?= $n($part['y']) ?>" width="<?= $n($part['length_mm']) ?>" height="<?= $n($part['width_mm']) ?>" fill="#e9c9ae"/>
              <?php endif ?>
            </svg>
            <span class="part-number figure"><?= $part['number'] ?></span>
          </span>
          <span class="part-name"><?= $e($partLabel($part['part'])) ?></span>
          <span class="figure muted small"><?= $sizeText($part) ?></span>
          <span class="muted small"><?= $t('plan.from') ?> : <?= $t('plan.piece', ['id' => $part['piece_id']]) ?></span>
        </li>
      <?php endforeach ?>
    </ul>
  </section>

  <section class="card" aria-labelledby="steps-title">
    <h2 id="steps-title"><?= $t('plan.steps') ?></h2>
    <ol class="steps">
      <?php foreach ($steps as $step): ?><li><?= $e($step) ?></li><?php endforeach ?>
    </ol>
  </section>

  <details class="card log">
    <summary><?= $t('plan.log') ?></summary>
    <ol>
      <?php foreach ($result['iterations'] as $iteration): ?>
        <li class="<?= $iteration['status'] ?>">
          <span><?= $t('plan.attempt', ['index' => $iteration['index'], 'placed' => $iteration['placed'], 'total' => $iteration['total']]) ?></span>
          <?php foreach ($iteration['adjustments'] as $change): ?>
            <span class="figure small muted"><?= $e($paramLabel($change['param'])) ?> : <?= (int) $change['from'] ?> → <?= (int) $change['to'] ?> mm</span>
          <?php endforeach ?>
        </li>
      <?php endforeach ?>
    </ol>
  </details>

  <div class="row actions">
    <button class="button" type="button" data-print><?= $t('plan.print') ?></button>
    <a class="button ghost" href="/projects"><?= $t('plan.another') ?></a>
    <a class="button ghost" href="/scan"><?= $t('plan.newScan') ?></a>
  </div>
</div>
