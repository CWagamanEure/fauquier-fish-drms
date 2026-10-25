<?php
// Log new volunteer hours for one donor (addHours.php?donor_id=N). FRONT END ONLY: the data below is fake and nothing is saved.
// Backend TODO: handle the POST (check fish_can('donors', 'edit'), validate, write volunteer_hours), load the entry
// when ?id= is given, and fill $volunteers / $jobs / $campaigns / $summary / $history from the database.
require_once 'include/fishnet-layout.php';

$isEdit = false;   // addHours.php only logs new hours; editing lives in hours.php
// Which donor's profile to go back to: donorProfile.php needs ?id=, or it sends you back to the donor list.
$donorId = (int) ($_GET['donor_id'] ?? 1);   // Backend TODO: use the entry's donor_id when editing

// ---------- fake data ----------
$jobs       = ['Pantry sorting', 'Pantry intake', 'Drop-off table', 'Delivery driver', 'Office help', 'Other'];
$campaigns  = ['None', 'Thanksgiving Food Drive', 'Winter Coat Collection', 'Fall Volunteer Push'];
$form = $isEdit
  ? ['volunteer' => 'Jhon D. Rangel Duran', 'date' => '2026-10-22', 'start' => '09:00', 'end' => '13:30', 'hours' => '4.5',
     'job' => 'Pantry sorting', 'campaign' => 'Thanksgiving Food Drive', 'location' => 'FISH pantry',
     'notes' => 'Sorted ~300 cans from Main St. Church drop-off. Trained 2 new volunteers.', 'verified' => true, 'letter' => false]
  : ['volunteer' => 'Jhon D. Rangel Duran', 'date' => date('Y-m-d'), 'start' => '', 'end' => '', 'hours' => '',
     'job' => 'Pantry sorting', 'campaign' => 'None', 'location' => 'FISH pantry', 'notes' => '', 'verified' => false, 'letter' => false];
$summary = ['Hours this year' => '42', 'Shifts this year' => '11', 'Last shift' => 'Oct 15, 2026', 'Usual role' => 'Pantry sorting'];
$history = [['Hours changed 4 → 4.5', 'Oct 23 · James A.'], ['Entry logged', 'Oct 22 · Charity C.']];
$extraPeople = [];
// ---------- end fake data ----------

$title = $isEdit ? 'Edit volunteer hours' : 'Log volunteer hours';
$sel = fn($a, $b) => $a === $b ? 'selected' : '';

