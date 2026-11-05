<?php
// Data & Imports: Import (pick a type, get its template, upload one file) beside Export (one row per CSV export),
// then Recent imports beside Needs review.
// FRONT END ONLY: no data yet and no handlers. Backend TODO:
//   - the import form posts `type` + `file` to an import handler (not built yet); each template link points at its file
//   - each export form posts `export` (+ from/to or year) to an export handler that streams a CSV
//   - fill $recent (one row per import run) and $review (one row per problem row / possible duplicate)
//   The markup for one row of each table is in the commented example inside it.
require_once 'include/fishnet-layout.php';

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

$recent   = [];   // Backend TODO
$review   = [];   // Backend TODO
$thisYear = (int) date('Y');

fishnet_open('Data & Imports', 'imports', ['donor.css', 'forms.css', 'imports.css']);
?>

      <section class="page-head">
        <div>
          <p class="crumbs">Data &amp; Imports</p>
          <h1>Data &amp; Imports</h1>
          <p class="lede">Bring spreadsheets in, download data out</p>
        </div>
      </section>

      <div class="data-cols">
        <!-- ===== Import ===== -->
        <section class="card table-card">
          <h2 class="section-title">Import</h2>
          <form class="import-form" method="post" action="#" enctype="multipart/form-data">
            <?= auth_csrf_field() ?>
            <p class="step">1. Pick what you are importing</p>
            <div class="type-chips" role="radiogroup" aria-label="Import type">
              <?php $first = true; foreach ($imports as $key => [$label]): ?>
              <label class="type-chip"><input type="radio" name="type" value="<?= h($key) ?>"<?= $first ? ' checked' : '' ?>><span><?= h($label) ?></span></label>
              <?php $first = false; endforeach; ?>
            </div>

            <p class="step">2. Fill in the template, then upload it</p>
            <?php foreach ($imports as $key => [$label, $holds]): ?>
            <p class="template-note" data-type="<?= h($key) ?>">
              <a href="#" class="template-link">Download <?= h($label) ?> template</a>
              <small>Columns: <?= h($holds) ?>.</small>
            </p>
            <?php endforeach; ?>

            <label class="drop-zone">
              <input type="file" name="file" accept=".csv,.xlsx" required>
              <span class="drop-text">Drag a .csv or .xlsx file here<br>or <u>choose a file</u></span>
              <span class="drop-file" hidden></span>
            </label>

            <button type="submit" class="btn btn-yellow upload-btn">Upload &amp; check</button>
            <p class="fine">Nothing is saved until the file is checked for errors and duplicates.</p>
          </form>
        </section>

        <!-- ===== Export ===== -->
        <section class="card table-card">
          <h2 class="section-title">Export</h2>
          <p class="section-note">Downloads a CSV for Excel or Google Sheets</p>
          <ul class="export-list">
            <?php foreach ($exports as $key => [$title, $desc, $filter]): ?>
            <li>
              <form class="export-row" method="post" action="#">
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
                <button type="submit" class="btn btn-outline">Export CSV</button>
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
                <?php /* Example row:
                <tr>
                  <td>Nov 4, 2026<span class="sub">Charity Coleman</span></td><td>Cash donations</td><td>october-gifts.xlsx</td>
                  <td>142<span class="sub">3 need review</span></td>
                  <td><span class="status active">Done</span></td>   (or: status pending "Needs review", status inactive "Failed")
                </tr> */ ?>
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
                <?php /* Example row:
                <tr>
                  <td>Donors<span class="sub">Nov 4 · donors-2026.xlsx</span></td><td>22</td>
                  <td><span class="issue dup">Possible duplicate</span><span class="sub">Jane Smith matches donor #418</span></td>   (or: issue error "Date not valid")
                  <td class="actions"><a href="#" class="edit">Fix</a><a href="#" class="edit">Merge</a><a href="#" class="edit">Skip</a></td>
                </tr> */ ?>
                <?php if (!$review): ?><tr><td colspan="4" class="empty">Nothing needs review.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <script src="js/dataImports.js"></script>
<?php fishnet_close(); ?>
