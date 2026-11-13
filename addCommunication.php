<?php
// One-on-one contacts save (UF2-146 donor from donor_summary, UF2-147 person contacted from dbpersons,
// UF2-148 campaign from dbevents) and return to the donor's Communications tab.
// Outreach posts (UF2-141..144) are saved through dbOutreach.php, may be linked to an active campaign,
// support photo uploads, and appear in the Communications -> Outreach view.
// ?kind=outreach opens with Outreach picked (for example from a campaign page); ?donor_id=N preselects a donor.
// The donor is picked with a search (js/commDonorSearch.js -> donorLookup.php) that fills a hidden donor_id;
// the page only loads the one chosen donor (comm_donor_choice()), never the full donor list.
// Gates: communications view to see the form; communications view + edit and donors view to save (otherwise
// the form is read-only and a POST gets 403). The layout checks the CSRF token on every POST.
require_once 'include/fishnet-layout.php';
require_once 'database/dbCommunications.php';
require_once 'database/dbOutreach.php';

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$title = 'Add communication';

if (!fish_can('communications', 'view')) {
  if ($isPost) { http_response_code(403); exit('Your account cannot view communications.'); }
  fishnet_open($title, 'communications', ['forms.css', 'communications.css']);
  echo '<div class="notice" role="status">Your account cannot view communications.</div>';
  fishnet_close();
  exit;
}
$canSeeDonors = fish_can('donors', 'view');
$canSave = fish_can_log_communications();
if ($isPost && !$canSave) {
  http_response_code(403);
  exit('Your account cannot log communications.');
}

$campaigns = $staff = $outCampaigns = [];   // id => name
$donors = [];                         // id => name; holds at most the one chosen donor
$chosenDonor = null;                  // id, name, type, town of that donor, for the search card
$loadError = false;
try {
  if ($canSave) { $outCampaigns = outreach_campaign_options(); }   // active campaigns only
  if ($canSeeDonors) {   // donor names only for accounts that may see donors
    // A POST uses only the posted donor (the form posts back to ?donor_id=, which must not override it).
    $wanted = $isPost ? fish_str($_POST, 'donor_id') : fish_str($_GET, 'donor_id');
    $chosenDonor = comm_donor_choice($wanted);
    if ($chosenDonor) { $donors = [$chosenDonor['id'] => $chosenDonor['name']]; }
  }
  if ($canSave) { $staff = comm_staff_options(); }    // account ids only for accounts that can save
  $campaigns = comm_campaign_options();
} catch (mysqli_sql_exception $e) {
  error_log('addCommunication.php: ' . $e->getMessage());
  $loadError = true;
}

$form = comm_form_blank();
$outForm = outreach_form_blank(auth_current_user()->get_id());
if (!outreach_in_map($staff, $outForm['posted_by'])) { $outForm['posted_by'] = ''; }   // default to the signed-in user when they can be picked
if (!$isPost && $chosenDonor) { $form['donor'] = (string) $chosenDonor['id']; }
$outreachFirst = fish_str($_GET, 'kind') === 'outreach';
$errors = [];

if ($isPost) {
  $outreachFirst = fish_str($_POST, 'kind') === 'outreach';
  if ($loadError) {
    http_response_code(503);
    $errors[] = 'Could not load everything this form needs. Please try again shortly.';
  } elseif ($outreachFirst) {
    $outForm = array_merge($outForm, outreach_form_from_post($_POST));
    $errors = outreach_form_validate($outForm, $staff, $outCampaigns);
    [$photo, $photoError] = outreach_photo_check($_FILES['photo'] ?? null);
    if ($photoError !== null) { $errors[] = $photoError; }
      if (!$errors) {
      $oid = outreach_create($outForm, $photo, $staff, auth_current_user()->get_id());
      if ($oid !== false) {
        header('Location: communications.php?tab=outreach&saved=1', true, 303);
        exit;
      }
      $errors[] = 'Could not save the outreach post. Please check the campaign is still active and try again.';
    }
  } else {
    $form = array_merge($form, comm_form_from_post($_POST));
    $errors = comm_form_validate($form, $donors, $staff, $campaigns);
    if (!$errors) {
      $id = comm_create($form, $staff, auth_current_user()->get_id());
      if ($id !== false) {
        header('Location: donorProfile.php?id=' . (int) $form['donor'] . '&tab=comm&comm=1', true, 303);
        exit;
      }
      $errors[] = 'Could not save the communication. Please check the donor is still active and try again; if it keeps failing, ask an administrator.';
    }
  }
}

