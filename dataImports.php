<?php
// Data & Imports: Import (pick a type, get its template, upload one file) beside Export (one row per CSV export),
// then Recent imports beside Needs review.
// Imports: the form posts `type` + `file` to importHandler.php, which checks every row and shows
// importPreview.php; Recent imports and Needs review read import_batches / import_rows (migration 018).
// templates/fishnet-import-<type>.xlsx are the templates (their headings must match IMPORT_TYPES).
//   - each export form posts `export` (+ from/to or year) to exportData.php, which streams a CSV. `cash` (UF2-133),
//     `noncash` (UF2-134) and `hours` (UF2-138) are built; the other rows' buttons say "Coming soon" until their case exists in exportData.php
require_once 'include/fishnet-layout.php';
require_once 'database/dbImports.php';

// key => [label, what the template holds]
$imports = [
  'donors'    => ['Donors',             'names, contact info, addresses and organizations'],
  'cash'      => ['Cash donations',     'date, donor, amount, method and campaign'],
  'noncash'   => ['Non-cash donations', 'date, donor, item, quantity, weight (lbs) and campaign'],
  'volunteer' => ['Volunteer hours',    'volunteer, date, start and end time, and job'],
  'comms'     => ['Communications',     'who was contacted, when, how and the subject'],
];

// key => [title, description, filter: false | 'range' | 'year']
$exports = [
  'emails'       => ['All emails',                         'Every donor email address, one per row.',                            false],
  'cash'         => ['Cash donations',                     'Every cash gift between two dates.',                                 'range'],
  'noncash'      => ['Non-cash donations',                 'Item gifts between two dates, with quantity and weight.',            'range'],
  'donor-totals' => ['Donors with hours and donations',    'Each donor with their total giving and volunteer hours.',            false],
  'donor-list'   => ['Donor list with contact and status', 'Contact info and giving status (Active, LYBUNT, SYBUNT, Inactive).', false],
  'tax-receipt'  => ['Donor tax receipts',                 'One row per donor: their total giving for the chosen year.',        'year'],
  'hours'        => ['All volunteer hours',                'Every volunteer hours entry.',                                       false],
  'lapsed'       => ['LYBUNT and SYBUNT contact list',     'Contact info for donors who gave before but not this year.',        false],
];

// Exports that exportData.php can answer, with the permission each needs.
$exportReady = ['cash' => fish_can_export_donations(), 'noncash' => fish_can_export_donations(),
                'hours' => fish_can_export_hours()];
$exportErrors = [
  'dates'       => 'Pick a From date on or before the To date.',
  'unavailable' => 'That export is not available yet.',
  'failed'      => 'The export could not be made. Try again.',
];
$exportError = $exportErrors[fish_str($_GET, 'export_error')] ?? null;

$importErrors = [
  'type'   => 'Pick what you are importing.',
  'nofile' => 'Choose a .csv or .xlsx file to upload.',
  'size'   => 'The file is too big (2 MB at most). Split it into smaller files.',
  'failed' => 'The import could not be saved. Try again.',
];
$importError = fish_str($_GET, 'import_error');
$importMessage = $importError === 'file' ? (string) ($_SESSION['import_message'] ?? 'The file could not be read.') : ($importErrors[$importError] ?? null);
unset($_SESSION['import_message']);
$reviewNotes = ['imported' => 'Row imported.', 'skipped' => 'Row skipped.', 'failed' => 'That row still has a problem; use Fix.'];
$reviewNote = $reviewNotes[fish_str($_GET, 'review')] ?? (fish_str($_GET, 'import_done') === 'cancelled' ? 'Import cancelled. Nothing was saved.' : null);
$canImportAny = false;
foreach (array_keys($imports) as $k) { $canImportAny = $canImportAny || import_can($k); }
try {
  if ($canImportAny) { import_recover_stale(); }   // an Import that was interrupted puts its leftover rows in review
  $recent = $canImportAny ? array_values(array_filter(import_recent(), fn($b) => import_can($b['import_type']))) : [];
  $review = $canImportAny ? array_values(array_filter(import_review_rows(), fn($r) => import_can($r['import_type']))) : [];
} catch (mysqli_sql_exception $e) {   // before migration 018 is run, the tables do not exist yet
  error_log('dataImports: ' . $e->getMessage());
  $recent = $review = [];
}
$typeLabel = fn($t) => IMPORT_TYPES[$t]['label'] ?? $t;
$thisYear = (int) date('Y');

