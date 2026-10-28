<?php
// One campaign's detail page: header and goal tiles from the campaign_summary view, tabs from the gift, volunteer
// and communication tables, all through database/dbCampaigns.php.
require_once 'include/fishnet-layout.php';
require_once 'include/permissions.php';
require_once 'database/dbCampaigns.php';

if (!fish_can('campaigns', 'view')) {
  http_response_code(403);
  exit('Your account cannot view campaigns.');
}

$id   = (int) ($_GET['id'] ?? 0);
$kind = fish_str($_GET, 'kind');
$page = max(1, (int) ($_GET['page'] ?? 1));

$c = null;
try {
  $c = $id > 0 ? campaign_get($id) : null;
  if ($c) {
    $people     = campaign_donor_counts($id);
    $giftPage   = campaign_gifts($c, $kind, $page);
    $volunteers = campaign_volunteers($id);
    $comms      = campaign_communications($id);
    $commCount  = campaign_comm_count($id);
    $weeks      = campaign_weekly_cash($c);
    $top        = campaign_top_donors($c);
  }
} catch (mysqli_sql_exception $e) {
  // A database fault must not print the exception into the page; log it and go back to the list with a notice.
  error_log('campaign.php: ' . $e->getMessage());
  header('Location: campaigns.php?error=load', true, 303);
  exit;
}
if (!$c) { header('Location: campaigns.php', true, 303); exit; }   // unknown id: back to the list

$saved      = fish_str($_GET, 'saved') === '1';
$canEdit    = fish_can('campaigns', 'edit');
$canGive    = fish_can('donations', 'edit');
$gifts      = $giftPage['rows'];
$kind       = $giftPage['kind'];
$giftTotal  = $c['cash_gift_count'] + $c['item_gift_count'];
$type       = $c['campaign_type'];
$showCash   = in_array($type, ['cash', 'both'], true);
$showItems  = in_array($type, ['items', 'both'], true);
$showShifts = $type === 'volunteers' || $c['volunteer_goal'] !== null || $c['shifts'] > 0;

$pct = fn($have, $goal) => $goal > 0 ? (int) round($have / $goal * 100) : 0;
function fmt_date($d) { return $d ? date('M j, Y', strtotime($d)) : '&mdash;'; }
function fmt_hours($n) { return rtrim(rtrim(number_format((float) $n, 1), '0'), '.'); }
function short_money($n) { return $n >= 1000 ? '$' . number_format($n / 1000, 1) . 'k' : '$' . number_format($n); }
function comm_type_label($t) { return h($t === 'in_person' ? 'In person' : ucfirst(str_replace('_', ' ', (string) $t))); }
function comm_badge($status) {
  $warn = in_array($status, ['bounced', 'failed'], true);
  return '<span class="status ' . ($warn ? 'pending' : 'active') . '">' . h(ucfirst((string) $status)) . '</span>';
}
function donor_link($donorId, $name) {
  $name = (string) $name;
  if ((int) $donorId <= 0 || (int) $donorId === CAMPAIGN_ANONYMOUS_DONOR_ID) { return h($name); }
  return '<a href="donorProfile.php?id=' . (int) $donorId . '">' . h($name) . '</a>';
}
function gift_url($id, $kind, $page = 1) {
  return '?' . http_build_query(['id' => $id, 'kind' => $kind, 'page' => $page]);
}

// "Nov 1 – Nov 24, 2026 · 12 days left · Owner: Charity Coleman · Drop-off: FISH pantry"
$bits = [];
if ($c['start_date'] && $c['end_date']) {
  $bits[] = date('M j', strtotime($c['start_date'])) . ' – ' . date('M j, Y', strtotime($c['end_date']));
}
$today = new DateTime('today');
if ($c['status'] === 'active' && $c['end_date']) {
  $left = (int) $today->diff(new DateTime($c['end_date']))->format('%r%a');
  $bits[] = $left <= 0 ? 'Ends today' : $left . ' day' . ($left === 1 ? '' : 's') . ' left';
} elseif ($c['status'] === 'upcoming' && $c['start_date']) {
  $until = (int) $today->diff(new DateTime($c['start_date']))->format('%r%a');
  $bits[] = 'Starts in ' . $until . ' day' . ($until === 1 ? '' : 's');
}
if ($c['owner_name'] !== null && $c['owner_name'] !== '') { $bits[] = 'Owner: ' . $c['owner_name']; }
if ($c['location'] !== null && $c['location'] !== '')     { $bits[] = 'Drop-off: ' . $c['location']; }
$meta = implode(' · ', $bits);

$weekMax = $weeks ? max(1, max(array_column($weeks, 1))) : 1;
$from = $giftPage['total'] ? ($giftPage['page'] - 1) * CAMPAIGN_GIFTS_PER_PAGE + 1 : 0;
$to   = min($giftPage['page'] * CAMPAIGN_GIFTS_PER_PAGE, $giftPage['total']);

