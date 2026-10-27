<?php
/**
 * GET /inventory: the analysed photo with each piece outlined and numbered, and the
 * table to correct. "Continue" asks the AI for the projects (slow: the waiting screen
 * shows while the browser waits for the next page).
 *
 * @var callable $t @var callable $e @var array $workspace @var string $photoUrl
 * @var array<int, array<string, string>> $issues @var string $csrf
 */
use RecupBuild\Models\Workspace;
use RecupBuild\Services\Drawing;

$n = [Drawing::class, 'n'];
$image = $workspace['image'];
$pieces = $workspace['pieces'];
$issueCount = array_sum(array_map('count', $issues));
$numberSize = max($image['width'], $image['height']) / 40;
?>
<section class="wrap app-head" aria-labelledby="inventory-title">
  <h1 id="inventory-title"><?= $t('inventory.title') ?></h1>
  <p class="lead left"><?= $t('inventory.lead') ?></p>
  <?php if ($workspace['demo']): ?><p class="pill-note"><?= $t('inventory.demo') ?></p><?php endif ?>
</section>

<?php if ($pieces === []): ?>
  <section class="wrap card">
    <p><?= $t('inventory.empty') ?></p>
    <a class="button" href="/scan"><?= $t('scan.title') ?></a>
  </section>
<?php else: ?>
<div class="wrap inventory-grid">
  <!-- The photo, each piece outlined and numbered as in the table. -->
  <figure class="card photo-card">
    <svg viewBox="0 0 <?= $image['width'] ?> <?= $image['height'] ?>" role="img" aria-label="<?= $t('inventory.photoAlt') ?>">
      <image href="<?= $e($photoUrl) ?>" width="<?= $image['width'] ?>" height="<?= $image['height'] ?>"/>
      <?php foreach ($pieces as $piece): ?>
        <?php
          $outline = $piece['outline'] ?? [];
          if (count($outline) !== 4) {
              continue;
          }
          $cx = array_sum(array_column($outline, 0)) / 4;
          $cy = array_sum(array_column($outline, 1)) / 4;
          $bad = isset($issues[(int) $piece['id']]);
        ?>
        <polygon points="<?= $e(implode(' ', array_map(static fn (array $p): string => $n((float) $p[0]) . ',' . $n((float) $p[1]), $outline))) ?>"
                 class="outline <?= $bad ? 'bad' : '' ?>" stroke-width="<?= $n($numberSize / 6) ?>"/>
        <circle cx="<?= $n($cx) ?>" cy="<?= $n($cy) ?>" r="<?= $n($numberSize) ?>" class="tag-dot <?= $bad ? 'bad' : '' ?>"/>
        <text x="<?= $n($cx) ?>" y="<?= $n($cy) ?>" class="tag-number" font-size="<?= $n($numberSize * 1.1) ?>" text-anchor="middle" dominant-baseline="central"><?= (int) $piece['id'] ?></text>
      <?php endforeach ?>
    </svg>
  </figure>

  <!-- The table: every figure can be corrected. -->
  <form class="card table-card" method="post" action="/inventory">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <p class="muted small"><?= $t('inventory.count', ['count' => count($pieces), 'issues' => $issueCount]) ?></p>
    <div class="table-scroll">
      <table class="inventory">
        <thead>
          <tr>
            <th scope="col"><?= $t('inventory.number') ?></th>
            <th scope="col"><?= $t('inventory.kind') ?></th>
            <th scope="col"><?= $t('inventory.length') ?></th>
            <th scope="col"><?= $t('inventory.width') ?></th>
            <th scope="col"><?= $t('inventory.thickness') ?></th>
            <th scope="col"><span class="sr-only"><?= $t('inventory.remove') ?></span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pieces as $piece): ?>
            <?php $id = (int) $piece['id']; ?>
            <tr>
              <th scope="row" class="figure"><?= $id ?></th>
              <td>
                <select name="pieces[<?= $id ?>][kind]" aria-label="<?= $t('inventory.kind') ?> <?= $id ?>">
                  <?php foreach (Workspace::KINDS as $kind): ?>
                    <option value="<?= $kind ?>" <?= $piece['kind'] === $kind ? 'selected' : '' ?>><?= $t('kind.' . $kind) ?></option>
                  <?php endforeach ?>
                </select>
              </td>
              <?php foreach (['length_mm' => 'length', 'width_mm' => 'width', 'thickness_mm' => 'thickness'] as $field => $label): ?>
                <?php $problem = $issues[$id][$field] ?? null; ?>
                <td>
                  <label class="mm <?= $problem ? 'bad' : '' ?>">
                    <span class="sr-only"><?= $t('inventory.' . $label) ?> <?= $id ?></span>
                    <input type="text" inputmode="decimal" name="pieces[<?= $id ?>][<?= $field ?>]"
                           value="<?= $e($piece[$field] === null ? '' : rtrim(rtrim(number_format((float) $piece[$field], 1, '.', ''), '0'), '.')) ?>"
                           <?= $problem ? 'aria-invalid="true"' : '' ?>>
                    <span aria-hidden="true">mm</span>
                  </label>
                  <?php if ($problem): ?><span class="problem"><?= $t('inventory.problem.' . $problem) ?></span><?php endif ?>
                </td>
              <?php endforeach ?>
              <td>
                <label class="remove" title="<?= $t('inventory.removeLabel', ['number' => $id]) ?>">
                  <input type="checkbox" name="pieces[<?= $id ?>][remove]" value="1">
                  <span class="sr-only"><?= $t('inventory.removeLabel', ['number' => $id]) ?></span>
                  <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5 6h10M8 6V4h4v2M6 6l1 10h6l1-10"/></svg>
                </label>
              </td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <div class="row end">
      <button class="button ghost" type="submit" name="action" value="save"><?= $t('inventory.save') ?></button>
      <button class="button" type="submit" name="action" value="continue"
              <?= $workspace['demo'] ? '' : 'data-loading="' . $t('inventory.projecting') . '" data-seconds="70"' ?>><?= $t('inventory.continue') ?> <span aria-hidden="true">→</span></button>
    </div>
  </form>
</div>
<?php endif ?>
