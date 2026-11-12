<?php
// One import batch: after upload it shows what will be imported and what will not (Import / Cancel);
// after import it shows what was added and what still needs review. ?id=<batch>
require_once 'include/fishnet-layout.php';
require_once 'database/dbImports.php';

$batch = import_batch_get((int) fish_str($_GET, 'id'));
if (!$batch || $batch['status'] === 'cancelled') {
    header('Location: dataImports.php', true, 303);
    exit;
}
$type = $batch['import_type'];
if (!import_can($type)) {
    http_response_code(403);
    fishnet_open('Import', 'imports', ['donor.css', 'forms.css', 'imports.css']);
    echo '<div class="form-errors" role="alert">You do not have access to imports.</div>';
    fishnet_close();
    exit;
}
$counts = import_batch_counts((int) $batch['id']);
$problems = import_batch_rows((int) $batch['id'], ['error', 'duplicate']);
$ready = $batch['status'] === 'checked' ? import_batch_rows((int) $batch['id'], ['ready']) : [];
$checked = $batch['status'] === 'checked';   // 'importing' (in progress or interrupted) shows like a finished import
$label = IMPORT_TYPES[$type]['label'];

fishnet_open('Import ' . $label, 'imports', ['donor.css', 'forms.css', 'imports.css']);
?>

      <section class="page-head">
        <div>
          <p class="crumbs"><a href="dataImports.php">Data &amp; Imports</a> &rsaquo; <strong><?= h($batch['file_name']) ?></strong></p>
          <h1><?= $checked ? 'Check your import' : 'Import finished' ?></h1>
          <p class="lede"><?= h($label) ?> &middot; <?= h($batch['file_name']) ?> &middot; <?= (int) $batch['rows_total'] ?> rows</p>
        </div>
      </section>

      <section class="tiles">
        <?php if ($checked): ?>
        <div class="tile"><p class="label">Ready to import</p><p class="num"><?= $counts['ready'] ?></p><p class="sub">passed every check</p></div>
        <?php else: ?>
        <div class="tile"><p class="label">Imported</p><p class="num"><?= $counts['imported'] ?></p><p class="sub">records added</p></div>
        <?php endif; ?>
        <div class="tile<?= $counts['error'] ? ' warn' : '' ?>"><p class="label">Errors</p><p class="num"><?= $counts['error'] ?></p><p class="sub">need fixing</p></div>
        <div class="tile<?= $counts['duplicate'] ? ' warn' : '' ?>"><p class="label">Possible duplicates</p><p class="num"><?= $counts['duplicate'] ?></p><p class="sub">already in FISH Net</p></div>
        <div class="tile"><p class="label">Skipped</p><p class="num"><?= $counts['skipped'] ?></p><p class="sub">set aside</p></div>
      </section>

      <?php if (fish_str($_GET, 'done') !== ''): ?>
      <div class="form-success" role="status">Imported <?= $counts['imported'] ?> <?= $counts['imported'] === 1 ? 'row' : 'rows' ?>.<?= $counts['error'] + $counts['duplicate'] ? ' The rest are in Needs review below and on Data &amp; Imports.' : '' ?></div>
      <?php endif; ?>

      <?php if ($checked): ?>
      <section class="card table-card data-section">
        <h2 class="section-title">Nothing is saved yet</h2>
        <p class="section-note">Import adds the <?= $counts['ready'] ?> ready <?= $counts['ready'] === 1 ? 'row' : 'rows' ?>. Rows with errors or possible duplicates are not added; you can fix them below now, or later from Needs review.</p>
        <div class="preview-actions">
          <form method="post" action="importHandler.php">
            <?= auth_csrf_field() ?><input type="hidden" name="action" value="import"><input type="hidden" name="batch" value="<?= (int) $batch['id'] ?>">
            <button type="submit" class="btn btn-yellow"<?= $counts['ready'] ? '' : ' disabled' ?>>Import <?= $counts['ready'] ?> <?= $counts['ready'] === 1 ? 'row' : 'rows' ?></button>
          </form>
          <form method="post" action="importHandler.php">
            <?= auth_csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="batch" value="<?= (int) $batch['id'] ?>">
            <button type="submit" class="btn btn-outline">Cancel import</button>
          </form>
        </div>
      </section>
      <?php endif; ?>

      <section class="card table-card data-section">
        <h2 class="section-title">Needs review <span class="count"><?= count($problems) ?></span></h2>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Row</th><th>Data</th><th>Problem</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($problems as $r): $dup = $r['status'] === 'duplicate'; ?>
              <tr>
                <td><?= (int) $r['sheet_row'] ?></td>
                <td><?= h(import_row_summary($type, $r['data'])) ?></td>
                <td class="problem"><span class="issue <?= $dup ? 'dup' : 'error' ?>"><?= $dup ? 'Possible duplicate' : 'Error' ?></span><span class="sub"><?= h((string) $r['problem']) ?></span></td>
                <td class="actions">
                  <a class="edit" href="importFix.php?row=<?= (int) $r['id'] ?>&amp;back=preview">Fix</a>
                  <?php if ($dup): ?>
                  <form method="post" action="importHandler.php" class="inline-form"><?= auth_csrf_field() ?><input type="hidden" name="action" value="force"><input type="hidden" name="row" value="<?= (int) $r['id'] ?>"><input type="hidden" name="back" value="preview"><button type="submit" class="edit">Import anyway</button></form>
                  <?php endif; ?>
                  <form method="post" action="importHandler.php" class="inline-form"><?= auth_csrf_field() ?><input type="hidden" name="action" value="skip"><input type="hidden" name="row" value="<?= (int) $r['id'] ?>"><input type="hidden" name="back" value="preview"><button type="submit" class="edit">Skip</button></form>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$problems): ?><tr><td colspan="4" class="empty">Every row passed.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <?php if ($ready): ?>
      <section class="card table-card data-section">
        <h2 class="section-title">Ready to import</h2>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Row</th><th>Data</th></tr></thead>
            <tbody>
              <?php foreach (array_slice($ready, 0, 200) as $r): ?>
              <tr><td><?= (int) $r['sheet_row'] ?></td><td><?= h(import_row_summary($type, $r['data'])) ?></td></tr>
              <?php endforeach; ?>
              <?php if (count($ready) > 200): ?><tr><td colspan="2" class="empty">and <?= count($ready) - 200 ?> more</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>

      <p class="data-section"><a href="dataImports.php" class="btn btn-outline">Back to Data &amp; Imports</a></p>

<?php fishnet_close(); ?>
