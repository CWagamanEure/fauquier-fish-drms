<?php
// Donor profile: header and tiles from the donor_summary view, four tabs from the activity tables
// (UF2-26, UF2-27, UF2-70, 73-78).
require_once 'include/fishnet-layout.php';
require_once 'include/permissions.php';
require_once 'database/dbDonors.php';

$id = (int) ($_GET['id'] ?? 0);
$loadError = false;
$donor = null;
$cash = $items = $vol = $comms = [];
try {
  $donor = $id > 0 ? donor_get($id) : null;
  if ($donor) {
    $cash  = donor_cash_history($id);
    $items = donor_noncash_history($id);
    $vol   = donor_hours_history($id);
    $comms = donor_communications($id);
  }
} catch (mysqli_sql_exception $e) {
  // A database fault must not print the exception into the page; log it and show an empty profile.
  error_log('donorProfile.php: ' . $e->getMessage());
  $loadError = true;
  $donor = ['id' => $id, 'display_name' => 'Donor ' . $id, 'is_anonymous' => 0, 'is_active' => 1, 'phone' => '', 'email' => '',
            'city' => '', 'state' => '', 'donor_since_date' => null, 'preferred_contact' => null, 'last_contact_at' => null,
            'lifetime_cash' => 0, 'items_donated' => 0, 'volunteer_hours' => 0, 'last_gift_date' => null];
}
if (!$donor) { header('Location: donor.php', true, 303); exit; }   // unknown id: back to the list

$saved = fish_str($_GET, 'saved') === '1';
$isAnonymous = (int) $donor['is_anonymous'] === 1;
$canEdit = fish_can('donors', 'edit') && !$isAnonymous && !$loadError;

// Tab footers come from the rows; the tiles come from the view so the list and the profile agree.
$cashTotal  = array_sum(array_column($cash, 'amount'));
$cashCount  = count($cash);
$cashAvg    = $cashCount ? $cashTotal / $cashCount : 0;
$itemQty    = array_sum(array_column($items, 'quantity'));
$itemValue  = array_sum(array_column($items, 'estimated_value'));
$volHours   = array_sum(array_column($vol, 'hours'));
$volCount   = count($vol);
$volAvg     = $volCount ? $volHours / $volCount : 0;

function fmt_date($d) { return $d ? date('M j, Y', strtotime($d)) : '&mdash;'; }
function fmt_hours($n) { return rtrim(rtrim(number_format((float) $n, 1), '0'), '.'); }
// Cash and non-cash receipts: sent, pending, or not requested.
function receipt_badge(array $row) {
  if (!empty($row['receipt_sent_at']) || !empty($row['thanked'])) { return '<span class="status active">Sent</span>'; }
  if (!empty($row['send_receipt'])) { return '<span class="status pending">Pending</span>'; }
  return '<span class="status off">Not requested</span>';
}
// Communication status straight from the enum; delivery failures stand out.
function comm_badge($status) {
  $warn = in_array($status, ['bounced', 'failed'], true);
  return '<span class="status ' . ($warn ? 'pending' : 'active') . '">' . h(ucfirst((string) $status)) . '</span>';
}
function payment_label(array $g) {
  $labels = ['cash' => 'Cash', 'check' => 'Check', 'card' => 'Card', 'online' => 'Online',
             'digital_token' => 'Digital token', 'stock' => 'Stock', 'other' => 'Other'];
  if ($g['payment_method'] === null) { return '&mdash;'; }
  $label = $labels[$g['payment_method']] ?? ucfirst(str_replace('_', ' ', $g['payment_method']));
  if ($g['payment_method'] === 'check' && $g['check_number'] !== null && $g['check_number'] !== '') {
    $label .= ' #' . $g['check_number'];
  }
  return h($label);
}
function comm_type_label($t) { return h($t === 'in_person' ? 'In person' : ucfirst(str_replace('_', ' ', (string) $t))); }
function count_line($n, $label) {
  return '<div class="pager"><p>Showing ' . ($n ? '1&ndash;' . $n : '0') . ' of ' . $n . ' ' . $label . '</p></div>';
}

