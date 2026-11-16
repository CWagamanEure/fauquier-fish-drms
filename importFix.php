<?php
// Fix one import row that had an error or looked like a duplicate: its cells as a form, the problem on top.
// Saving posts action=fix to importHandler.php, which re-checks the row (and imports it when the batch has
// already been imported). ?row=<id>[&back=preview]
require_once 'include/fishnet-layout.php';
require_once 'database/dbImports.php';

$row = import_row_get((int) fish_str($_GET, 'row'));
$batch = $row ? import_batch_get((int) $row['batch_id']) : null;
if (!$row || !$batch || $batch['status'] === 'cancelled' || !in_array($row['status'], ['error', 'duplicate'], true)) {
    header('Location: dataImports.php', true, 303);
    exit;
}
$type = $batch['import_type'];
if (!import_can($type)) {
    http_response_code(403);
    fishnet_open('Fix import row', 'imports', ['donor.css', 'forms.css']);
    echo '<div class="form-errors" role="alert">You do not have access to imports.</div>';
    fishnet_close();
    exit;
}
$toPreview = fish_str($_GET, 'back') === 'preview';
$cells = import_cells($type, $row['data']);

fishnet_open('Fix import row', 'imports', ['donor.css', 'forms.css', 'imports.css']);
?>

      <section class="page-head">
        <div>
          <p class="crumbs"><a href="dataImports.php">Data &amp; Imports</a> &rsaquo; <a href="importPreview.php?id=<?= (int) $batch['id'] ?>"><?= h($batch['file_name']) ?></a> &rsaquo; <strong>Row <?= (int) $row['sheet_row'] ?></strong></p>
          <h1>Fix row <?= (int) $row['sheet_row'] ?></h1>
          <p class="lede"><?= h(IMPORT_TYPES[$type]['label']) ?> &middot; <?= h($batch['file_name']) ?></p>
        </div>
      </section>

      <div class="form-errors" role="alert">
        <?= fish_str($_GET, 'failed') !== '' ? 'Still not saved: ' : '' ?><?= h((string) $row['problem']) ?>
      </div>

      <form class="card form-card fix-form" method="post" action="importHandler.php">
        <?= auth_csrf_field() ?>
        <input type="hidden" name="action" value="fix">
        <input type="hidden" name="row" value="<?= (int) $row['id'] ?>">
        <?php if ($toPreview): ?><input type="hidden" name="back" value="preview"><?php endif; ?>
        <div class="fix-grid">
          <?php foreach (IMPORT_TYPES[$type]['columns'] as $h => [$required]): $id = 'c' . md5($h); ?>
          <div class="field">
            <label for="<?= $id ?>"><?= h($h) ?><?= $required ? ' *' : '' ?></label>
            <input type="text" id="<?= $id ?>" name="cells[<?= h($h) ?>]" value="<?= h($cells[$h]) ?>">
          </div>
          <?php endforeach; ?>
        </div>
        <p class="fine">Dates as MM/DD/YYYY. The template's Instructions sheet lists every column's allowed values.</p>
        <div class="preview-actions">
          <button type="submit" class="btn btn-yellow"><?= $batch['status'] === 'imported' ? 'Save &amp; import' : 'Save &amp; re-check' ?></button>
          <a class="btn btn-outline" href="<?= $toPreview ? 'importPreview.php?id=' . (int) $batch['id'] : 'dataImports.php' ?>">Cancel</a>
        </div>
      </form>

<?php fishnet_close(); ?>
