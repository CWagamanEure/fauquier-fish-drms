<?php
// css and php to keep the pages consistant
require_once 'include/fishnet-layout.php';
require_once 'include/donorDataStopgap.php';

//logic for fake data, replace with SQL once schema is set
$id = (int)($_GET['id'] ?? 0);
$donor = null;
foreach (stopgap_donors() as $d) { if ($d['id'] === $id) { $donor = $d; break; } }
if (!$donor) { header('Location: donor.php'); exit; }   // unknown/missing id: back to the list

$detail  = stopgap_donor_details()[$id] ?? [];
$isEmail = strpos($donor['contact'], '@') !== false;
$p = [
  'email'        => $detail['email']        ?? ($isEmail ? $donor['contact'] : ''),
  'phone'        => $detail['phone']        ?? ($isEmail ? '' : $donor['contact']),
  'location'     => $detail['location']     ?? '',
  'since'        => $detail['since']        ?? '',
  'prefers'      => $detail['prefers']      ?? '',
  'last_contact' => $detail['last_contact'] ?? '',
];
// Donors without detail data get one summary gift so the page matches the list's lifetime total
$cash  = $detail['cash'] ?? [['date'=>$donor['last_gift'],'amount'=>$donor['lifetime'],'payment'=>'&mdash;','campaign'=>'','receipt'=>'sent']];
$items = $detail['items'] ?? [];
$vol   = $detail['vol']   ?? [];
$comms = $detail['comms'] ?? [];

$cashTotal  = array_sum(array_column($cash, 'amount'));
$cashCount  = count($cash);
$cashAvg    = $cashCount ? $cashTotal / $cashCount : 0;
$itemQty    = array_sum(array_column($items, 'qty'));
$itemValue  = array_sum(array_column($items, 'value'));
$volHours   = array_sum(array_column($vol, 'hours'));
$volCount   = count($vol);
$volAvg     = $volCount ? $volHours / $volCount : 0;
$lastGift   = $cash ? max(array_column($cash, 'date')) : '';

function fmt_date($d) { return $d ? date('M j, Y', strtotime($d)) : '&mdash;'; }
function receipt_badge($r) {
  return $r === 'pending' ? '<span class="status pending">Pending</span>' : '<span class="status active">Sent</span>';
}
function pager_line($n, $label) {
  return '<div class="pager"><p>Showing ' . ($n ? '1&ndash;' . $n : '0') . ' of ' . $n . ' ' . $label . '</p>'
       . '<div class="pages"><a href="#" class="pg wide">&lsaquo; Previous</a><a href="#" class="pg on">1</a><a href="#" class="pg wide">Next &rsaquo;</a></div></div>';
}

$contactLine = array_filter([$p['phone'], $p['email'], $p['location']]);
$metaLine = array_filter([
  $p['since']        ? 'Donor since ' . $p['since'] : '',
  $p['prefers']      ? 'Prefers ' . strtolower($p['prefers']) : '',
  $p['last_contact'] ? 'Last contact ' . date('M j, Y', strtotime($p['last_contact'])) : '',
]);

fishnet_open('Donor profile', 'donor', ['donor.css', 'forms.css']);
?>

      <p class="crumbs"><a href="donor.php">Donor</a> &rsaquo; <strong>Jhon D. Rangel Duran</strong></p>

      <section class="card profile-head">
        <span class="big-initials">JR</span>
        <div class="who">
          <h1><?= h($donor['name']) ?></h1>
          <p><?= $contactLine ? h(implode(' · ', $contactLine)) : '&mdash;' ?></p>
          <p><?= $metaLine ? h(implode(' · ', $metaLine)) : '&nbsp;' ?></p>
        </div>
        <a href="newDonor.php" class="btn btn-yellow">&#9998; Update profile</a>
      </section>
<!-- cards and titles for the card NEED A CONNECTION (FAKE DATA HERE) -->
      <section class="tiles">
        <div class="tile"><p class="label">Lifetime cash</p><p class="num">$<?= number_format($cashTotal, 2) ?></p></div>
        <div class="tile"><p class="label">Items donated</p><p class="num"><?= number_format($itemQty) ?></p></div>
        <div class="tile"><p class="label">Volunteer hours</p><p class="num"><?= rtrim(rtrim(number_format($volHours, 1), '0'), '.') ?></p></div>
        <div class="tile"><p class="label">Last gift</p><p class="num"><?= fmt_date($lastGift) ?></p></div>
      </section>
      
      <section class="card tabs-card">
        <input type="radio" name="tab" id="tCash" checked>
        <input type="radio" name="tab" id="tItems">
        <input type="radio" name="tab" id="tVol">
        <input type="radio" name="tab" id="tComm">