$name = (string) $donor['display_name'];
$contactLine = array_filter([$donor['phone'], $donor['email'], implode(', ', array_filter([$donor['city'], $donor['state']]))]);
$metaLine = array_filter([
  $donor['donor_since_date'] ? 'Donor since ' . date('Y', strtotime($donor['donor_since_date'])) : '',
  $donor['preferred_contact'] ? 'Prefers ' . strtolower(donor_pref_label($donor['preferred_contact'])) : '',
  $donor['last_contact_at'] ? 'Last contact ' . date('M j, Y', strtotime($donor['last_contact_at'])) : '',
]);

fishnet_open('Donor profile', 'donor', ['donor.css', 'forms.css']);
?>

      <p class="crumbs"><a href="donor.php">Donor</a> &rsaquo; <strong><?= h($name) ?></strong></p>
      <?php if ($saved): ?><div class="notice" role="status">Donor saved.</div><?php endif; ?>
      <?php if ($loadError): ?><div class="notice" role="alert">Could not load this donor. Please try again shortly.</div><?php endif; ?>

      <section class="card profile-head">
        <span class="big-initials"><?= h(initials($name)) ?></span>
        <div class="who">
          <h1><?= h($name) ?><?php if (!(int) $donor['is_active']): ?> <span class="status off">Inactive</span><?php endif; ?></h1>
          <p><?= $contactLine ? h(implode(' · ', $contactLine)) : '&mdash;' ?></p>
          <p><?= $metaLine ? h(implode(' · ', $metaLine)) : '&nbsp;' ?></p>
        </div>
        <?php if ($canEdit): ?><a href="updateDonor.php?id=<?= (int)$donor['id'] ?>" class="btn btn-yellow">&#9998; Update profile</a><?php endif; ?>
      </section>

      <section class="tiles">
        <div class="tile"><p class="label">Lifetime cash</p><p class="num">$<?= number_format((float) $donor['lifetime_cash'], 2) ?></p></div>
        <div class="tile"><p class="label">Items donated</p><p class="num"><?= number_format((float) $donor['items_donated']) ?></p></div>
        <div class="tile"><p class="label">Volunteer hours</p><p class="num"><?= fmt_hours($donor['volunteer_hours']) ?></p></div>
        <div class="tile"><p class="label">Last gift</p><p class="num"><?= fmt_date($donor['last_gift_date']) ?></p></div>
      </section>

      <section class="card tabs-card">
        <input type="radio" name="tab" id="tCash" checked>
        <input type="radio" name="tab" id="tItems">
        <input type="radio" name="tab" id="tVol">
        <input type="radio" name="tab" id="tComm">
        <div class="tab-bar">
          <label for="tCash">Cash <span class="count"><?= $cashCount ?></span></label>
          <label for="tItems">Non-cash <span class="count"><?= count($items) ?></span></label>
          <label for="tVol">Volunteer <span class="count"><?= $volCount ?></span></label>
          <label for="tComm">Communications <span class="count"><?= count($comms) ?></span></label>
        </div>

        <!-- Cash -->
        <div class="panel-tab p-cash">
          <div class="tab-actions"><a href="addDonation.php?donor_id=<?= (int)$donor['id'] ?>" class="btn btn-outline pill-btn">+ Add cash gift</a></div>
          <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>Amount</th><th>Payment</th><th>Campaign</th><th>Receipt</th></tr></thead>
            <tbody>
              <?php foreach ($cash as $g): ?>
              <tr><td><?= fmt_date($g['donation_date']) ?></td><td><strong>$<?= number_format((float) $g['amount'], 2) ?></strong></td><td><?= payment_label($g) ?></td><td><?= $g['campaign'] !== null && $g['campaign'] !== '' ? h($g['campaign']) : '&mdash;' ?></td><td><?= receipt_badge($g) ?></td></tr>
              <?php endforeach; ?>
              <?php if (!$cash): ?><tr><td colspan="5" class="muted">No cash gifts recorded.</td></tr><?php endif; ?>
            </tbody>
            <?php if ($cash): ?>
            <tfoot><tr><td>Total cash</td><td>$<?= number_format($cashTotal, 2) ?></td><td colspan="3" class="muted"><?= $cashCount ?> gift<?= $cashCount === 1 ? '' : 's' ?> &middot; avg $<?= number_format($cashAvg, 2) ?></td></tr></tfoot>
            <?php endif; ?>
          </table></div>
          <?= count_line($cashCount, 'cash gifts') ?>
        </div>

        <!-- Non-cash -->
        <div class="panel-tab p-items">
          <div class="tab-actions"><a href="addDonation.php?donor_id=<?= (int)$donor['id'] ?>" class="btn btn-outline pill-btn">+ Add non-cash gift</a></div>
          <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>Quantity</th><th>Item</th><th>Est. value</th><th>Campaign</th><th>Receipt</th></tr></thead>
            <tbody>
              <?php foreach ($items as $g): ?>
              <tr><td><?= fmt_date($g['date_received']) ?></td><td><strong><?= h(fmt_hours($g['quantity'])) ?> <?= h($g['unit']) ?></strong></td><td><?= h($g['item_description']) ?></td><td><?= $g['estimated_value'] === null ? '&mdash;' : '$' . number_format((float) $g['estimated_value'], 2) ?></td><td><?= $g['campaign'] !== null && $g['campaign'] !== '' ? h($g['campaign']) : '&mdash;' ?></td><td><?= receipt_badge($g) ?></td></tr>
              <?php endforeach; ?>
              <?php if (!$items): ?><tr><td colspan="6" class="muted">No non-cash gifts recorded.</td></tr><?php endif; ?>
            </tbody>
            <?php if ($items): ?>
            <tfoot><tr><td>Totals</td><td><?= h(fmt_hours($itemQty)) ?></td><td></td><td>$<?= number_format($itemValue, 2) ?></td><td colspan="2"></td></tr></tfoot>
            <?php endif; ?>
          </table></div>
          <?= count_line(count($items), 'non-cash gifts') ?>
        </div>

        <!-- Volunteer -->
        <div class="panel-tab p-vol">
          <div class="tab-actions"><a href="#" class="btn btn-outline pill-btn">+ Log hours</a></div>
          <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>Hours</th><th>Job</th><th>Campaign</th></tr></thead>
            <tbody>
              <?php foreach ($vol as $v): ?>
              <tr><td><?= fmt_date($v['activity_date']) ?></td><td><strong><?= h(fmt_hours($v['hours'])) ?></strong></td><td><?= h($v['job']) ?></td><td><?= $v['campaign'] !== null && $v['campaign'] !== '' ? h($v['campaign']) : '&mdash;' ?></td></tr>
              <?php endforeach; ?>
              <?php if (!$vol): ?><tr><td colspan="4" class="muted">No volunteer shifts recorded.</td></tr><?php endif; ?>
            </tbody>
            <?php if ($vol): ?>
            <tfoot><tr><td>Total hours</td><td><?= h(fmt_hours($volHours)) ?></td><td colspan="2" class="muted"><?= h(fmt_hours($volAvg)) ?> hours served on avg</td></tr></tfoot>
            <?php endif; ?>
          </table></div>
          <?= count_line($volCount, 'volunteer shifts') ?>
        </div>

        <!-- Communications -->
        <div class="panel-tab p-comm">
          <div class="tab-actions"><a href="logCommunication.php?donor_id=<?= (int)$donor['id'] ?>" class="btn btn-outline pill-btn">+ Log communication</a></div>
          <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>Type</th><th>Subject line</th><th>Campaign</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($comms as $c): ?>
              <tr><td><?= fmt_date($c['occurred_at']) ?></td><td><strong><?= comm_type_label($c['comm_type']) ?></strong></td><td><?= h($c['subject']) ?></td><td><?= $c['campaign'] !== null && $c['campaign'] !== '' ? h($c['campaign']) : '&mdash;' ?></td><td><?= comm_badge($c['status']) ?></td></tr>
              <?php endforeach; ?>
              <?php if (!$comms): ?><tr><td colspan="5" class="muted">No communications logged.</td></tr><?php endif; ?>
            </tbody>
          </table></div>
          <?= count_line(count($comms), 'communications') ?>
        </div>
      </section>

<?php fishnet_close(); ?>
