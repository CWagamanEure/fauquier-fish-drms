<?php
// Add / edit campaign form: FRONT END ONLY. Nothing is saved yet.
// Backend TODO: handle the POST (check fish_can('campaigns', 'edit'), validate, insert/update), and load the
// campaign into $form when ?id= is given. Field names below are what the backend should read.
require_once 'include/fishnet-layout.php';

$isEdit = isset($_GET['id']);
// ---------- fake data ----------
$owners = ['Charity Coleman', 'Dana Price', 'James Andrews', 'Tristan Chavez-Duran'];
$form = $isEdit
  ? ['name' => 'Thanksgiving Food Drive', 'description' => 'Holiday meals for about 300 local families.', 'owner' => 'Charity Coleman',
     'type' => 'both', 'cash_goal' => '12000', 'item_goal' => '2000', 'item_unit' => 'cans', 'volunteer_goal' => '40',
     'start_date' => '2026-11-01', 'end_date' => '2026-11-24', 'fund' => 'Food Pantry', 'location' => 'FISH pantry']
  : ['name' => '', 'description' => '', 'owner' => 'Charity Coleman', 'type' => 'cash', 'cash_goal' => '', 'item_goal' => '',
     'item_unit' => 'items', 'volunteer_goal' => '', 'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d', strtotime('+30 days')),
     'fund' => 'General Fund', 'location' => ''];
// ---------- end fake data ----------
$types = ['cash' => ['Cash', 'Money only'], 'items' => ['Items', 'Food, coats, supplies'],
          'both' => ['Cash + items', 'Money and items'], 'volunteers' => ['Volunteers', 'Volunteer time']];
$units = ['items', 'cans', 'lbs', 'bags', 'boxes', 'coats', 'kits', 'meals'];
$title = $isEdit ? 'Edit campaign' : 'Add campaign';

fishnet_open($title, 'campaigns', ['forms.css', 'campaigns.css']);
?>

      <section class="page-head">
        <div>
          <p class="crumbs"><a href="campaigns.php">Campaigns</a> &rsaquo;
            <?php if ($isEdit): ?><a href="campaign.php?id=1"><?= h($form['name']) ?></a> &rsaquo; Edit<?php else: ?>New campaign<?php endif; ?></p>
          <h1><?= $title ?></h1>
        </div>
      </section>

      <div class="split">
        <form class="card form-card" id="campaignForm" method="post" novalidate>
          <?= auth_csrf_field() ?>
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
                  <?php foreach ($owners as $o): ?><option <?= $o === $form['owner'] ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="field top-gap">
              <label for="description">Description</label>
              <textarea id="description" name="description" rows="2" placeholder="What is this campaign for and who does it help?"><?= h($form['description']) ?></textarea>
            </div>
          </div>

          <div>
            <div class="group-title">What are you collecting?</div>
            <div class="type-choices" role="radiogroup" aria-label="Campaign type">
              <?php foreach ($types as $t => [$label, $hint]): ?>
              <label class="type-choice">
                <input type="radio" name="type" value="<?= $t ?>" <?= $form['type'] === $t ? 'checked' : '' ?>>
                <span><strong><?= $label ?></strong><small><?= $hint ?></small></span>
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
                <select id="item_unit" name="item_unit"><?php foreach ($units as $u): ?><option <?= $u === $form['item_unit'] ? 'selected' : '' ?>><?= $u ?></option><?php endforeach; ?></select></div>
              <div class="field"><label for="volunteer_goal">Volunteer shifts</label><input id="volunteer_goal" name="volunteer_goal" inputmode="numeric" value="<?= h($form['volunteer_goal']) ?>" placeholder="e.g. 40"></div>
            </div>
            <div class="grid g-dates top-gap">
              <div class="field"><label for="start_date">Start date *</label><input type="date" id="start_date" name="start_date" required value="<?= h($form['start_date']) ?>"></div>
              <div class="field"><label for="end_date">End date *</label><input type="date" id="end_date" name="end_date" required value="<?= h($form['end_date']) ?>"></div>
              <div class="field"><label for="fund">Fund / designation</label><input id="fund" name="fund" value="<?= h($form['fund']) ?>"></div>
              <div class="field"><label for="location">Drop-off location</label><input id="location" name="location" value="<?= h($form['location']) ?>" placeholder="e.g. FISH pantry"></div>
            </div>
          </div>

          <div>
            <div class="group-title">Outreach</div>
            <div class="checks">
              <label><input type="checkbox" name="show_on_dashboard" checked> Show on dashboard</label>
              <label><input type="checkbox" name="email_past_donors" <?= $isEdit ? 'checked' : '' ?>> Email past donors</label>
              <label><input type="checkbox" name="ask_volunteers"> Ask volunteers to sign up</label>
              <?php if ($isEdit): ?><label><input type="checkbox" name="completed"> Mark as completed</label><?php endif; ?>
            </div>
          </div>
          </div>

          <div class="form-foot">
            <span class="req">* Required. Leave a goal blank if it doesn&rsquo;t apply.</span>
            <div class="foot-btns">
              <a href="<?= $isEdit ? 'campaign.php?id=1' : 'campaigns.php' ?>" class="btn btn-outline small">Cancel</a>
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
            <p>A campaign shows as <em>Upcoming</em> before its start date and <em>Completed</em> after its end date.</p>
          </section>
        </aside>
      </div>

      <script src="js/campaigns.js"></script>
<?php fishnet_close(); ?>
