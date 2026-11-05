<?php
// this is used to pull the css for the dash and the layout for the website (i.e the logo the title serach bar and profile logo also the side bar and links to the other main pages)
require_once 'include/fishnet-layout.php';
require_once 'include/permissions.php';
require_once 'database/dbinfo.php';

// ---------- This month's numbers for the three stat cards ----------
// The month is the server's current month (America/New_York, set by the layout). Ranges are
// [first of month, first of next month) so the date indexes can be used.
$monthStart = new DateTime('first day of this month 00:00');
$nextStart  = (clone $monthStart)->modify('+1 month');
$lyStart    = (clone $monthStart)->modify('-1 year');
$lyNext     = (clone $lyStart)->modify('+1 month');
$lmStart    = (clone $monthStart)->modify('-1 month');    // last month
$monthShort = $monthStart->format('M');     // "Nov"
$ymd = fn(DateTime $d) => $d->format('Y-m-d');

function dash_row($con, $sql, array $dates) {
  $stmt = $con->prepare($sql);
  $stmt->bind_param(str_repeat('s', count($dates)), ...$dates);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc() ?: [];
  $stmt->close();
  return $row;
}

$stats = ['cash' => 0.0, 'cash_ly' => 0.0, 'cash_lm' => 0.0, 'items' => 0.0, 'item_value' => 0.0, 'hours' => 0.0, 'volunteers' => 0];
$statsError = false;
// Same gates as the pages that own the data: donations.php for gift totals; donor permissions govern
// volunteer records (see fish_can_log_hours). A user without the permission sees "No access" instead.
$canGifts = fish_can('donations', 'view');
$canHours = fish_can('donors', 'view');
try {
  $con = ($canGifts || $canHours) ? connect() : null;
  if ($canGifts) {
    $r = dash_row($con, 'SELECT COALESCE(SUM(amount), 0) AS total FROM donations WHERE donation_date >= ? AND donation_date < ?',
                  [$ymd($monthStart), $ymd($nextStart)]);
    $stats['cash'] = (float) ($r['total'] ?? 0);
    $r = dash_row($con, 'SELECT COALESCE(SUM(amount), 0) AS total FROM donations WHERE donation_date >= ? AND donation_date < ?',
                  [$ymd($lyStart), $ymd($lyNext)]);
    $stats['cash_ly'] = (float) ($r['total'] ?? 0);
    $r = dash_row($con, 'SELECT COALESCE(SUM(amount), 0) AS total FROM donations WHERE donation_date >= ? AND donation_date < ?',
                  [$ymd($lmStart), $ymd($monthStart)]);
    $stats['cash_lm'] = (float) ($r['total'] ?? 0);
    $r = dash_row($con, 'SELECT COALESCE(SUM(quantity), 0) AS items, COALESCE(SUM(estimated_value), 0) AS value
                         FROM non_cash_donations WHERE date_received >= ? AND date_received < ?',
                  [$ymd($monthStart), $ymd($nextStart)]);
    $stats['items'] = (float) ($r['items'] ?? 0);
    $stats['item_value'] = (float) ($r['value'] ?? 0);
  }
  if ($canHours) {
    $r = dash_row($con, 'SELECT COALESCE(SUM(hours), 0) AS hours, COUNT(DISTINCT donor_id) AS people
                         FROM volunteer_hours WHERE activity_date >= ? AND activity_date < ?',
                  [$ymd($monthStart), $ymd($nextStart)]);
    $stats['hours'] = (float) ($r['hours'] ?? 0);
    $stats['volunteers'] = (int) ($r['people'] ?? 0);
  }
  if ($con) { mysqli_close($con); }
} catch (mysqli_sql_exception $e) {
  // A database fault must not print the exception into the page; log it and show dashes.
  error_log('dashboard.php: ' . $e->getMessage());
  $statsError = true;
}
function dash_num($n) { return rtrim(rtrim(number_format((float) $n, 1), '0'), '.'); }