<!--  title for the tabs -->
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
            <thead><tr><th>Date</th><th>Amount</th><th>Payment</th><th>Campaign</th><th>Receipt</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($cash as $g): ?>
              <tr><td><?= fmt_date($g['date']) ?></td><td><strong>$<?= number_format($g['amount'], 2) ?></strong></td><td><?= $g['payment'] ?></td><td><?= h($g['campaign']) ?: '&mdash;' ?></td><td><?= receipt_badge($g['receipt']) ?></td><td><a href="#" class="edit">Edit</a></td></tr>
              <?php endforeach; ?>
              <?php if (!$cash): ?><tr><td colspan="6" class="muted">No cash gifts recorded.</td></tr><?php endif; ?>
            </tbody>
            <?php if ($cash): ?>
            <tfoot><tr><td>Total cash</td><td>$<?= number_format($cashTotal, 2) ?></td><td colspan="4" class="muted"><?= $cashCount ?> gift<?= $cashCount === 1 ? '' : 's' ?> &middot; avg $<?= number_format($cashAvg, 2) ?></td></tr></tfoot>
            <?php endif; ?>
          </table></div>
          <?= pager_line($cashCount, 'cash gifts') ?>
        </div>

        <!-- Non-cash -->
        <div class="panel-tab p-items">
          <div class="tab-actions"><a href="addDonation.php?donor_id=<?= (int)$donor['id'] ?>" class="btn btn-outline pill-btn">+ Add non-cash gift</a></div>
          <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>Quantity</th><th>Item</th><th>Est. value</th><th>Campaign</th><th>Receipt</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($items as $g): ?>
              <tr><td><?= fmt_date($g['date']) ?></td><td><strong><?= (int)$g['qty'] ?></strong></td><td><?= h($g['item']) ?></td><td>$<?= number_format($g['value'], 2) ?></td><td><?= h($g['campaign']) ?: '&mdash;' ?></td><td><?= receipt_badge($g['receipt']) ?></td><td><a href="#" class="edit">Edit</a></td></tr>
              <?php endforeach; ?>
              <?php if (!$items): ?><tr><td colspan="7" class="muted">No non-cash gifts recorded.</td></tr><?php endif; ?>
            </tbody>
            <?php if ($items): ?>
            <tfoot><tr><td>Totals</td><td><?= number_format($itemQty) ?></td><td></td><td>$<?= number_format($itemValue, 2) ?></td><td colspan="3"></td></tr></tfoot>
            <?php endif; ?>
          </table></div>
          <?= pager_line(count($items), 'non-cash gifts') ?>
        </div>

        <!-- Volunteer -->
        <div class="panel-tab p-vol">
          <div class="tab-actions"><a href="#" class="btn btn-outline pill-btn">+ Log hours</a></div>
          <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>Hours</th><th>Job</th><th>Campaign</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($vol as $v): ?>
              <tr><td><?= fmt_date($v['date']) ?></td><td><strong><?= h($v['hours']) ?></strong></td><td><?= h($v['job']) ?></td><td><?= h($v['campaign']) ?: '&mdash;' ?></td><td><a href="#" class="edit">Edit</a></td></tr>
              <?php endforeach; ?>
              <?php if (!$vol): ?><tr><td colspan="5" class="muted">No volunteer shifts recorded.</td></tr><?php endif; ?>
            </tbody>
            <?php if ($vol): ?>
            <tfoot><tr><td>Total hours</td><td><?= h(rtrim(rtrim(number_format($volHours, 1), '0'), '.')) ?></td><td colspan="3" class="muted"><?= h(rtrim(rtrim(number_format($volAvg, 1), '0'), '.')) ?> hours served on avg</td></tr></tfoot>
            <?php endif; ?>
          </table></div>
          <?= pager_line($volCount, 'volunteer shifts') ?>
        </div>

        <!-- Communications -->
        <div class="panel-tab p-comm">
          <div class="tab-actions"><a href="logCommunication.php?donor_id=<?= (int)$donor['id'] ?>" class="btn btn-outline pill-btn">+ Log communication</a></div>
          <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>Type</th><th>Subject line</th><th>Campaign</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($comms as $c): ?>
              <tr><td><?= fmt_date($c['date']) ?></td><td><strong><?= h($c['type']) ?></strong></td><td><?= h($c['subject']) ?></td><td><?= h($c['campaign']) ?: '&mdash;' ?></td><td><?= receipt_badge($c['status']) ?></td><td><a href="#" class="edit">Edit</a></td></tr>
              <?php endforeach; ?>
              <?php if (!$comms): ?><tr><td colspan="6" class="muted">No communications logged.</td></tr><?php endif; ?>
            </tbody>
          </table></div>
          <?= pager_line(count($comms), 'communications') ?>
        </div>
      </section>

<?php fishnet_close(); ?>