fishnet_open($title, 'donor', ['forms.css', 'volunteer.css']);
?>

      <section class="page-head">
        <div>
          <p class="crumbs"><a href="donor.php">Donor</a> &rsaquo; <a href="donorProfile.php?id=<?= $donorId ?>"><?= h($form['volunteer']) ?></a> &rsaquo; <?= $title ?></p>
          <h1><?= $title ?></h1>
        </div>
      </section>

      <div class="split">
        <form class="card form-card" method="post" novalidate>
          <?= auth_csrf_field() ?>

          <div>
            <div class="group-title">1 &middot; Volunteer</div>
            <div class="vol-who">
              <span class="vol-initials"><?= h(initials($form['volunteer'])) ?></span>
              <strong><?= h($form['volunteer']) ?></strong>
              <input type="hidden" name="donor_id" value="<?= $donorId ?>">
            </div>
          </div>

          <div>
            <div class="group-title">2 &middot; When</div>
            <div class="grid g-vol-when">
              <div class="field"><label for="date">Date *</label><input type="date" id="date" name="date" required value="<?= h($form['date']) ?>"></div>
              <div class="field"><label for="start">Start time</label><input type="time" id="start" name="start_time" value="<?= h($form['start']) ?>"></div>
              <div class="field"><label for="end">End time</label><input type="time" id="end" name="end_time" value="<?= h($form['end']) ?>"></div>
              <div class="field hours-field">
                <label for="hours">Hours *</label>
                <input id="hours" name="hours" inputmode="decimal" required value="<?= h($form['hours']) ?>" placeholder="e.g. 4.5">
              </div>
            </div>
          </div>

          <div>
            <div class="group-title">3 &middot; What they did</div>
            <div class="grid g-vol-what">
              <div class="field">
                <label for="job">Job / role</label>
                <select id="job" name="job"><?php foreach ($jobs as $j): ?><option <?= $sel($j, $form['job']) ?>><?= h($j) ?></option><?php endforeach; ?></select>
              </div>
              <div class="field">
                <label for="campaign">Campaign (optional)</label>
                <select id="campaign" name="campaign_id"><?php foreach ($campaigns as $c): ?><option <?= $sel($c, $form['campaign']) ?>><?= h($c) ?></option><?php endforeach; ?></select>
              </div>
              <div class="field"><label for="location">Location</label><input id="location" name="location" value="<?= h($form['location']) ?>"></div>
            </div>
            <div class="field top-gap">
              <label for="notes">Notes</label>
              <textarea id="notes" name="notes" rows="3" placeholder="e.g. Sorted canned goods from the church drop-off"><?= h($form['notes']) ?></textarea>
            </div>
          </div>

          <?php if (!$isEdit): ?>
          <div>
            <div class="group-title">4 &middot; Same shift for more people? <span class="muted">(optional)</span></div>
            <div class="people-box">
              <?php foreach ($extraPeople as $p): ?>
              <span class="person-chip"><?= h($p) ?> <button type="button" aria-label="Remove <?= h($p) ?>">&times;</button></span>
              <?php endforeach; ?>
              <input type="text" name="extra_volunteers" placeholder="Add volunteer..." aria-label="Add volunteer">
            </div>
            <p class="hint">Each person gets their own entry with the same date and hours.</p>
          </div>
          <?php else: ?>
          <div>
            <div class="group-title">Status</div>
            <div class="checks">
              <label><input type="checkbox" name="verified" <?= $form['verified'] ? 'checked' : '' ?>> Hours verified by coordinator</label>
              <label><input type="checkbox" name="service_letter" <?= $form['letter'] ? 'checked' : '' ?>> Counts toward service-hour letter</label>
            </div>
          </div>
          <?php endif; ?>

          <div class="spacer"></div>
          <div class="form-foot">
            <span class="req">* Required</span>
            <div class="foot-btns">
              <a href="donorProfile.php?id=<?= $donorId ?>" class="btn btn-outline small">Cancel</a>
              <?php if ($isEdit): ?>
              <button type="submit" class="btn btn-yellow small">Save changes</button>
              <?php else: ?>
              <button type="submit" class="btn btn-yellow small">Save hours</button>
              <?php endif; ?>
            </div>
          </div>
        </form>

        <aside class="side">
          <section class="card panel">
            <h3><?= h($form['volunteer']) ?></h3>
            <dl class="facts">
              <?php foreach ($summary as $k => $v): ?><dt><?= h($k) ?></dt><dd><?= h($v) ?></dd><?php endforeach; ?>
            </dl>
            <p class="panel-link"><a href="donorProfile.php?id=<?= $donorId ?>" class="link">View volunteer tab &rarr;</a></p>
          </section>

          <?php if ($isEdit): ?>
          <section class="card panel">
            <h3>Change history</h3>
            <ul class="timeline">
              <?php foreach ($history as [$what, $when]): ?><li><strong><?= h($what) ?></strong><span><?= h($when) ?></span></li><?php endforeach; ?>
            </ul>
          </section>
          <section class="card panel danger">
            <h3>Delete this entry</h3>
            <p>Removes these <?= h($form['hours']) ?> hours from the volunteer&rsquo;s total and the campaign.</p>
            <button type="button" class="btn btn-danger small">Delete entry</button>
          </section>
          <?php endif; ?>
        </aside>
      </div>

<?php fishnet_close(); ?>