// Percent change against a comparison total; null when there is nothing to compare with.
function dash_change($now, $then) { return $then > 0 ? (int) round(($now - $then) / $then * 100) : null; }
// One comparison line for the cash card, e.g. "▲ 8% from last Nov" or "No gifts in Oct to compare".
function dash_compare_line($change, $what) {
  if ($change === null) { return '<p class="sub">No gifts ' . h($what) . ' to compare</p>'; }
  return '<p class="sub ' . ($change >= 0 ? 'up' : 'down') . '">' . ($change >= 0 ? '&#9651;' : '&#9661;') . ' '
       . abs($change) . '% from ' . h($what) . '</p>';
}
$lastMonthShort = $lmStart->format('M');
fishnet_open('Dashboard', 'dashboard', 'dashboard.css');
?>
<!-- Html Section -->
      <section class="welcome-row">
        <div>
          <!-- needs connection to what the role of user is -->
          <p class="role">Administrator</p>
          <!-- need connection to what the users name is -->
          <h1>Welcome back, Charity!</h1>
        </div>
        <div class="actions">
          <!-- link to add a new donation (same gate as addDonation.php) -->
          <?php if (fish_can_record_donations()): ?>
          <a href="addDonation.php" class="btn btn-yellow">+Add donation</a>
          <?php endif; ?>
          <!-- link to add a new donor (UF2-99) -->
          <?php if (fish_can('donors', 'edit')): ?>
          <a href="addDonor.php" class="btn btn-outline">+Add Donor</a>
          <?php endif; ?>
          <!-- link to log a communication (addCommunication.php) -->
          <?php if (fish_can('communications', 'edit')): ?>
          <a href="addCommunication.php" class="btn btn-outline">+Log Communication</a>
          <?php endif; ?>
        </div>
      </section>

      <section class="stats">
        <!-- this month's cash gifts (donations.donation_date), compared with last month and the same month last year -->
        <div class="card stat">
          <p class="label"><?= h($monthShort) ?> Cash Donations</p>
          <p class="big"><?= $statsError || !$canGifts ? '&mdash;' : '$' . number_format($stats['cash']) ?></p>
          <?php if (!$canGifts): ?>
          <p class="sub">No access to donations</p>
          <?php elseif ($statsError): ?>
          <p class="sub">Could not load</p>
          <?php else: ?>
          <?= dash_compare_line(dash_change($stats['cash'], $stats['cash_lm']), $lastMonthShort) ?>
          <?= dash_compare_line(dash_change($stats['cash'], $stats['cash_ly']), 'last ' . $monthShort) ?>
          <?php endif; ?>
        </div>
        <!-- this month's non-cash items (non_cash_donations.date_received): total quantity and estimated value -->
        <div class="card stat">
          <p class="label"><?= h($monthShort) ?> Non Cash Donations</p>
          <p class="big"><?= $statsError || !$canGifts ? '&mdash;' : dash_num($stats['items']) ?></p>
          <p class="sub"><?= !$canGifts ? 'No access to donations' : ($statsError ? 'Could not load' : 'Est Value $' . number_format($stats['item_value'])) ?></p>
        </div>
        <!-- this month's volunteer hours (volunteer_hours.activity_date) and how many different people logged them -->
        <div class="card stat">
          <p class="label"><?= h($monthShort) ?> Volunteer hours</p>
          <p class="big"><?= $statsError || !$canHours ? '&mdash;' : dash_num($stats['hours']) ?></p>
          <p class="sub"><?= !$canHours ? 'No access to volunteer records' : ($statsError ? 'Could not load' : $stats['volunteers'] . ' volunteer' . ($stats['volunteers'] === 1 ? '' : 's') . ' this month') ?></p>
        </div>
        <div class="card stat alert">
          <!-- not in this sprint (FAKE) -->
          <p class="label">The last Import had</p>
          <p class="big">9</p>
          <p class="sub">Entry conflicts that still need to be resolved</p>
        </div>
      </section>

      <section class="lower">
        <!-- not in this sprint (FAKE) -->
        <div class="card chart-card">
          <h2>Giving Trends</h2>
          <div class="legend">
            <span><i class="sw noncash"></i>Non Cash</span>
            <span><i class="sw cash"></i>Cash</span>
          </div>
          <div class="chart">
            <div class="y-axis">
                              <span>$12,500</span>
                              <span>$10,000</span>
                              <span>$7,500</span>
                              <span>$5,000</span>
                              <span>$2,500</span>
                              <span>$0</span>
                          </div>
            <div class="bars">
                              <div class="bar-col">
                  <div class="bar" title="Cash $5,000 / Non cash $2,000">
                    <div class="seg noncash" style="height: 16%"></div>
                    <div class="seg cash" style="height: 40%"></div>
                  </div>
                  <span class="x-label">Sept 2025</span>
                </div>
                              <div class="bar-col">
                  <div class="bar" title="Cash $300 / Non cash $700">
                    <div class="seg noncash" style="height: 5.6%"></div>
                    <div class="seg cash" style="height: 2.4%"></div>
                  </div>
                  <span class="x-label">Sept 2026</span>
                </div>
                              <div class="bar-col">
                  <div class="bar" title="Cash $10,000 / Non cash $1,000">
                    <div class="seg noncash" style="height: 8%"></div>
                    <div class="seg cash" style="height: 80%"></div>
                  </div>
                  <span class="x-label">Oct 2025</span>
                </div>
                              <div class="bar-col">
                  <div class="bar" title="Cash $1,000 / Non cash $1,000">
                    <div class="seg noncash" style="height: 8%"></div>
                    <div class="seg cash" style="height: 8%"></div>
                  </div>
                  <span class="x-label">Oct 2026</span>
                </div>
                              <div class="bar-col">
                  <div class="bar" title="Cash $500 / Non cash $7,500">
                    <div class="seg noncash" style="height: 60%"></div>
                    <div class="seg cash" style="height: 4%"></div>
                  </div>
                  <span class="x-label">Nov 2025</span>
                </div>
                              <div class="bar-col">
                  <div class="bar" title="Cash $7,500 / Non cash $2,500">
                    <div class="seg noncash" style="height: 20%"></div>
                    <div class="seg cash" style="height: 60%"></div>
                  </div>
                  <span class="x-label">Nov 2026</span>
                </div>
                          </div>
          </div>
          <p class="x-title">Category</p>
        </div>

        <div class="card activity">
          <!-- not in this sprint (FAKE) -->
          <h2>New activity</h2>
          <ul>
            <li class="empty">No new activity yet.</li>
          </ul>
        </div>
      </section>

<?php fishnet_close(); ?>