// The card shows a donor only when the (validated) form still holds it.
if ($form['donor'] === '' || !$chosenDonor || (string) $chosenDonor['id'] !== (string) $form['donor']) { $chosenDonor = null; }
$sel = function ($a, $b) { return (string) $a === (string) $b ? ' selected' : ''; };
fishnet_open($title, 'communications', ['forms.css', 'communications.css']);
?>

      <section class="page-head">
        <div>
          <p class="crumbs"><a href="communications.php">Communications</a> &rsaquo; Add communication</p>
          <h1>Add communication</h1>
        </div>
      </section>

      <div class="split">
        <form class="card form-card comm-form" method="post" enctype="multipart/form-data" novalidate>
          <?= auth_csrf_field() ?>
          <?php if (!$canSave): ?>
          <div class="notice" role="status">Your account can view communications but cannot log them.</div>
          <?php endif; ?>
          <?php if ($loadError && !$isPost): ?>
          <div class="notice" role="alert">Could not load everything this form needs. Please try again shortly.</div>
          <?php endif; ?>
          <?php if ($errors): ?>
          <div class="form-errors" role="alert">
            <strong>Please fix the following:</strong>
            <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
          </div>
          <?php endif; ?>

          <div>
            <div class="group-title">1 &middot; What kind of communication?</div>
            <div class="kind-choices" role="radiogroup" aria-label="Kind of communication">
              <label class="kind-choice">
                <input type="radio" name="kind" value="one_on_one" id="kindOne" <?= $outreachFirst ? '' : 'checked' ?>>
                <span><strong>One-on-one contact</strong><small>Call, email, letter, text or visit with a donor</small></span>
              </label>
              <label class="kind-choice">
                <input type="radio" name="kind" value="outreach" id="kindOut" <?= $outreachFirst ? 'checked' : '' ?>>
                <span><strong>Outreach</strong><small>Social media post or mass mailing to many people</small></span>
              </label>
            </div>
          </div>

          <fieldset class="plain"<?= $canSave ? '' : ' disabled' ?>>

          <!-- ===== One-on-one ===== -->
          <div class="only-one">
            <div class="group-title">2 &middot; Who</div>
            <div class="grid g-2">
              <div class="field">
                <label for="donorSearch">Donor *</label>
                <?php if (!$canSeeDonors): ?>
                <div class="notice" role="status">Your account cannot view donors.</div>
                <?php else: ?>
                <input type="hidden" name="donor_id" id="donor_id" value="<?= h($form['donor']) ?>">
                <div class="donor-box" id="donorSearchBox"<?= $chosenDonor ? ' hidden' : '' ?>>
                  <input type="text" id="donorSearch" placeholder="Search donors by name, email or phone..." autocomplete="off" aria-controls="donorResults">
                  <ul class="results" id="donorResults" aria-live="polite" hidden></ul>
                </div>
                <div class="donor-box chosen" id="donorChosen"<?= $chosenDonor ? '' : ' hidden' ?>>
                  <span class="initials" id="chosenInitials"><?= $chosenDonor ? h(initials($chosenDonor['name'])) : '' ?></span>
                  <div>
                    <strong id="chosenName"><?= $chosenDonor ? h($chosenDonor['name']) : '' ?></strong>
                    <p class="meta" id="chosenMeta"><?= $chosenDonor ? h(implode(' · ', array_filter([$chosenDonor['type'], $chosenDonor['town']], 'strlen'))) : '' ?></p>
                  </div>
                  <button type="button" class="link" id="changeDonor">Change</button>
                </div>
                <noscript><p class="muted">The donor search needs JavaScript.</p></noscript>
                <?php endif; ?>
              </div>
              <div class="field">
                <label for="person">Person contacted</label>
                <select id="person" name="person_contacted">
                  <option value="">Not recorded</option>
                  <?php foreach ($staff as $id => $name): ?><option value="<?= h($id) ?>"<?= $form['person_is_staff'] ? $sel($id, $form['person']) : '' ?>><?= h($name) ?></option><?php endforeach; ?>
                  <option value="<?= h(COMM_PERSON_OTHER) ?>"<?= !$form['person_is_staff'] && $form['person'] === COMM_PERSON_OTHER ? ' selected' : '' ?>>Someone else&hellip;</option>
                </select>
              </div>
              <div class="field person-other-field"><label for="person_other">Name of the person contacted</label><input id="person_other" name="person_contacted_other" maxlength="255" placeholder="Who you spoke with" value="<?= h($form['person_other']) ?>"></div>
            </div>
          </div>

          <div class="only-one">
            <div class="group-title">3 &middot; Details</div>
            <div class="grid g-comm4">
              <div class="field"><label for="comm_type">Type *</label>
                <select id="comm_type" name="comm_type"><option value="">Choose&hellip;</option><?php foreach (COMM_TYPES as $v => $label): ?><option value="<?= $v ?>"<?= $sel($v, $form['comm_type']) ?>><?= $label ?></option><?php endforeach; ?></select></div>
              <div class="field"><label for="direction">Direction</label>
                <select id="direction" name="direction"><?php foreach (COMM_DIRECTIONS as $v => $label): ?><option value="<?= $v ?>"<?= $sel($v, $form['direction']) ?>><?= $label ?></option><?php endforeach; ?></select></div>
              <div class="field"><label for="occurred_at">Date *</label><input type="date" id="occurred_at" name="occurred_at" max="<?= h(donation_today()) ?>" value="<?= h($form['date']) ?>"></div>
              <div class="field"><label for="status">Status</label>
                <select id="status" name="status"><?php foreach (COMM_FORM_STATUSES as $v => $label): ?><option value="<?= $v ?>"<?= $sel($v, $form['status']) ?>><?= $label ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="grid g-subject top-gap">
              <div class="field"><label for="subject">Subject</label><input id="subject" name="subject" maxlength="255" placeholder="What it was about" value="<?= h($form['subject']) ?>"></div>
              <div class="field"><label for="campaign_one">Campaign (optional)</label>
                <select id="campaign_one" name="campaign_id_one"><option value="">None</option>
                  <?php foreach ($campaigns as $id => $name): ?><option value="<?= (int) $id ?>"<?= $sel($id, $form['campaign']) ?>><?= h($name) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="field top-gap"><label for="summary">Summary</label><textarea id="summary" name="summary" rows="3" maxlength="5000" placeholder="What was said or agreed"><?= h($form['summary']) ?></textarea></div>
            <div class="grid g-follow top-gap">
              <label class="check-line"><input type="checkbox" name="follow_up"<?= $form['follow_up'] ? ' checked' : '' ?>> Remind me to follow up</label>
              <div class="field"><label for="follow_up_on">Follow-up date</label><input type="date" id="follow_up_on" name="follow_up_on" value="<?= h($form['follow_up_on']) ?>"></div>
            </div>
          </div>

          <!-- ===== Outreach ===== -->
          <div class="only-out">
            <div class="group-title">2 &middot; Channel <span class="muted">(pick one or more)</span></div>
            <div class="channel-choices" role="group" aria-label="Channels">
              <?php foreach (OUTREACH_CHANNELS as $v => $label): ?>
              <label class="channel"><input type="checkbox" name="channel[]" value="<?= h($v) ?>"<?= in_array($v, $outForm['channels'], true) ? ' checked' : '' ?>><span><?= h($label) ?></span></label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="only-out">
            <div class="group-title">3 &middot; Details</div>
            <div class="grid g-out">
              <div class="field"><label for="campaign_out">Campaign (active only)</label>
                <select id="campaign_out" name="campaign_id"><option value="">None</option>
                  <?php foreach ($outCampaigns as $id => $name): ?><option value="<?= (int) $id ?>"<?= $sel($id, $outForm['campaign']) ?>><?= h($name) ?></option><?php endforeach; ?></select></div>
              <div class="field"><label for="posted_on">Post date *</label><input type="date" id="posted_on" name="posted_on" value="<?= h($outForm['posted_on']) ?>"></div>
              <div class="field"><label for="posted_by">Posted by</label>
                <select id="posted_by" name="posted_by"><option value="">Not recorded</option>
                  <?php foreach ($staff as $id => $name): ?><option value="<?= h($id) ?>"<?= !empty($outForm['posted_by_staff']) || outreach_in_map($staff, $outForm['posted_by']) ? $sel($id, $outForm['posted_by']) : '' ?>><?= h($name) ?></option><?php endforeach; ?>
                  <option value="<?= h(OUTREACH_PERSON_OTHER) ?>"<?= $outForm['posted_by'] === OUTREACH_PERSON_OTHER ? ' selected' : '' ?>>Other</option></select></div>
            </div>
            <div class="field top-gap poster-other-field"><label for="posted_by_other">Name of poster</label><input id="posted_by_other" name="posted_by_other" maxlength="255" value="<?= h($outForm['posted_by_other']) ?>"></div>
            <div class="chan-links">
              <?php foreach (OUTREACH_CHANNELS as $v => $label): ?>
              <div class="field top-gap chan-link" data-channel="<?= h($v) ?>"><label for="url_<?= h($v) ?>"><?= h($label) ?> link</label><input type="url" id="url_<?= h($v) ?>" name="channel_url[<?= h($v) ?>]" placeholder="https://" maxlength="500" value="<?= h($outForm['urls'][$v] ?? '') ?>"></div>
              <?php endforeach; ?>
            </div>
            <div class="field top-gap"><label for="caption">Caption / content</label><textarea id="caption" name="caption" rows="3" maxlength="5000" placeholder="The post caption, or the text of the email"><?= h($outForm['caption']) ?></textarea></div>
            <div class="field top-gap"><label for="photo">Photo or screenshot</label><input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp" class="file-input">
              <p class="hint">JPG, PNG or WEBP, up to 5 MB. After an error, choose the photo again.</p></div>
          </div>

          <div class="only-out">
            <div class="group-title">4 &middot; Results <span class="muted">(optional, update later)</span></div>
            <div class="grid g-comm4">
              <?php foreach (OUTREACH_COUNT_LABELS as $k => $label): ?>
              <div class="field"><label for="<?= $k ?>"><?= $k === 'reach' ? 'Reach / views' : h($label) ?></label><input id="<?= $k ?>" name="<?= $k ?>" inputmode="numeric" value="<?= h($outForm[$k]) ?>"></div>
              <?php endforeach; ?>
            </div>
          </div>

          </fieldset>

          <div class="spacer"></div>
          <div class="form-foot right">
            <div class="foot-btns">
              <a href="communications.php" class="btn btn-outline small">Cancel</a>
              <?php if ($canSave): ?><button type="submit" class="btn btn-yellow small"><span class="only-one-inline">Save contact</span><span class="only-out-inline">Save outreach</span></button><?php endif; ?>
            </div>
          </div>
        </form>

        <aside class="side">
          <section class="card panel only-one-panel">
            <h3>Where it shows</h3>
            <p>On the donor&rsquo;s Communications tab, in One-on-one, and on the campaign if you pick one.</p>
          </section>
          <section class="card panel preview-panel only-out-panel">
            <h3>Will show on this campaign</h3>
            <p>Pick a campaign and this post appears on that campaign&rsquo;s Communications tab. Outreach isn&rsquo;t tied to one donor, so it shows in the Outreach tab, not on donor profiles.</p>
          </section>
        </aside>
      </div>

<?php if ($canSeeDonors): ?>
<script src="js/commDonorSearch.js"></script>
<?php endif; ?>
<?php fishnet_close(); ?>
