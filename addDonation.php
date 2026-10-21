<?php
// Add or edit a cash / non-cash donation through database/dbDonations.php (UF2-46, 48-53, 57, 87).
// ?donor=<id>&kind=cash|noncash opens the add form with that donor and type chosen (donor_id is accepted
// for old links); ?kind=&id= opens a stored gift for editing. On edit the kind and id come only from the
// URL and the stored row, never from the POST.
require_once 'include/fishnet-layout.php';
require_once 'include/permissions.php';
require_once 'database/dbDonations.php';

$canWrite = fish_can_record_donations();
$canAddDonors = fish_can('donors', 'edit');
$kinds = ['cash', 'noncash'];

$editId = fish_str($_GET, 'id');
$editing = $editId !== '';
$stored = null;
$loadError = false;
if ($editing) {
  $editKind = fish_str($_GET, 'kind');
  if (!in_array($editKind, $kinds, true) || !ctype_digit($editId)) { header('Location: donations.php', true, 303); exit; }
  if ($canWrite) {
    try {
      $stored = donation_get($editKind, (int) $editId);
    } catch (mysqli_sql_exception $e) {
      error_log('addDonation.php: ' . $e->getMessage());
      $loadError = true;
    }
    if (!$stored && !$loadError) { header('Location: donations.php', true, 303); exit; }
  }
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$fromProfile = fish_str($isPost ? $_POST : $_GET, 'from') === 'profile';
if ($editing) {
  $kind = $editKind;
} else {
  $kind = fish_str($isPost ? $_POST : $_GET, 'kind');
  if (!in_array($kind, $kinds, true)) { $kind = ''; }
}

// Both kinds' fields, so switching type keeps whatever was typed into the other one.
$form = array_merge(donation_form_blank('cash'), donation_form_blank('noncash'));
if ($stored) {
  $form = array_merge($form, donation_form_from_row($stored));
} elseif (!$editing) {
  $form['donor'] = fish_str($_GET, 'donor') ?: fish_str($_GET, 'donor_id');
}
$errors = [];

if ($isPost) {
  if (!$canWrite) {
    http_response_code(403);
    exit('Your account cannot record or change donations.');
  }
  if ($loadError) {
    $errors[] = 'Could not load this donation. Please try again shortly.';
  } elseif ($kind === '') {
    $form = array_merge($form, ['donor' => fish_str($_POST, 'donor')]);
    $errors[] = 'Choose cash or non-cash.';
  } else {
    $form = array_merge($form, donation_form_from_post($_POST, $kind));
    try {
      $errors = donation_form_validate($form, $kind, $stored);
    } catch (mysqli_sql_exception $e) {
      error_log('addDonation.php: ' . $e->getMessage());
      $errors = ['Could not save the donation. Please try again.'];
    }
    if (!$errors) {
      $id = $editing ? (int) $editId : donation_create($kind, $form, auth_current_user()->get_id());
      $ok = $editing ? donation_update($kind, $id, $form) : $id !== false;
      if ($ok) {
        $profile = $fromProfile ? '&from=profile' : '';
        if (!$editing && fish_str($_POST, 'next') === 'another') {
          $target = 'addDonation.php?donor=' . (int) $form['donor'] . '&kind=' . $kind . '&saved=1' . $profile;
        } elseif ($fromProfile) {
          $target = 'donorProfile.php?id=' . (int) $form['donor'] . '&gift=1';
        } else {
          $target = 'viewDonation.php?kind=' . $kind . '&id=' . $id . '&saved=1';
        }
        header('Location: ' . $target, true, 303);
        exit;
      }
      $errors[] = 'Could not save the donation. Please try again.';
    }
  }
}

// The chosen donor for the side panel: on edit the stored donor is always allowed; otherwise only an
// active, non-anonymous donor (the same rule the search uses).
$chosenDonor = null;
$campaigns = [];
if ($canWrite) {
  try {
    if (ctype_digit($form['donor'])) {
      $row = donor_get((int) $form['donor']);
      $isStoredDonor = $stored && (int) $form['donor'] === $stored['donor_id'];
      if ($row && ($isStoredDonor || ((int) $row['is_active'] === 1 && (int) $row['is_anonymous'] === 0))) {
        $chosenDonor = donor_lookup_row($row);
      }
    }
    $campaigns = donation_campaigns($stored ? $stored['campaign_id'] : null);
  } catch (mysqli_sql_exception $e) {
    error_log('addDonation.php: ' . $e->getMessage());
    $loadError = true;
  }
}
if (!$chosenDonor) { $form['donor'] = ''; }

// On edit, the stored gift is already inside its campaign's totals; the card subtracts it once.
$storedForCard = $stored ? ['campaign' => $stored['campaign_id'] === null ? null : (int) $stored['campaign_id'],
                            'value' => (float) ($kind === 'cash' ? $stored['amount'] : $stored['estimated_value'])] : null;

$title = $editing ? 'Edit donation' : 'Add donation';
$saved = fish_str($_GET, 'saved') === '1';
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

function select_options(array $labels, $selected, $placeholder) {
  $html = $placeholder === null ? '' : '<option value="">' . h($placeholder) . '</option>';
  foreach ($labels as $value => $label) {
    $html .= '<option value="' . h($value) . '"' . ((string) $selected === (string) $value ? ' selected' : '') . '>' . h($label) . '</option>';
  }
  return $html;
}
function campaign_options(array $campaigns, $selected) {
  return select_options(['' => 'No campaign'] + array_map(function ($c) { return $c['name']; }, $campaigns), $selected, null);
}

fishnet_open($title, 'donations', ['forms.css', 'donation.css']);
?>

      <section class="page-head">
        <div>
          <p class="crumbs"><a href="donations.php">Donations</a> &rsaquo; <strong><?= $title ?></strong></p>
          <h1><?= $title ?></h1>
        </div>
      </section>

      <?php if ($saved): ?><div class="notice ok" role="status">Donation saved.</div><?php endif; ?>
      <?php if (!$canWrite): ?>
      <div class="notice err" role="status">Your account cannot record or change donations.</div>
      <?php else: ?>
      <?php if ($loadError): ?><div class="notice err" role="alert">Could not load everything this form needs. Please try again shortly.</div><?php endif; ?>

      <form class="donation-layout" method="post" id="donationForm" novalidate>
        <?= auth_csrf_field() ?>
        <?php if ($fromProfile): ?><input type="hidden" name="from" value="profile"><?php endif; ?>
        <!-- Hidden default button: pressing Enter in a field saves normally -->
        <button type="submit" name="next" value="done" hidden></button>

        <div class="card form-card">
          <?php if ($errors): ?>
          <div class="form-errors" role="alert">
            <strong>Please fix the following:</strong>
            <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
          </div>
          <?php endif; ?>

          <fieldset class="step">
            <legend>1 &middot; Donor</legend>
            <input type="hidden" name="donor" id="donorId" value="<?= h($form['donor']) ?>">
            <div class="donor-box" id="donorSearchBox">
              <input type="text" id="donorSearch" placeholder="Search donors by name, email or phone..." autocomplete="off" aria-label="Search donors" aria-controls="donorResults">
              <?php if ($canAddDonors && !$editing): ?>
              <a href="addDonor.php" class="link">+Add</a>
              <?php endif; ?>
              <ul class="results" id="donorResults" aria-live="polite" hidden></ul>
            </div>
            <div class="donor-box chosen" id="donorChosen" hidden>
              <span class="initials" id="chosenInitials"></span>
              <div>
                <strong id="chosenName"></strong>
                <p class="meta" id="chosenMeta"></p>
              </div>
              <button type="button" class="link" id="changeDonor">Change</button>
            </div>
          </fieldset>

          <fieldset class="step" id="stepType" disabled>
            <legend>2 &middot; Donation type</legend>
            <div class="choices">
              <?php foreach (['cash' => ['Cash', 'Check, cash, card, online'], 'noncash' => ['Non-cash (items)', 'Food, clothing, goods']] as $v => [$label, $hint]): ?>
              <?php if (!$editing || $kind === $v): ?>
              <label class="choice">
                <input type="radio" name="kind" value="<?= $v ?>"<?= $kind === $v ? ' checked' : '' ?>>
                <span><strong><?= $label ?></strong><small><?= $editing ? 'The type of a recorded gift cannot change' : $hint ?></small></span>
              </label>
              <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </fieldset>

          <!-- 3a. Cash details (UF2-48). Only the selected kind's section is enabled, so a POST never mixes the two. -->
          <fieldset class="step details" id="cashDetails"<?= $kind === 'cash' ? '' : ' hidden disabled' ?>>
            <legend>3 &middot; Donation details</legend>
            <div class="grid">
              <div class="field"><label for="amount">Amount</label><input type="text" inputmode="decimal" id="amount" name="amount" placeholder="$0.00" value="<?= h($form['amount']) ?>"></div>
              <div class="field"><label for="fee">Processing fee</label><input type="text" inputmode="decimal" id="fee" name="fee" placeholder="$0.00" value="<?= h($form['fee']) ?>"></div>
              <div class="field"><label for="net">Net amount</label><input type="text" id="net" readonly tabindex="-1"></div>
              <div class="field"><label for="cashDate">Date received</label><input type="date" id="cashDate" name="date_received" max="<?= h(donation_today()) ?>" value="<?= h($form['date_received']) ?>"></div>

              <div class="field"><label for="payType">Type</label>
                <select id="payType" name="pay_type"><?= select_options(DONATION_PAYMENT_LABELS, $form['pay_type'], 'Choose…') ?></select></div>
              <div class="field"><label for="platform">Platform</label>
                <select id="platform" name="platform"><?= select_options(DONATION_PLATFORM_LABELS, $form['platform'], 'Choose…') ?></select></div>
              <div class="field check-only"><label for="checkNum">Check number</label><input type="text" id="checkNum" name="check_number" maxlength="32" value="<?= h($form['check_number']) ?>"></div>
              <div class="field check-only"><label for="signed">Signed</label>
                <select id="signed" name="signed"><?= select_options(['yes' => 'Yes', 'no' => 'No'], $form['signed'], '—') ?></select></div>

              <div class="field check-only"><label for="depositDate">Date to be deposited</label><input type="date" id="depositDate" name="deposit_date" value="<?= h($form['deposit_date']) ?>"></div>
              <div class="field span2"><label for="cashCampaign">Campaign</label>
                <select id="cashCampaign" name="campaign" class="campaign-select"><?= campaign_options($campaigns, $form['campaign']) ?></select></div>

              <div class="field span2"><label class="check"><input type="checkbox" id="inHonor" name="in_honor"<?= $form['in_honor'] ? ' checked' : '' ?>> Given in honor or memory of someone</label></div>
              <div class="field span2" id="honoreeField"><label for="honoree">In honor of</label><input type="text" id="honoree" name="honoree_name" maxlength="255" value="<?= h($form['honoree_name']) ?>"></div>
              <div class="field span4"><label for="cashNotes">Notes</label><input type="text" id="cashNotes" name="notes" maxlength="1000" value="<?= h($form['notes']) ?>"></div>
            </div>
          </fieldset>

          <!-- 3b. Item details (UF2-16) -->
          <fieldset class="step details" id="itemDetails"<?= $kind === 'noncash' ? '' : ' hidden disabled' ?>>
            <legend>3 &middot; Item details</legend>
            <div class="grid">
              <div class="field span2"><label for="itemDesc">Item description</label><input type="text" id="itemDesc" name="item_description" maxlength="255" placeholder="e.g. Canned vegetables &amp; soup" value="<?= h($form['item_description']) ?>"></div>
              <div class="field"><label for="category">Category</label>
                <select id="category" name="category"><?= select_options(DONATION_CATEGORY_LABELS, $form['category'], 'Choose…') ?></select></div>
              <div class="field"><label for="itemDate">Date received</label><input type="date" id="itemDate" name="date_received" max="<?= h(donation_today()) ?>" value="<?= h($form['date_received']) ?>"></div>

              <div class="field"><label for="quantity">Quantity</label><input type="text" inputmode="decimal" id="quantity" name="quantity" placeholder="e.g. 320" value="<?= h($form['quantity']) ?>"></div>
              <div class="field"><label for="unit">Unit</label>
                <select id="unit" name="unit"><?= select_options(array_combine(DONATION_UNITS, DONATION_UNITS), $form['unit'], null) ?></select></div>
              <div class="field"><label for="estValue">Estimated value</label><input type="text" inputmode="decimal" id="estValue" name="estimated_value" placeholder="$0.00" value="<?= h($form['estimated_value']) ?>"></div>
              <div class="field"><label for="condition">Condition</label>
                <select id="condition" name="condition"><?= select_options(DONATION_CONDITION_LABELS, $form['condition'], '—') ?></select></div>

              <div class="field span2"><label for="intendedUse">Intended use / program</label><input type="text" id="intendedUse" name="intended_use" maxlength="255" placeholder="e.g. Food pantry" value="<?= h($form['intended_use']) ?>"></div>
              <div class="field span2"><label for="itemCampaign">Campaign</label>
                <select id="itemCampaign" name="campaign" class="campaign-select"><?= campaign_options($campaigns, $form['campaign']) ?></select></div>
              <div class="field span4"><label for="itemNotes">Notes</label><input type="text" id="itemNotes" name="notes" maxlength="1000" value="<?= h($form['notes']) ?>"></div>
            </div>
          </fieldset>

          <div class="spacer"></div>
          <footer class="form-foot">
            <label class="check"><input type="checkbox" name="send_receipt"<?= $form['send_receipt'] ? ' checked' : '' ?>> Send a thank-you &amp; tax receipt</label>
            <div class="foot-btns">
              <a href="<?= $editing ? 'viewDonation.php?kind=' . $kind . '&amp;id=' . (int) $editId : ($fromProfile && $chosenDonor ? 'donorProfile.php?id=' . $chosenDonor['id'] : 'donations.php') ?>" class="btn btn-outline small">Cancel</a>
              <?php if (!$editing): ?>
              <button type="submit" name="next" value="another" class="btn btn-outline small" id="saveAnother" disabled>Save &amp; add another</button>
              <?php endif; ?>
              <button type="submit" name="next" value="done" class="btn btn-yellow small" id="saveBtn" disabled><?= $editing ? 'Save changes' : 'Save donation' ?></button>
            </div>
          </footer>
        </div>

        <aside class="side">
          <div class="card summary" id="donorSummary" hidden>
            <h3 id="sumName"></h3>
            <dl>
              <dt>Lifetime cash</dt><dd id="sumCash"></dd>
              <dt>Lifetime items</dt><dd id="sumItems"></dd>
              <dt>Last gift</dt><dd id="sumLast"></dd>
              <dt>Volunteer hours</dt><dd id="sumVol"></dd>
            </dl>
            <a href="#" id="sumProfile" class="link" target="_blank" rel="noopener">View full profile &rarr;</a>
          </div>
          <div class="card campaign" id="campaignCard" hidden>
            <p class="ct-title">Counts toward</p>
            <p class="ct-name" id="ctName"></p>
            <div class="bar" id="ctBar"><div class="fill" id="ctFill"></div></div>
            <p class="ct-text" id="ctText"></p>
          </div>
        </aside>
      </form>

<script>
const CHOSEN_DONOR = <?= json_encode($chosenDonor, $jsonFlags) ?>;
const CAMPAIGNS = <?= json_encode((object) $campaigns, $jsonFlags) ?>;
const STORED = <?= json_encode($storedForCard, $jsonFlags) ?>;
</script>
<script src="js/addDonation.js"></script>
      <?php endif; ?>

<?php fishnet_close(); ?>