fishnet_open($c['name'], 'campaigns', ['donor.css', 'forms.css', 'campaigns.css']);
?>

      <p class="crumbs"><a href="campaigns.php">Campaigns</a> &rsaquo; <strong><?= h($c['name']) ?></strong></p>
      <?php if ($saved): ?><div class="notice" role="status">Campaign saved.</div><?php endif; ?>

      <section class="card campaign-head">
        <div class="who">
          <h1><?= h($c['name']) ?> <span class="status <?= h($c['status']) ?>"><?= h(CAMPAIGN_STATUS_LABELS[$c['status']] ?? $c['status']) ?></span> <span class="ctype <?= h($type) ?>"><?= h(CAMPAIGN_TYPES[$type] ?? $type) ?></span></h1>
          <?php if ($meta !== ''): ?><p><?= h($meta) ?></p><?php endif; ?>
          <?php if ((string) $c['description'] !== ''): ?><p class="desc"><?= h($c['description']) ?></p><?php endif; ?>
        </div>
        <div class="head-btns">
          <?php if ($canEdit): ?><a href="addCampaign.php?id=<?= (int) $c['id'] ?>" class="btn btn-outline small">Edit</a><?php endif; ?>
          <a href="#" class="btn btn-outline small">Send update</a>
          <?php if ($canGive): ?><a href="addDonation.php?campaign_id=<?= (int) $c['id'] ?>" class="btn btn-yellow small">+ Donation</a><?php endif; ?>
        </div>
      </section>

      <section class="tiles">
        <?php if ($showCash): $p = $pct($c['cash_raised'], (float) $c['cash_goal']); ?>
        <div class="tile goal-tile"><p class="label">Cash raised</p><p class="num">$<?= number_format($c['cash_raised']) ?></p>
          <?php if ($c['cash_goal'] > 0): ?>
          <div class="bar"><span style="width: <?= min(100, $p) ?>%"></span></div>
          <p class="sub"><?= $p ?>% of $<?= number_format((float) $c['cash_goal']) ?> goal</p>
          <?php else: ?><p class="sub">No cash goal set</p><?php endif; ?></div>
        <?php endif; ?>
        <?php if ($showItems): $p = $pct($c['items_collected'], (float) $c['item_goal']); ?>
        <div class="tile goal-tile"><p class="label">Items collected</p><p class="num"><?= h(campaign_num($c['items_collected'])) ?> <?= h($c['item_unit']) ?></p>
          <?php if ($c['item_goal'] > 0): ?>
          <div class="bar yellow"><span style="width: <?= min(100, $p) ?>%"></span></div>
          <p class="sub"><?= $p ?>% of <?= h(campaign_num($c['item_goal'])) ?> goal</p>
          <?php else: ?><p class="sub">No item goal set</p><?php endif; ?></div>
        <?php endif; ?>
        <?php if ($showShifts): $p = $pct($c['shifts'], (float) $c['volunteer_goal']); ?>
        <div class="tile goal-tile"><p class="label">Volunteer shifts</p><p class="num"><?= (int) $c['shifts'] ?><?= $c['volunteer_goal'] > 0 ? ' / ' . (int) $c['volunteer_goal'] : '' ?></p>
          <?php if ($c['volunteer_goal'] > 0): ?>
          <div class="bar navy"><span style="width: <?= min(100, $p) ?>%"></span></div>
          <p class="sub"><?= $p ?>% filled</p>
          <?php else: ?><p class="sub"><?= h(fmt_hours($c['volunteer_hours'])) ?> hours so far</p><?php endif; ?></div>
        <?php endif; ?>
        <div class="tile goal-tile"><p class="label">Donors</p><p class="num"><?= number_format($people['donors']) ?></p>
          <p class="sub"><?= number_format($people['first_time']) ?> first-time donor<?= $people['first_time'] === 1 ? '' : 's' ?></p></div>
      </section>

      <section class="card tabs-card campaign-tabs">
        <input type="radio" name="tab" id="tGifts" checked>
        <input type="radio" name="tab" id="tVols">
        <input type="radio" name="tab" id="tComms">
        <div class="tab-bar">
          <label for="tGifts">Donations <span class="count"><?= (int) $giftTotal ?></span></label>
          <label for="tVols">Volunteers <span class="count"><?= (int) $c['shifts'] ?></span></label>
          <label for="tComms">Communications <span class="count"><?= (int) $commCount ?></span></label>
        </div>

        <!-- Donations: the filter and the paging are done by the server, so they cover every gift, not just this page -->
        <div class="panel-tab p-gifts">
          <div class="gifts-layout">
            <div>
              <div class="tab-actions left">
                <div class="pills">
                  <?php foreach (['all' => 'All', 'cash' => 'Cash', 'noncash' => 'Items'] as $k => $label): ?>
                    <a href="<?= h(gift_url($id, $k)) ?>" class="pill<?= $kind === $k ? ' on' : '' ?>"><?= $label ?></a>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="table-wrap"><table>
                <thead><tr><th>Date</th><th>Donor</th><th>Type</th><th>Amount / items</th></tr></thead>
                <tbody>
                  <?php foreach ($gifts as $g): ?>
                  <tr>
                    <td><?= fmt_date($g['date']) ?></td>
                    <td><strong><?= donor_link($g['donor_id'], $g['donor']) ?></strong></td>
                    <td><span class="kind <?= h($g['kind']) ?>"><?= $g['kind'] === 'cash' ? 'Cash' : 'Items' ?></span></td>
                    <td><?= h($g['detail']) ?></td>
                  </tr>
                  <?php endforeach; ?>
                  <?php if (!$gifts): ?><tr><td colspan="4" class="empty">No gifts of this type yet.</td></tr><?php endif; ?>
                </tbody>
              </table></div>
              <div class="pager">
                <p>Showing <?= $from ?>&ndash;<?= $to ?> of <?= number_format($giftPage['total']) ?></p>
                <div class="pages">
                  <?php if ($giftPage['page'] > 1): ?><a href="<?= h(gift_url($id, $kind, $giftPage['page'] - 1)) ?>" class="pg wide">&lsaquo; Prev</a><?php endif; ?>
                  <?php if ($giftPage['page'] < $giftPage['pages']): ?><a href="<?= h(gift_url($id, $kind, $giftPage['page'] + 1)) ?>" class="pg wide">Next &rsaquo;</a><?php endif; ?>
                </div>
              </div>
            </div>

            <aside class="gift-side">
              <?php if ($weeks): ?>
              <h3>Weekly progress (cash)</h3>
              <div class="week-chart" role="img" aria-label="Cash raised per week">
                <?php foreach ($weeks as $i => [$label, $amt]): ?>
                <div class="wk">
                  <span class="wk-val"><?= h(short_money($amt)) ?></span>
                  <span class="wk-bar<?= $i === count($weeks) - 1 ? ' latest' : '' ?>" style="height: <?= (int) round($amt / $weekMax * 100) ?>%"></span>
                  <span class="wk-lbl"><?= h($label) ?></span>
                </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
              <?php if ($top): ?>
              <h3>Top donors</h3>
              <?php foreach ($top as [$donorId, $name, $amt]): ?>
              <p class="top-row"><span><?= donor_link($donorId, $name) ?></span><strong><?= h($amt) ?></strong></p>
              <?php endforeach; ?>
              <?php endif; ?>
            </aside>
          </div>
        </div>

        <!-- Volunteers -->
        <div class="panel-tab p-vols">
          <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>Volunteer</th><th>Hours</th><th>Job</th></tr></thead>
            <tbody>
              <?php foreach ($volunteers as $v): ?>
              <tr><td><?= fmt_date($v['activity_date']) ?></td><td><strong><?= donor_link($v['donor_id'], $v['volunteer_name']) ?></strong></td><td><?= h(fmt_hours($v['hours'])) ?></td><td><?= $v['job'] !== null && $v['job'] !== '' ? h($v['job']) : '&mdash;' ?></td></tr>
              <?php endforeach; ?>
              <?php if (!$volunteers): ?><tr><td colspan="4" class="empty">No volunteer shifts yet.</td></tr><?php endif; ?>
            </tbody>
            <?php if ($volunteers): ?>
            <tfoot><tr><td>Total</td><td><?= (int) $c['shifts'] > count($volunteers) ? 'latest ' . count($volunteers) . ' of ' . (int) $c['shifts'] . ' shifts shown' : '' ?></td><td><?= h(fmt_hours($c['volunteer_hours'])) ?> hrs</td><td></td></tr></tfoot>
            <?php endif; ?>
          </table></div>
        </div>

        <!-- Communications -->
        <div class="panel-tab p-comms">
          <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>To / from</th><th>Type</th><th>Subject</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($comms as $m): ?>
              <tr><td><?= fmt_date($m['occurred_at']) ?></td><td><?= donor_link($m['donor_id'], $m['donor_name']) ?></td><td><?= comm_type_label($m['comm_type']) ?></td><td><?= $m['subject'] !== null && $m['subject'] !== '' ? h($m['subject']) : '&mdash;' ?></td><td><?= comm_badge($m['status']) ?></td></tr>
              <?php endforeach; ?>
              <?php if (!$comms): ?><tr><td colspan="5" class="empty">No communications logged yet.</td></tr><?php endif; ?>
            </tbody>
          </table></div>
        </div>
      </section>

      <script src="js/campaigns.js"></script>
<?php fishnet_close(); ?>
