<?php
// One campaign's detail page: FRONT END ONLY. The data below is fake so the page can be styled and reviewed.
// Backend TODO: load the campaign by ?id= and fill $c, $gifts, $volunteers, $comms, $weeks and $top.
require_once 'include/fishnet-layout.php';

// ---------- fake data ----------
$c = ['id' => 1, 'name' => 'Thanksgiving Food Drive', 'status' => 'active', 'type' => 'both',
      'meta' => 'Nov 1 – Nov 24, 2026 · 36 days left · Owner: Charity Coleman · Drop-off: FISH pantry',
      'description' => 'Holiday meals for about 300 local families.',
      'cash' => 8160, 'cash_goal' => 12000, 'items' => 1240, 'item_goal' => 2000, 'unit' => 'cans',
      'shifts' => 34, 'shift_goal' => 40, 'donors' => 87, 'first_time' => 19];
$gifts = [
  ['date' => 'Oct 18, 2026', 'donor' => 'J. Miller',            'kind' => 'cash',    'amount' => '$250.00'],
  ['date' => 'Oct 17, 2026', 'donor' => 'Main St. Church',      'kind' => 'noncash', 'amount' => '320 cans · Canned vegetables'],
  ['date' => 'Oct 16, 2026', 'donor' => 'R. Patel',             'kind' => 'cash',    'amount' => '$100.00'],
  ['date' => 'Oct 15, 2026', 'donor' => 'Oak Ridge Elementary', 'kind' => 'noncash', 'amount' => '58 boxes · Dry goods'],
  ['date' => 'Oct 14, 2026', 'donor' => 'L. Chen',              'kind' => 'cash',    'amount' => '$75.00'],
  ['date' => 'Oct 12, 2026', 'donor' => 'Warrenton Rotary',     'kind' => 'cash',    'amount' => '$1,500.00'],
  ['date' => 'Oct 10, 2026', 'donor' => 'Grace Community Church','kind' => 'noncash', 'amount' => '240 cans · Soup'],
  ['date' => 'Oct 9, 2026',  'donor' => 'M. Alvarez',           'kind' => 'cash',    'amount' => '$40.00'],
];
$volunteers = [
  ['date' => 'Oct 17, 2026', 'name' => 'A. Ruiz',   'hours' => 6, 'job' => 'Sorting cans'],
  ['date' => 'Oct 16, 2026', 'name' => 'T. Barker', 'hours' => 4, 'job' => 'Pantry intake'],
  ['date' => 'Oct 12, 2026', 'name' => 'M. Lopez',  'hours' => 3, 'job' => 'Drop-off table'],
];
$comms = [
  ['date' => 'Oct 15, 2026', 'name' => 'All past donors',  'type' => 'Email', 'subject' => 'Help us feed 300 families', 'status' => 'Sent'],
  ['date' => 'Oct 13, 2026', 'name' => 'Main St. Church',  'type' => 'Phone', 'subject' => 'Drop-off times',            'status' => 'Logged'],
];
$weeks = [['Wk 1', 1900], ['Wk 2', 2600], ['Wk 3', 3100], ['This wk', 560]];
$top = [['Warrenton Rotary', '$1,500'], ['Main St. Church', '320 cans'], ['J. Miller', '$250']];
// ---------- end fake data ----------

$pct = fn($a, $b) => $b ? (int) round($a / $b * 100) : 0;
$weekMax = max(array_column($weeks, 1));