fishnet_open('Data & Imports', 'imports', ['donor.css', 'forms.css', 'imports.css']);
?>

      <section class="page-head">
        <div>
          <p class="crumbs">Data &amp; Imports</p>
          <h1>Data &amp; Imports</h1>
          <p class="lede">Bring spreadsheets in, download data out</p>
        </div>
        <a href="itemValues.php" class="btn btn-outline">Item values</a>
      </section>

      <div class="data-cols">
        <!-- ===== Import ===== -->
        <section class="card table-card">
          <h2 class="section-title">Import</h2>
          <?php if ($importMessage !== null): ?><div class="form-errors" role="alert"><?= h($importMessage) ?></div><?php endif; ?>
          <?php if ($reviewNote !== null): ?><div class="form-success" role="status"><?= h($reviewNote) ?></div><?php endif; ?>
          <form class="import-form" method="post" action="importHandler.php" enctype="multipart/form-data">
            <?= auth_csrf_field() ?>
            <input type="hidden" name="action" value="upload">
            <p class="step">1. Pick what you are importing</p>
            <div class="type-chips" role="radiogroup" aria-label="Import type">
              <?php $first = true; foreach ($imports as $key => [$label]): ?>
              <label class="type-chip"><input type="radio" name="type" value="<?= h($key) ?>"<?= $first ? ' checked' : '' ?>><span><?= h($label) ?></span></label>
              <?php $first = false; endforeach; ?>
            </div>

            <p class="step">2. Fill in the template, then upload it</p>
            <?php foreach ($imports as $key => [$label, $holds]): ?>
            <p class="template-note" data-type="<?= h($key) ?>">
              <a href="templates/fishnet-import-<?= h($key) ?>.xlsx" class="template-link" download>Download <?= h($label) ?> template (.xlsx)</a>
              <small>Columns: <?= h($holds) ?>.</small>
            </p>
            <?php endforeach; ?>

            <label class="drop-zone">
              <input type="file" name="file" accept=".csv,.xlsx" required>
              <span class="drop-text">Drag a .csv or .xlsx file here<br>or <u>choose a file</u></span>
              <span class="drop-file" hidden></span>
            </label>

            <button type="submit" class="btn btn-yellow upload-btn"<?= $canImportAny ? '' : ' disabled title="You do not have access to imports"' ?>>Upload &amp; check</button>
            <p class="fine">Nothing is saved until the file is checked for errors and duplicates.</p>
          </form>
        </section>

        <!-- ===== Export ===== -->
        <section class="card table-card" id="export">
          <h2 class="section-title">Export</h2>
          <p class="section-note">Downloads a CSV for Excel or Google Sheets</p>
          <?php if ($exportError !== null): ?><div class="form-errors" role="alert"><?= h($exportError) ?></div><?php endif; ?>
          <ul class="export-list">
            <?php foreach ($exports as $key => [$title, $desc, $filter]):
              $built = array_key_exists($key, $exportReady);
              $allowed = $built && $exportReady[$key]; ?>
            <li>
              <form class="export-row" method="post" action="<?= $built ? 'exportData.php' : '#' ?>">
                <?= auth_csrf_field() ?>
                <input type="hidden" name="export" value="<?= h($key) ?>">
                <div class="export-text">
                  <strong><?= h($title) ?></strong>
                  <small><?= h($desc) ?></small>
                </div>
                <div class="export-filters">
                  <?php if ($filter === 'range'): ?>
                    <input type="date" name="from" required aria-label="<?= h($title) ?> from">
                    <span>to</span>
                    <input type="date" name="to" required aria-label="<?= h($title) ?> to">
                  <?php elseif ($filter === 'year'): ?>
                    <select name="year" aria-label="Tax year">
                      <?php for ($y = $thisYear; $y >= $thisYear - 5; $y--): ?><option<?= $y === $thisYear - 1 ? ' selected' : '' ?>><?= $y ?></option><?php endfor; ?>
                    </select>
                  <?php endif; ?>
                </div>
                <?php if ($allowed): ?>
                <button type="submit" class="btn btn-outline">Export CSV</button>
                <?php else: ?>
                <button type="submit" class="btn btn-outline" disabled><?= $built ? 'No access' : 'Coming soon' ?></button>
                <?php endif; ?>
              </form>
            </li>
            <?php endforeach; ?>
          </ul>
        </section>
      </div>

      <div class="data-cols even">
        <!-- ===== Recent imports ===== -->
        <section class="card table-card">
          <h2 class="section-title">Recent imports</h2>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Date</th><th>Type</th><th>File</th><th>Rows</th><th>Status</th></tr></thead>
              <tbody>
                <?php foreach ($recent as $b): $open = (int) $b['open_rows']; ?>
                <tr>
                  <td><?= h(date('M j, Y', strtotime($b['created_at']))) ?><span class="sub"><?= h($b['uploaded_by']) ?></span></td>
                  <td><?= h($typeLabel($b['import_type'])) ?></td>
                  <td><a href="importPreview.php?id=<?= (int) $b['id'] ?>"><?= h($b['file_name']) ?></a></td>
                  <td><?= (int) $b['rows_added'] ?> of <?= (int) $b['rows_total'] ?><?php if ($open): ?><span class="sub"><?= $open ?> need review</span><?php endif; ?></td>
                  <td><?php if ($b['status'] === 'checked'): ?><span class="status pending">Not imported yet</span>
                      <?php elseif ($open): ?><span class="status pending">Needs review</span>
                      <?php else: ?><span class="status active">Done</span><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$recent): ?><tr><td colspan="5" class="empty">No imports yet.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>

        <!-- ===== Needs review ===== -->
        <section class="card table-card">
          <h2 class="section-title">Needs review <span class="count"><?= count($review) ?></span></h2>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Import</th><th>Row</th><th>Problem</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($review as $r): $dup = $r['status'] === 'duplicate'; ?>
                <tr>
                  <td><?= h($typeLabel($r['import_type'])) ?><span class="sub"><?= h(date('M j', strtotime($r['batch_at']))) ?> · <?= h($r['file_name']) ?></span></td>
                  <td><?= (int) $r['sheet_row'] ?></td>
                  <td class="problem"><span class="issue <?= $dup ? 'dup' : 'error' ?>"><?= $dup ? 'Possible duplicate' : 'Error' ?></span>
                      <span class="sub"><?= h(import_row_summary($r['import_type'], $r['data'])) ?></span><span class="sub"><?= h((string) $r['problem']) ?></span></td>
                  <td class="actions">
                    <a href="importFix.php?row=<?= (int) $r['id'] ?>" class="edit">Fix</a>
                    <?php if ($dup): ?>
                    <form method="post" action="importHandler.php" class="inline-form"><?= auth_csrf_field() ?><input type="hidden" name="action" value="force"><input type="hidden" name="row" value="<?= (int) $r['id'] ?>"><button type="submit" class="edit">Import anyway</button></form>
                    <?php endif; ?>
                    <form method="post" action="importHandler.php" class="inline-form"><?= auth_csrf_field() ?><input type="hidden" name="action" value="skip"><input type="hidden" name="row" value="<?= (int) $r['id'] ?>"><button type="submit" class="edit">Skip</button></form>
                  </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$review): ?><tr><td colspan="4" class="empty">Nothing needs review.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <script src="js/dataImports.js"></script>
<?php fishnet_close(); ?>
