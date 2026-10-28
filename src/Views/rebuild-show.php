<?php
defined('RECUPBUILD') || (http_response_code(404) && exit);
/**
 * GET /rebuilds/{id}: how to make the photographed object: its 3D reconstruction,
 * the parts to cut, the tools, the hardware and the steps.
 *
 * @var callable $t @var callable $e @var array $rebuild
 */
use RecupBuild\Services\Drawing;

$n = [Drawing::class, 'n'];
$result = $rebuild['result'];
$placements = [];
$sizes = [];
foreach ($result['placements'] as $part) {
    // The 3D view reads parts as placements cut from their own imaginary piece.
    $placements[] = ['number' => $part['number'], 'part' => $part['part'], 'piece_id' => $part['number'], 'x' => 0, 'y' => 0,
        'length_mm' => $part['length_mm'], 'width_mm' => $part['width_mm'], 'thickness_mm' => $part['thickness_mm']];
    $sizes[$part['number']] = $part + ['metal' => $part['material'] === 'metal'];
}
$pieces = array_map(static fn (array $part): array => ['id' => $part['number'], 'kind' => $part['material'] === 'metal' ? 'sheet_metal' : 'plank',
    'length_mm' => $part['length_mm'], 'width_mm' => $part['width_mm'], 'thickness_mm' => $part['thickness_mm'], 'outline' => []], $result['placements']);
$scene = json_encode(['placements' => $placements, 'assembly' => $result['assembly'], 'pieces' => $pieces, 'photo' => null], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// Identical parts on one line: "4 × Leg".
$lines = [];
foreach ($result['placements'] as $part) {
    $key = $part['part'];
    $lines[$key] ??= ['label' => $part['label'], 'count' => 0, 'size' => $n((float) $part['length_mm']) . ' × ' . $n((float) $part['width_mm']) . ' × ' . $n((float) $part['thickness_mm']) . ' mm', 'material' => $part['material']];
    ++$lines[$key]['count'];
}
$dimensions = [];
foreach (['width' => 'width_mm', 'depth' => 'depth_mm', 'height' => 'height_mm'] as $label => $field) {
    if (!empty($result['dimensions'][$field])) {
        $dimensions[] = html_entity_decode($t('param.' . $label), ENT_QUOTES, 'UTF-8') . ' ' . $result['dimensions'][$field] . ' mm';
    }
}
?>
<section class="wrap rebuild-head" aria-labelledby="rebuild-title">
  <img class="rebuild-photo" src="/rebuilds/<?= (int) $rebuild['id'] ?>/photo" alt="" width="480" height="360">
  <div>
    <p class="stamp"><?= $t('rebuild.stamp') ?></p>
    <h1 id="rebuild-title"><?= $e($result['name']) ?></h1>
    <p class="lead left"><?= $e($result['description']) ?></p>
    <ul class="chips">
      <li><?= $t('rebuild.difficulty.' . $result['difficulty']) ?></li>
      <?php if (!empty($result['hours'])): ?><li><?= $t('rebuild.hours', ['hours' => $result['hours']]) ?></li><?php endif ?>
      <?php if ($dimensions !== []): ?><li class="figure"><?= $e(implode(' · ', $dimensions)) ?></li><?php endif ?>
    </ul>
    <p class="muted small"><?= $t('rebuild.estimate') ?></p>
  </div>
</section>

<div class="wrap result">
  <?php if (!empty($result['assembly'])): ?>
    <section class="card" aria-labelledby="render-title">
      <h2 id="render-title"><?= $t('rebuild.render') ?></h2>
      <p class="muted small"><?= $t('rebuild.renderHint') ?></p>
      <div class="viewer" data-scene3d="<?= $e($scene) ?>" role="img" aria-label="<?= $t('plan.renderAlt') ?>">
        <?= Drawing::isometric($result['assembly'], $sizes) ?>
      </div>
      <script type="module" src="/js/assembly3d.js"></script>
    </section>
  <?php endif ?>

  <section class="card" aria-labelledby="parts-title">
    <h2 id="parts-title"><?= $t('rebuild.parts') ?></h2>
    <div class="table-scroll">
      <table class="inventory">
        <thead><tr><th scope="col"><?= $t('rebuild.count') ?></th><th scope="col"><?= $t('plan.part') ?></th><th scope="col"><?= $t('plan.size') ?></th><th scope="col"><?= $t('rebuild.material') ?></th></tr></thead>
        <tbody>
          <?php foreach ($lines as $line): ?>
            <tr><th scope="row" class="figure"><?= $line['count'] ?> ×</th><td><?= $e($line['label']) ?></td><td class="figure"><?= $line['size'] ?></td><td><?= $t('rebuild.material.' . $line['material']) ?></td></tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
  </section>

  <div class="two">
    <section class="card" aria-labelledby="tools-title">
      <h2 id="tools-title"><?= $t('rebuild.tools') ?></h2>
      <ul class="tool-list">
        <?php foreach ($result['tools'] as $tool): ?>
          <li><strong><?= $e($tool['name']) ?></strong><?php if ($tool['why'] !== ''): ?><span class="muted small"><?= $e($tool['why']) ?></span><?php endif ?></li>
        <?php endforeach ?>
      </ul>
    </section>
    <section class="card" aria-labelledby="hardware-title">
      <h2 id="hardware-title"><?= $t('rebuild.hardware') ?></h2>
      <?php if ($result['hardware'] === []): ?>
        <p class="muted"><?= $t('rebuild.noHardware') ?></p>
      <?php else: ?>
        <ul class="hardware">
          <?php foreach ($result['hardware'] as $item): ?><li><span><?= $e($item['name']) ?></span><span class="figure muted"><?= $e($item['quantity']) ?></span></li><?php endforeach ?>
        </ul>
      <?php endif ?>
    </section>
  </div>

  <section class="card" aria-labelledby="steps-title">
    <h2 id="steps-title"><?= $t('rebuild.steps') ?></h2>
    <ol class="steps">
      <?php foreach ($result['steps'] as $step): ?><li><?= $e($step) ?></li><?php endforeach ?>
    </ol>
    <?php if (!empty($result['tips'])): ?>
      <h3 class="tips-title"><?= $t('rebuild.tips') ?></h3>
      <ul class="checks"><?php foreach ($result['tips'] as $tip): ?><li><?= $e($tip) ?></li><?php endforeach ?></ul>
    <?php endif ?>
  </section>

  <div class="row actions">
    <button class="button" type="button" data-print><?= $t('plan.print') ?></button>
    <a class="button ghost" href="/scan"><?= $t('rebuild.withMyWood') ?></a>
    <a class="button ghost" href="/rebuild"><?= $t('rebuild.another') ?></a>
  </div>
</div>
