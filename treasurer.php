<?php
// Treasurer: a detailed log of money in (cash donations) and money out (expenses) for a date range and fund,
// then donations vs expenses year over year (UF2-163..167). Data comes from database/dbTreasurer.php; the
// fund is the campaign's Fund / designation, General Fund when there is none.
// Still TODO: "+ Add expense" (UF2-168) and "Download CSV" need their own pages / handlers.
require_once 'include/fishnet-layout.php';
require_once 'include/permissions.php';
require_once 'database/dbTreasurer.php';
$canView = fish_can('donations', 'view');   // expenses have no permission area of their own

// ---------- filters (GET, validated) ----------
// Both dates must be real calendar dates, or the whole range falls back to this month: keeping one half of
// a bad range would silently widen it (e.g. this month's start .. 1901).
if (treasurer_valid_date($_GET['from'] ?? null) && treasurer_valid_date($_GET['to'] ?? null)) {
  [$from, $to] = [$_GET['from'], $_GET['to']];
  if ($from > $to) { [$from, $to] = [$to, $from]; }
} else {
  [$from, $to] = [date('Y-m-01'), date('Y-m-t')];
}
$show = in_array($_GET['show'] ?? '', TREASURER_SHOW, true) ? $_GET['show'] : 'all';
$span = (int) fish_str($_GET, 'years') === 5 ? 5 : 3;
$funds = [];
$fund  = '';
$log   = [];
$years = [];
$totals = ['in' => 0.0, 'out' => 0.0, 'donations' => 0, 'expenses' => 0];
$loadError = false;
if ($canView) {
  try {
    $funds  = treasurer_funds();
    $fund   = in_array($_GET['fund'] ?? '', $funds, true) ? $_GET['fund'] : '';
    $log    = treasurer_log($from, $to, $show, $fund);
    $totals = treasurer_totals($from, $to, $fund);
    $years  = treasurer_years($span, $fund);
  } catch (mysqli_sql_exception $e) {
    // A database fault must not print the exception into the page; log it and render empty figures.
    error_log('treasurer.php: ' . $e->getMessage());
    $loadError = true;
    $funds = []; $fund = ''; $log = []; $years = [];
    $totals = ['in' => 0.0, 'out' => 0.0, 'donations' => 0, 'expenses' => 0];
  }
}

// ---------- the cards (UF2-164..167): the range and fund, whatever the "show" filter ----------
$in = $totals['in'];
$out = $totals['out'];
$net = round($in - $out, 2);
$entries = $totals['donations'] + $totals['expenses'];
$money = fn(float $n) => '$' . number_format($n, 2);
$rangeLabel = date('M j', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));

// chart scale: the tallest bar fills 100%
$max = 0.0;
foreach ($years as $y) { $max = max($max, (float) $y['donations'], (float) $y['expenses']); }
$pct = fn($v) => $max > 0 ? round((float) $v / $max * 100, 1) : 0;
$short = function ($v) {
  $v = (float) $v;
  return $v >= 1000 ? '$' . rtrim(rtrim(number_format($v / 1000, 1), '0'), '.') . 'k' : '$' . number_format($v);
};

