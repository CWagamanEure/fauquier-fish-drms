<?php
// One donation, read-only, with Close and Edit buttons (UF2-56). ?kind=cash|noncash&id=<id>.
// Donor figures need donors view; the gift itself needs donations view.
require_once 'include/fishnet-layout.php';
require_once 'include/permissions.php';
require_once 'database/dbDonations.php';

$kind = fish_str($_GET, 'kind');
$id = fish_str($_GET, 'id');
if (!in_array($kind, ['cash', 'noncash'], true) || !ctype_digit($id)) { header('Location: donations.php', true, 303); exit; }

$canView = fish_can('donations', 'view');
$canEdit = fish_can_record_donations();
$canViewDonors = fish_can('donors', 'view');
$gift = $donor = $campaign = null;
$loadError = false;
if ($canView) {
  try {
    $gift = donation_get($kind, (int) $id);
    if ($gift) {
      $donor = donor_get($gift['donor_id']);
      if ($gift['campaign_id'] !== null) { $campaign = donation_campaigns($gift['campaign_id'])[(int) $gift['campaign_id']] ?? null; }
    }
  } catch (mysqli_sql_exception $e) {
    error_log('viewDonation.php: ' . $e->getMessage());
    $loadError = true;
  }
  if (!$gift && !$loadError) { header('Location: donations.php', true, 303); exit; }
}

const NOT_RECORDED = 'Not recorded';
function shown($v) { return $v === null || $v === '' ? NOT_RECORDED : (string) $v; }
function shown_money($v) { return $v === null ? NOT_RECORDED : '$' . number_format((float) $v, 2); }
function shown_date($v) { return $v === null || $v === '' ? NOT_RECORDED : date('m/d/Y', strtotime($v)); }
function shown_label(array $labels, $v) { return $v === null || $v === '' ? NOT_RECORDED : ($labels[$v] ?? (string) $v); }
function shown_qty($v) { return rtrim(rtrim(number_format((float) $v, 2), '0'), '.'); }
function receipt_text(array $g) {
  if (!empty($g['receipt_sent_at']) || !empty($g['thanked'])) { return 'Sent'; }
  return $g['send_receipt'] ? 'Requested, not sent yet' : 'Not requested';
}
function field($label, $value, $class = '') {
  return '<div class="field' . ($class ? ' ' . $class : '') . '"><label>' . h($label) . '</label><input value="' . h($value) . '" readonly></div>';
}

