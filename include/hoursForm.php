<?php
// The volunteer hours form shared by addHours.php (log) and hours.php (edit / view), from PR #26's mock-up.
// Only defines hours_form_render(); it runs no query and reads no request, so requesting this file directly
// prints nothing. $v keys:
//   edit (bool), action (form URL), donor ['id', 'name'], form (hours_form_* array), errors (list),
//   jobs (hours_job_options()), campaigns (id => name), summary (label => value), history (list of
//   [what, when]; edit only), readonly (bool), delete_action (URL, or '' when the account cannot delete),
//   entry_hours (stored hours label; edit only), readonly_notice (text shown when readonly).

function hours_form_render(array $v) {
  $form = $v['form'];
  $edit = $v['edit'];
  $readonly = $v['readonly'];
  $profile = 'donorProfile.php?id=' . (int) $v['donor']['id'];
  $jobs = $v['jobs'];
  if ($form['job'] !== '' && !in_array($form['job'], $jobs, true)) { $jobs[] = $form['job']; }   // a stored legacy value round-trips
  $sel = function ($a, $b) { return (string) $a === (string) $b ? ' selected' : ''; };
?>
      <div class="split">
        <form class="card form-card" method="post" action="<?= h($v['action']) ?>" id="hoursForm" novalidate>
          <?= auth_csrf_field() ?>
          <?php if (!$readonly): ?>
          <!-- Hidden default button: pressing Enter in a field saves normally -->
          <button type="submit" name="action" value="save" hidden></button>
          <?php endif; ?>

          <?php if ($readonly): ?>
          <div class="notice" role="status"><?= h($v['readonly_notice']) ?></div>
          <?php endif; ?>
          <?php if ($v['errors']): ?>
          <div class="form-errors" role="alert">
            <strong>Please fix the following:</strong>
            <ul><?php foreach ($v['errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
          </div>
          <?php endif; ?>

          <fieldset class="plain"<?= $readonly ? ' disabled' : '' ?>>
          <div>
            <div class="group-title">1 &middot; Volunteer</div>
            <div class="vol-who">
              <span class="vol-initials"><?= h(initials($v['donor']['name'])) ?></span>
              <strong><?= h($v['donor']['name']) ?></strong>
            </div>
          </div>

          <div>
            <div class="group-title">2 &middot; When</div>
            <div class="grid g-vol-when">
              <div class="field"><label for="date">Date *</label><input type="date" id="date" name="date" required max="<?= h(donation_today()) ?>" value="<?= h($form['date']) ?>"></div>
              <div class="field"><label for="start">Start time</label><input type="time" id="start" name="start_time" value="<?= h($form['start']) ?>"></div>
              <div class="field"><label for="end">End time</label><input type="time" id="end" name="end_time" value="<?= h($form['end']) ?>"></div>
              <div class="field hours-field">
                <label for="hours">Hours *</label>
                <input id="hours" name="hours" inputmode="decimal" required value="<?= h($form['hours']) ?>" placeholder="e.g. 4.5">
              </div>
            </div>
            <p class="hint">Hours fill in from the start and end time; change them for a break.</p>
          </div>

          <div>
            <div class="group-title">3 &middot; What they did</div>
            <div class="grid g-vol-what">
              <div class="field">
                <label for="job">Job / role</label>
                <select id="job" name="job"><option value="">Choose…</option><?php foreach ($jobs as $j): ?><option value="<?= h($j) ?>"<?= $sel($j, $form['job']) ?>><?= h($j) ?></option><?php endforeach; ?></select>
              </div>
              <div class="field">
                <label for="campaign">Campaign (optional)</label>
                <select id="campaign" name="campaign_id"><option value="">None</option><?php foreach ($v['campaigns'] as $id => $name): ?><option value="<?= (int) $id ?>"<?= $sel($id, $form['campaign']) ?>><?= h($name) ?></option><?php endforeach; ?></select>
              </div>
              <div class="field"><label for="location">Location</label><input id="location" name="location" maxlength="255" value="<?= h($form['location']) ?>"></div>
            </div>
            <div class="field top-gap">
              <label for="notes">Notes</label>
              <textarea id="notes" name="notes" rows="3" maxlength="2000" placeholder="e.g. Sorted canned goods from the church drop-off"><?= h($form['notes']) ?></textarea>
            </div>
          </div>

          <?php if (!$edit): ?>
          <div>
            <div class="group-title">4 &middot; Same shift for more people? <span class="muted">(optional)</span></div>
            <div class="people-box" id="peopleBox" data-main="<?= (int) $v['donor']['id'] ?>">
              <?php foreach ($form['extra'] as $p): ?>
              <span class="person-chip"><?= h($p['name']) ?> <button type="button" aria-label="Remove <?= h($p['name']) ?>">&times;</button><input type="hidden" name="extra_donor[]" value="<?= (int) $p['id'] ?>"></span>
              <?php endforeach; ?>
              <input type="text" id="volSearch" placeholder="Add volunteer..." aria-label="Add volunteer" autocomplete="off" aria-controls="volResults">
            </div>
            <ul class="vol-results" id="volResults" aria-live="polite" hidden></ul>
            <p class="hint">Each person gets their own entry with the same date and hours.</p>
          </div>
          <?php else: ?>
          <div>
            <div class="group-title">Status</div>
            <div class="checks">
              <label><input type="checkbox" name="verified"<?= $form['verified'] ? ' checked' : '' ?>> Hours verified by coordinator</label>
              <label><input type="checkbox" name="service_letter"<?= $form['letter'] ? ' checked' : '' ?>> Counts toward service-hour letter</label>
            </div>
          </div>
          <?php endif; ?>
          </fieldset>

          <div class="spacer"></div>
          <div class="form-foot">
            <span class="req"><?= $readonly ? '' : '* Required' ?></span>
            <div class="foot-btns">
              <a href="<?= h($profile) ?>&amp;tab=vol" class="btn btn-outline small"><?= $readonly ? 'Back to profile' : 'Cancel' ?></a>
              <?php if (!$readonly): ?>
              <button type="submit" name="action" value="save" class="btn btn-yellow small"><?= $edit ? 'Save changes' : 'Save hours' ?></button>
              <?php endif; ?>
            </div>
          </div>
        </form>

        <aside class="side">
          <section class="card panel">
            <h3><?= h($v['donor']['name']) ?></h3>
            <dl class="facts">
              <?php foreach ($v['summary'] as $k => $val): ?><dt><?= h($k) ?></dt><dd><?= h($val) ?></dd><?php endforeach; ?>
            </dl>
            <p class="panel-link"><a href="<?= h($profile) ?>&amp;tab=vol" class="link">View volunteer tab &rarr;</a></p>
          </section>

          <?php if ($edit): ?>
          <section class="card panel">
            <h3>Change history</h3>
            <ul class="timeline">
              <?php foreach ($v['history'] as [$what, $when]): ?><li><strong><?= h($what) ?></strong><span><?= h($when) ?></span></li><?php endforeach; ?>
            </ul>
          </section>
          <?php if ($v['delete_action'] !== ''): ?>
          <section class="card panel danger">
            <h3>Delete this entry</h3>
            <p>Removes these <?= h($v['entry_hours']) ?> hours from the volunteer&rsquo;s total and the campaign.</p>
            <form method="post" action="<?= h($v['delete_action']) ?>" id="deleteForm">
              <?= auth_csrf_field() ?>
              <button type="submit" name="action" value="delete" class="btn btn-danger small">Delete entry</button>
            </form>
          </section>
          <?php endif; ?>
          <?php endif; ?>
        </aside>
      </div>
<?php
}