fishnet_open('Treasurer', 'treasurer', ['donor.css', 'forms.css', 'treasurer.css']);
?>

      <section class="page-head">
        <div>
          <p class="crumbs">Treasurer</p>
          <h1>Treasurer</h1>
          <p class="lede">Every dollar in and out, then the big picture</p>
        </div>
        <div class="head-actions">
          <a href="#" class="btn btn-outline">+ Add expense</a>
          <a href="#" class="btn btn-yellow">Download CSV</a>
        </div>
      </section>

      <?php if (!$canView): ?>
      <div class="notice" role="status">Your account cannot view donations.</div>
      <?php else: ?>
      <?php if ($loadError): ?><div class="notice" role="alert">Could not load treasurer figures. Please try again shortly.</div><?php endif; ?>
      <section class="tiles">
        <div class="tile"><p class="label">Money in</p><p class="num in">+<?= h($money($in)) ?></p><p class="sub"><?= h($rangeLabel) ?></p></div>
        <div class="tile"><p class="label">Money out</p><p class="num out">&minus;<?= h($money($out)) ?></p><p class="sub"><?= h($rangeLabel) ?></p></div>
        <div class="tile"><p class="label">Net</p><p class="num"><?= $net < 0 ? '&minus;' : '' ?><?= h($money(abs($net))) ?></p><p class="sub">in minus out</p></div>
        <div class="tile"><p class="label">Entries</p><p class="num"><?= (int) $entries ?></p><p class="sub">donations and expenses</p></div>
      </section>

      <!-- ===== Detailed log ===== -->
      <section class="card table-card tr-section">
        <div class="tr-head">
          <div>
            <h2 class="tr-title">Detailed log</h2>
            <p class="tr-note">Newest first</p>
          </div>
          <form class="tr-filters" method="get" action="treasurer.php">
            <label>From <input type="date" name="from" value="<?= h($from) ?>"></label>
            <label>To <input type="date" name="to" value="<?= h($to) ?>"></label>
            <select name="show" aria-label="Show">
              <option value="all">All entries</option>
              <option value="donations"<?= $show === 'donations' ? ' selected' : '' ?>>Donations only</option>
              <option value="expenses"<?= $show === 'expenses' ? ' selected' : '' ?>>Expenses only</option>
            </select>
            <select name="fund" aria-label="Fund">
              <option value="">All funds</option>
              <?php foreach ($funds as $f): ?><option value="<?= h($f) ?>"<?= $f === $fund ? ' selected' : '' ?>><?= h($f) ?></option><?php endforeach; ?>
            </select>
            <input type="hidden" name="years" value="<?= $span ?>">
            <button type="submit" class="btn btn-outline tr-apply">Apply</button>
          </form>
        </div>
        <div class="table-wrap">
          <table class="tr-log">
            <thead><tr><th>Date</th><th>Type</th><th>From / paid to</th><th>Detail</th><th>Fund</th><th class="num-col">Amount</th></tr></thead>
            <tbody>
              <?php foreach ($log as $r): $isIn = $r['kind'] === 'donation'; ?>
              <tr>
                <td><?= h(date('M j', strtotime($r['date']))) ?></td>
                <td><span class="kind <?= $isIn ? 'k-in' : 'k-out' ?>"><?= $isIn ? 'Donation' : 'Expense' ?></span></td>
                <td><?= h($r['who']) ?></td>
                <td><?= h($r['detail']) ?></td>
                <td><?= h($r['fund']) ?></td>
                <td class="amt <?= $isIn ? 'plus' : 'minus' ?>"><?= $isIn ? '+' : '&minus;' ?><?= h($money((float) $r['amount'])) ?></td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$log): ?><tr><td colspan="6" class="empty">No donations or expenses between these dates.</td></tr><?php endif; ?>
            </tbody>
            <tfoot><tr><td colspan="5">Net for <?= h($rangeLabel) ?></td><td class="amt"><?= $net < 0 ? '&minus;' : '' ?><?= h($money(abs($net))) ?></td></tr></tfoot>
          </table>
        </div>
      </section>

      <!-- ===== Year over year chart ===== -->
      <section class="card table-card tr-section">
        <div class="tr-head">
          <div>
            <h2 class="tr-title">Donations vs expenses, year over year</h2>
            <p class="tr-note">Totals for each calendar year<?= $fund !== '' ? ' · ' . h($fund) : ', all funds' ?></p>
          </div>
          <form class="tr-filters" method="get" action="treasurer.php">
            <input type="hidden" name="from" value="<?= h($from) ?>">
            <input type="hidden" name="to" value="<?= h($to) ?>">
            <input type="hidden" name="show" value="<?= h($show) ?>">
            <input type="hidden" name="fund" value="<?= h($fund) ?>">
            <select name="years" aria-label="Years shown" onchange="this.form.submit()">
              <option value="3">Last 3 years</option>
              <option value="5"<?= $span === 5 ? ' selected' : '' ?>>Last 5 years</option>
            </select>
          </form>
        </div>
        <?php if (!$years): ?>
          <p class="chart-empty">No yearly totals yet.</p>
        <?php else: ?>
          <div class="yoy" role="img" aria-label="Donations and expenses by year">
            <?php foreach ($years as $y): ?>
            <div class="yoy-bars">
              <i class="d" style="height:<?= $pct($y['donations']) ?>%"><b><?= h($short($y['donations'])) ?></b></i>
              <i class="e" style="height:<?= $pct($y['expenses']) ?>%"><b><?= h($short($y['expenses'])) ?></b></i>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="yoy-years">
            <?php foreach ($years as $y): ?><span><?= (int) $y['year'] ?><?= (int) $y['year'] === (int) date('Y') ? ' (to date)' : '' ?></span><?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="legend"><span><i class="d"></i>Donations</span><span><i class="e"></i>Expenses</span></div>
      </section>
      <?php endif; ?>

<?php fishnet_close(); ?>