$donorName = $donor ? (string) $donor['display_name'] : 'Unknown donor';
$saved = fish_str($_GET, 'saved') === '1';
fishnet_open('View donation', 'donations', ['forms.css', 'donation.css']);
?>

      <section class="page-head">
        <div>
          <p class="crumbs"><a href="donations.php">Donations</a> &rsaquo; <strong>View donation</strong></p>
          <h1>View donation</h1>
        </div>
      </section>

      <?php if (!$canView): ?>
      <div class="notice err" role="status">Your account cannot view donations.</div>
      <?php elseif ($loadError): ?>
      <div class="notice err" role="alert">Could not load this donation. Please try again shortly.</div>
      <?php else: ?>
      <?php if ($saved): ?><div class="notice ok" role="status">Donation saved.</div><?php endif; ?>

      <div class="donation-layout view-only">
        <div class="card form-card">

          <fieldset class="step">
            <legend>1 &middot; Donor</legend>
            <div class="donor-box chosen">
              <span class="initials"><?= h(initials($donorName)) ?></span>
              <div>
                <strong><?= h($donorName) ?></strong>
                <?php if ($canViewDonors && $donor): ?>
                <p class="meta"><?= h(implode(' · ', array_filter([
                  $donor['donor_type'] === 'organization' ? 'Organization' : 'Individual',
                  implode(', ', array_filter([(string) $donor['city'], (string) $donor['state']], 'strlen')),
                  $donor['donor_since_date'] ? 'Donor since ' . substr($donor['donor_since_date'], 0, 4) : '',
                ]))) ?></p>
                <?php endif; ?>
              </div>
              <?php if ($canViewDonors && $donor): ?><a href="donorProfile.php?id=<?= (int) $donor['id'] ?>" class="link">View donor</a><?php endif; ?>
            </div>
          </fieldset>

          <fieldset class="step">
            <legend>2 &middot; Donation type</legend>
            <div class="choices">
              <label class="choice on"><input type="radio" checked disabled>
                <span><strong><?= $kind === 'cash' ? 'Cash' : 'Non-cash (items)' ?></strong><small><?= $kind === 'cash' ? 'Check, cash, card, online' : 'Food, clothing, goods' ?></small></span>
              </label>
            </div>
          </fieldset>

          <fieldset class="step details">
          <?php if ($kind === 'cash'): ?>
            <legend>3 &middot; Donation details</legend>
            <div class="grid">
              <?= field('Amount', shown_money($gift['amount'])) ?>
              <?= field('Processing fee', $gift['fee'] === null ? NOT_RECORDED : '$' . number_format((float) $gift['fee'], 2)) ?>
              <?= field('Net amount', shown_money($gift['net_amount'])) ?>
              <?= field('Date received', shown_date($gift['date_received'])) ?>
              <?= field('Type', shown_label(DONATION_PAYMENT_LABELS, $gift['payment_method'])) ?>
              <?= field('Platform', shown_label(DONATION_PLATFORM_LABELS, $gift['platform'])) ?>
              <?php if ($gift['payment_method'] === 'check'): ?>
              <?= field('Check number', shown($gift['check_number'])) ?>
              <?= field('Signed', $gift['is_signed'] === null ? NOT_RECORDED : ((int) $gift['is_signed'] ? 'Yes' : 'No')) ?>
              <?= field('Date to be deposited', shown_date($gift['deposit_date'])) ?>
              <?php endif; ?>
              <?= field('Campaign', shown($gift['campaign']), 'span2') ?>
              <?= field('In honor of', (int) $gift['in_honor_of'] ? shown($gift['honoree_name']) : 'No', 'span2') ?>
              <?= field('Receipt', receipt_text($gift), 'span2') ?>
              <?= field('Notes', shown($gift['notes']), 'span4') ?>
              <?= field('Recorded by', shown($gift['recorder']), 'span2') ?>
            </div>
          <?php else: ?>
            <legend>3 &middot; Item details</legend>
            <div class="grid">
              <?= field('Item description', shown($gift['item_description']), 'span2') ?>
              <?= field('Category', shown_label(DONATION_CATEGORY_LABELS, $gift['category'])) ?>
              <?= field('Date received', shown_date($gift['date_received'])) ?>
              <?= field('Quantity', shown_qty($gift['quantity']) . ' ' . $gift['unit']) ?>
              <?= field('Estimated value', shown_money($gift['estimated_value'])) ?>
              <?= field('Condition', shown_label(DONATION_CONDITION_LABELS, $gift['item_condition'])) ?>
              <?= field('Given out so far', shown_qty($gift['distributed']) . ' ' . $gift['unit']) ?>
              <?= field('Intended use / program', shown($gift['intended_use']), 'span2') ?>
              <?= field('Campaign', shown($gift['campaign']), 'span2') ?>
              <?= field('Receipt', receipt_text($gift), 'span2') ?>
              <?= field('Recorded by', shown($gift['recorder']), 'span2') ?>
              <?= field('Notes', shown($gift['notes']), 'span4') ?>
            </div>
          <?php endif; ?>
          </fieldset>

          <div class="spacer"></div>

          <footer class="form-foot right">
            <div class="foot-btns">
              <a href="donations.php" class="btn btn-outline small">Close</a>
              <?php if ($canEdit): ?><a href="addDonation.php?kind=<?= $kind ?>&amp;id=<?= (int) $gift['id'] ?>" class="btn btn-yellow small">Edit donation</a><?php endif; ?>
            </div>
          </footer>
        </div>

        <aside class="side">
          <?php if ($canViewDonors && $donor): ?>
          <div class="card summary">
            <h3><?= h($donorName) ?></h3>
            <dl>
              <dt>Lifetime cash</dt><dd>$<?= number_format((float) $donor['lifetime_cash'], 2) ?></dd>
              <dt>Lifetime items</dt><dd><?= number_format((float) $donor['items_donated']) ?> items</dd>
              <dt>Last gift</dt><dd><?= $donor['last_gift_date'] ? h(date('M j, Y', strtotime($donor['last_gift_date']))) : 'None yet' ?></dd>
              <dt>Volunteer hours</dt><dd><?= h(rtrim(rtrim(number_format((float) $donor['volunteer_hours'], 1), '0'), '.')) ?></dd>
            </dl>
            <a href="donorProfile.php?id=<?= (int) $donor['id'] ?>" class="link">View full profile &rarr;</a>
          </div>
          <?php endif; ?>

          <?php if ($campaign): ?>
          <div class="card campaign">
            <p class="ct-title">Counts toward</p>
            <p class="ct-name"><?= h($campaign['name']) ?></p>
            <?php if ($kind === 'cash' && $campaign['goal'] > 0): ?>
            <div class="bar"><div class="fill" style="width: <?= min(100, round($campaign['raised'] / $campaign['goal'] * 100)) ?>%"></div></div>
            <p class="ct-text">$<?= number_format($campaign['raised'], 2) ?> of $<?= number_format($campaign['goal'], 2) ?> raised</p>
            <?php elseif ($kind === 'cash'): ?>
            <p class="ct-text">$<?= number_format($campaign['raised'], 2) ?> raised so far</p>
            <?php else: ?>
            <p class="ct-text"><?= number_format($campaign['gifts']) ?> non-cash gift<?= $campaign['gifts'] === 1 ? '' : 's' ?>, est. $<?= number_format($campaign['item_value'], 2) ?></p>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </aside>
      </div>
      <?php endif; ?>

<?php fishnet_close(); ?>