fishnet_open($c['name'], 'campaigns', ['donor.css', 'forms.css', 'campaigns.css']);
?>

      <p class="crumbs"><a href="campaigns.php">Campaigns</a> &rsaquo; <strong><?= h($c['name']) ?></strong></p>

      <section class="card campaign-head">
        <div class="who">
          <h1><?= h($c['name']) ?> <span class="status active">Active</span> <span class="ctype both">Cash + items</span></h1>
          <p><?= h($c['meta']) ?></p>
          <p class="desc"><?= h($c['description']) ?></p>
        </div>
        <div class="head-btns">
          <a href="addCampaign.php?id=<?= $c['id'] ?>" class="btn btn-outline small">Edit</a>
          <a href="#" class="btn btn-outline small">Send update</a>
          <a href="addDonation.php" class="btn btn-yellow small">+ Donation</a>
        </div>
      </section>

      <section class="tiles">
        <div class="tile goal-tile"><p class="label">Cash raised</p><p class="num">$<?= number_format($c['cash']) ?></p>
          <div class="bar"><span style="width: <?= $pct($c['cash'], $c['cash_goal']) ?>%"></span></div>
          <p class="sub"><?= $pct($c['cash'], $c['cash_goal']) ?>% of $<?= number_format($c['cash_goal']) ?> goal</p></div>
        <div class="tile goal-tile"><p class="label">Items collected</p><p class="num"><?= number_format($c['items']) ?> <?= $c['unit'] ?></p>
          <div class="bar yellow"><span style="width: <?= $pct($c['items'], $c['item_goal']) ?>%"></span></div>
          <p class="sub"><?= $pct($c['items'], $c['item_goal']) ?>% of <?= number_format($c['item_goal']) ?> goal</p></div>
        <div class="tile goal-tile"><p class="label">Volunteer shifts</p><p class="num"><?= $c['shifts'] ?> / <?= $c['shift_goal'] ?></p>
          <div class="bar navy"><span style="width: <?= $pct($c['shifts'], $c['shift_goal']) ?>%"></span></div>
          <p class="sub"><?= $pct($c['shifts'], $c['shift_goal']) ?>% filled</p></div>
        <div class="tile goal-tile"><p class="label">Donors</p><p class="num"><?= $c['donors'] ?></p>
          <p class="sub"><?= $c['first_time'] ?> first-time donors</p></div>
      </section>

      <section class="card tabs-card campaign-tabs">
        <input type="radio" name="tab" id="tGifts" checked>
        <input type="radio" name="tab" id="tVols">
        <input type="radio" name="tab" id="tComms">
        <div class="tab-bar">
          <label for="tGifts">Donations <span class="count">124</span></label>
          <label for="tVols">Volunteers <span class="count"><?= count($volunteers) ?></span></label>
          <label for="tComms">Communications <span class="count"><?= count($comms) ?></span></label>
        </div>

        <!-- Donations -->
        <div class="panel-tab p-gifts">
          <div class="gifts-layout">
            <div data-filter-list>
              <div class="tab-actions left">
                <div class="pills">
                  <button type="button" class="pill on" data-filter="all">All</button>
                  <button type="button" class="pill" data-filter="cash">Cash</button>
                  <button type="button" class="pill" data-filter="noncash">Items</button>
                </div>
              </div>
              <div class="table-wrap"><table>
                <thead><tr><th>Date</th><th>Donor</th><th>Type</th><th>Amount / items</th><th></th></tr></thead>
                <tbody>
                  <?php foreach ($gifts as $g): ?>
                  <tr data-status="<?= $g['kind'] ?>">
                    <td><?= $g['date'] ?></td>
                    <td><strong><?= h($g['donor']) ?></strong></td>
                    <td><span class="kind <?= $g['kind'] ?>"><?= $g['kind'] === 'cash' ? 'Cash' : 'Items' ?></span></td>
                    <td><?= h($g['amount']) ?></td>
                    <td><a href="#" class="select">Edit</a></td>
                  </tr>
                  <?php endforeach; ?>
                  <tr class="no-match" hidden><td colspan="5" class="empty">No gifts of this type yet.</td></tr>
                </tbody>
              </table></div>
              <div class="pager">
                <p>Showing <span data-shown><?= count($gifts) ?></span> of 124</p>
                <div class="pages"><a href="#" class="pg wide">&lsaquo; Prev</a><a href="#" class="pg wide">Next &rsaquo;</a></div>
              </div>
            </div>

            <aside class="gift-side">
              <h3>Weekly progress (cash)</h3>
              <div class="week-chart" role="img" aria-label="Cash raised per week">
                <?php foreach ($weeks as $i => [$label, $amt]): ?>
                <div class="wk">
                  <span class="wk-val">$<?= number_format($amt / 1000, 1) ?>k</span>
                  <span class="wk-bar<?= $i === count($weeks) - 1 ? ' latest' : '' ?>" style="height: <?= round($amt / $weekMax * 100) ?>%"></span>
                  <span class="wk-lbl"><?= $label ?></span>
                </div>
                <?php endforeach; ?>
              </div>
              <h3>Top donors</h3>
              <?php foreach ($top as [$name, $amt]): ?>
              <p class="top-row"><span><?= h($name) ?></span><strong><?= h($amt) ?></strong></p>
              <?php endforeach; ?>
            </aside>
          </div>
        </div>

        <!-- Volunteers -->
        <div class="panel-tab p-vols">
          <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>Volunteer</th><th>Hours</th><th>Job</th></tr></thead>
            <tbody>
              <?php foreach ($volunteers as $v): ?>
              <tr><td><?= $v['date'] ?></td><td><strong><?= h($v['name']) ?></strong></td><td><?= $v['hours'] ?></td><td><?= h($v['job']) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td>Total</td><td></td><td><?= array_sum(array_column($volunteers, 'hours')) ?> hrs</td><td></td></tr></tfoot>
          </table></div>
        </div>

        <!-- Communications -->
        <div class="panel-tab p-comms">
          <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>To / from</th><th>Type</th><th>Subject</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($comms as $m): ?>
              <tr><td><?= $m['date'] ?></td><td><?= h($m['name']) ?></td><td><?= $m['type'] ?></td><td><?= h($m['subject']) ?></td><td><span class="status active"><?= $m['status'] ?></span></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table></div>
        </div>
      </section>

      <script src="js/campaigns.js"></script>
<?php fishnet_close(); ?>
