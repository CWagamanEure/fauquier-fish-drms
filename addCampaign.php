<?php
// Add or edit a campaign through database/dbCampaigns.php. addCampaign.php creates one; addCampaign.php?id=N edits it.
// The form token is checked before this page runs (the same way addDonor.php relies on it).
require_once 'include/fishnet-layout.php';
require_once 'include/permissions.php';
require_once 'database/dbCampaigns.php';

$isEdit = isset($_GET['id']);
$id     = (int) ($_GET['id'] ?? 0);

if (!fish_can('campaigns', 'edit')) {
  http_response_code(403);
  exit('Your account can view campaigns but not change them.');
}

$errors = [];
$stored = null;
try {
  $owners = campaign_owner_options();
  if ($isEdit) { $stored = $id > 0 ? campaign_get($id) : null; }
} catch (mysqli_sql_exception $e) {
  error_log('addCampaign.php: ' . $e->getMessage());
  header('Location: campaigns.php?error=load', true, 303);   // the list page shows the generic notice
  exit;
}
if ($isEdit && !$stored) { header('Location: campaigns.php', true, 303); exit; }

// An owner who is no longer in the dropdown (inactive account, for example) stays selectable on their own campaign.
$ownerIds = array_column($owners, 'id');
if ($stored && (string) $stored['owner_id'] !== '' && !in_array($stored['owner_id'], $ownerIds, true)) {
  $owners[]   = ['id' => $stored['owner_id'], 'name' => $stored['owner_name'] ?: $stored['owner_id']];
  $ownerIds[] = $stored['owner_id'];
}

$me   = auth_current_user();
$myId = $me ? (string) $me->get_id() : '';
$form = $stored ? campaign_form_from_row($stored)
                : campaign_form_defaults(in_array($myId, $ownerIds, true) ? $myId : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $form   = campaign_form_from_post($_POST, $form);
  $errors = campaign_form_validate($form, $ownerIds);
  if (!$errors) {
    $byUser = $myId !== '' ? $myId : null;
    if ($isEdit) {
      $ok = campaign_update($id, $form, $byUser);
      $goId = $id;
    } else {
      $goId = campaign_create($form, $byUser);
      $ok = $goId !== false;
    }
    if ($ok) {
      header('Location: campaign.php?id=' . (int) $goId . '&saved=1', true, 303);
      exit;
    }
    $errors[] = 'Could not save the campaign. Please try again.';
  }
}

$typeHints = ['cash' => 'Money only', 'items' => 'Food, coats, supplies', 'both' => 'Money and items', 'volunteers' => 'Volunteer time'];
$title      = $isEdit ? 'Edit campaign' : 'Add campaign';
$formAction = 'addCampaign.php' . ($isEdit ? '?id=' . $id : '');
$backUrl    = $isEdit ? 'campaign.php?id=' . $id : 'campaigns.php';

fishnet_open($title, 'campaigns', ['forms.css', 'campaigns.css']);
?>

      <section class="page-head">
        <div>
          <p class="crumbs"><a href="campaigns.php">Campaigns</a> &rsaquo;
            <?php if ($isEdit): ?><a href="campaign.php?id=<?= $id ?>"><?= h($stored['name']) ?></a> &rsaquo; Edit<?php else: ?>New campaign<?php endif; ?></p>
          <h1><?= h($title) ?></h1>
        </div>
      </section>

      <div class="split">
        <form class="card form-card" id="campaignForm" method="post" action="<?= h($formAction) ?>" novalidate>
          <?= auth_csrf_field() ?>

          <?php if ($errors): ?>
          <div class="form-errors" role="alert">
            <strong>Please fix the following:</strong>
            <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
          </div>
          <?php endif; ?>

          <div class="plain-fieldset">
          <div>
            <div class="group-title">Basics</div>
            <div class="grid g-basics">
              <div class="field">
                <label for="name">Campaign name *</label>
                <input id="name" name="name" maxlength="255" required value="<?= h($form['name']) ?>" placeholder="e.g. Spring Pantry Restock">
              </div>
              <div class="field">
                <label for="owner_id">Owner</label>
                <select id="owner_id" name="owner_id">
                  <option value="">&mdash; No owner &mdash;</option>
                  <?php foreach ($owners as $o): ?>
                    <option value="<?= h($o['id']) ?>" <?= (string) $o['id'] === $form['owner_id'] ? 'selected' : '' ?>><?= h($o['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="field top-gap">
              <label for="description">Description</label>
              <textarea id="description" name="description" rows="2" maxlength="2000" placeholder="What is this campaign for and who does it help?"><?= h($form['description']) ?></textarea>
            </div>
          </div>

          <div>
            <div class="group-title">What are you collecting?</div>
            <div class="type-choices" role="radiogroup" aria-label="Campaign type">
              <?php foreach (CAMPAIGN_TYPES as $t => $label): ?>
              <label class="type-choice">
                <input type="radio" name="type" value="<?= h($t) ?>" <?= $form['type'] === $t ? 'checked' : '' ?>>
                <span><strong><?= h($label) ?></strong><small><?= h($typeHints[$t] ?? '') ?></small></span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div>
            <div class="group-title">Goals &amp; dates</div>
            <div class="grid g-goals">
              <div class="field goal-cash"><label for="cash_goal">Cash goal ($)</label><input id="cash_goal" name="cash_goal" inputmode="decimal" value="<?= h($form['cash_goal']) ?>" placeholder="e.g. 12000"></div>
              <div class="field goal-items"><label for="item_goal">Item goal</label><input id="item_goal" name="item_goal" inputmode="decimal" value="<?= h($form['item_goal']) ?>" placeholder="e.g. 2000"></div>
              <div class="field goal-items"><label for="item_unit">Item unit</label>
                <select id="item_unit" name="item_unit"><?php foreach (CAMPAIGN_UNITS as $u): ?><option <?= $u === $form['item_unit'] ? 'selected' : '' ?>><?= h($u) ?></option><?php endforeach; ?></select></div>
              <div class="field"><label for="volunteer_goal">Volunteer shifts</label><input id="volunteer_goal" name="volunteer_goal" inputmode="numeric" value="<?= h($form['volunteer_goal']) ?>" placeholder="e.g. 40"></div>
            </div>
            <div class="grid g-dates top-gap">
              <div class="field"><label for="start_date">Start date *</label><input type="date" id="start_date" name="start_date" required value="<?= h($form['start_date']) ?>"></div>
              <div class="field"><label for="end_date">End date *</label><input type="date" id="end_date" name="end_date" required value="<?= h($form['end_date']) ?>"></div>
              <div class="field"><label for="fund">Fund / designation</label><input id="fund" name="fund" maxlength="100" value="<?= h($form['fund']) ?>"></div>
              <div class="field"><label for="location">Drop-off location</label><input id="location" name="location" maxlength="255" value="<?= h($form['location']) ?>" placeholder="e.g. FISH pantry"></div>
            </div>
          </div>

          <div>
            <div class="group-title">Outreach</div>
            <div class="checks">
              <label><input type="checkbox" name="show_on_dashboard" <?= $form['show_on_dashboard'] ? 'checked' : '' ?>> Show on dashboard</label>
              <label><input type="checkbox" name="email_past_donors" <?= $form['email_past_donors'] ? 'checked' : '' ?>> Email past donors</label>
              <label><input type="checkbox" name="ask_volunteers" <?= $form['ask_volunteers'] ? 'checked' : '' ?>> Ask volunteers to sign up</label>
              <?php if ($isEdit): ?><label><input type="checkbox" name="completed" <?= $form['completed'] ? 'checked' : '' ?>> Mark as completed</label><?php endif; ?>
            </div>
          </div>
          </div>

          <div class="form-foot">
            <span class="req">* Required. Leave a goal blank if it doesn&rsquo;t apply.</span>
            <div class="foot-btns">
              <a href="<?= h($backUrl) ?>" class="btn btn-outline small">Cancel</a>
              <button type="submit" class="btn btn-yellow small"><?= $isEdit ? 'Save changes' : 'Create campaign' ?></button>
            </div>
          </div>
        </form>

        <aside class="side">
          <section class="card panel preview-panel" aria-live="polite">
            <h3>Preview</h3>
            <div class="preview-card">
              <p class="pv-name" id="pvName">Campaign name</p>
              <p class="pv-meta"><span class="ctype" id="pvType">Cash</span> <span id="pvDates"></span></p>
              <div class="pv-goal goal-cash"><small>Cash</small><div class="bar"><span style="width:0"></span></div><small id="pvCash"></small></div>
              <div class="pv-goal goal-items"><small>Items</small><div class="bar"><span style="width:0"></span></div><small id="pvItems"></small></div>
              <div class="pv-goal"><small>Volunteer shifts</small><div class="bar"><span style="width:0"></span></div><small id="pvVol"></small></div>
            </div>
          </section>
          <section class="card panel">
            <h3>Tips</h3>
            <p>Pick <strong>Cash + items</strong> for food drives that also take money.</p>
            <p>Gifts are linked to a campaign from the donation form.</p>
            <p>A campaign shows as <em>Upcoming</em> before its start date and <em>Completed</em> after its end date, or as soon as you mark it completed.</p>
          </section>
        </aside>
      </div>

      <script src="js/campaigns.js"></script>
<?php fishnet_close(); ?>
