<?php
// css and php to keep the pages consistant
require_once 'include/fishnet-layout.php';
require_once 'include/donorDataStopgap.php';

/* STOPGAP: access check disabled until sessions/login are wired up. Re-enable later (same rule addDonor.php used):
   if (($_SESSION['access_level'] ?? 0) < 2) { header('Location: login.php'); exit; } */

$id = (int)($_GET['id'] ?? 0);
$p  = stopgap_profile($id);              // STOPGAP: REPLACE with a SELECT of this donor by id
if (!$p) { header('Location: donor.php'); exit; }
$displayName = stopgap_display_name($p);

$states = ['AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY'];
$preferredOptions = ['Email', 'Phone', 'Mail'];
$errors = [];
$form = $p;                               // values shown in the form (replaced by posted values on errors)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? 'save';

  if ($action === 'deactivate') {
    /* STOPGAP START: remembers "inactive" in the session.
       REPLACE with something like UPDATE donors SET active = 0 WHERE id = ? */
    $_SESSION['stopgap_inactive'][$id] = true;
    /* STOPGAP END */
    header('Location: donor.php');
    exit;
  }

  // ---- Save changes ----
  $in = fn($k) => trim((string)($_POST[$k] ?? ''));
  $form = array_merge($p, [
    'type'           => $in('type'),
    'first'          => $in('first'),
    'middle'         => $in('middle'),
    'last'           => $in('last'),
    'org_name'       => $in('org_name'),
    'email'          => $in('email'),
    'phone'          => $in('phone'),
    'preferred'      => $in('preferred'),
    'street'         => $in('street'),
    'city'           => $in('city'),
    'state'          => $in('state'),
    'zip'            => $in('zip'),
    'volunteer'      => isset($_POST['volunteer'])      ? 1 : 0,
    'email_updates'  => isset($_POST['email_updates'])  ? 1 : 0,
    'mail_receipts'  => isset($_POST['mail_receipts'])  ? 1 : 0,
    'do_not_contact' => isset($_POST['do_not_contact']) ? 1 : 0,
  ]);

  // ---- Validation (self-contained for now) ----
  if (!in_array($form['type'], ['Individual', 'Organization'], true)) $form['type'] = 'Individual';
  if ($form['type'] === 'Individual') {
    if ($form['first'] === '') $errors[] = 'First name is required.';
    if ($form['last']  === '') $errors[] = 'Last name is required.';
  } elseif ($form['org_name'] === '') {
    $errors[] = 'Organization name is required.';
  }
  if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Enter a valid email address.';
  }
  if ($form['phone'] !== '') {
    $digits = preg_replace('/\D/', '', $form['phone']);
    if (strlen($digits) === 11 && $digits[0] === '1') $digits = substr($digits, 1);
    if (strlen($digits) !== 10) {
      $errors[] = 'Phone number must have 10 digits.';
    } else {
      $form['phone'] = sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
    }
  }
  if ($form['zip'] !== '' && !preg_match('/^\d{5}(-\d{4})?$/', $form['zip'])) {
    $errors[] = 'ZIP code must be 5 digits.';
  }
  if ($form['state'] !== '' && !in_array($form['state'], $states, true)) $errors[] = 'Choose a valid state.';
  if ($form['preferred'] !== '' && !in_array($form['preferred'], $preferredOptions, true)) $form['preferred'] = '';

  if (!$errors) {
    /* STOPGAP START: saves the edits to the session so they show on donor.php / donorProfile.php.
       REPLACE with an UPDATE on the real donors table. */
    $_SESSION['stopgap_edits'][$id] = $form;
    /* STOPGAP END */
    header('Location: donorProfile.php?id=' . $id);
    exit;
  }
}

fishnet_open('Edit donor', 'donor', 'forms.css');
?>

      <section class="page-head">
        <div>
          <p class="crumbs"><a href="donor.php">Donor</a> &rsaquo; <a href="donorProfile.php?id=<?= $id ?>"><strong><?= h($displayName) ?></strong></a> &rsaquo; <strong>Edit</strong></p>
          <h1>Edit donor</h1>
        </div>
      </section>

      <div class="split">
        <form class="card form-card" method="post" action="updateDonor.php?id=<?= $id ?>" novalidate>

          <?php if ($errors): ?>
          <div class="form-errors" role="alert">
            <strong>Please fix the following:</strong>
            <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
          </div>
          <?php endif; ?>

          <div class="form-field">
            <span class="lbl">Donor type</span>
            <div class="seg">
              <input type="radio" name="type" id="typeInd" value="Individual" <?= $form['type'] === 'Individual' ? 'checked' : '' ?>>
              <label for="typeInd">Individual</label>
              <input type="radio" name="type" id="typeOrg" value="Organization" <?= $form['type'] === 'Organization' ? 'checked' : '' ?>>
              <label for="typeOrg">Organization</label>
            </div>
          </div>

          <div>
            <div class="group-title">Name</div>
            <div class="grid g-name only-ind">
              <div class="form-field"><label for="first">First name *</label><input type="text" id="first" name="first" value="<?= h($form['first']) ?>" placeholder="First name"></div>
              <div class="form-field"><label for="middle">Middle</label><input type="text" id="middle" name="middle" value="<?= h($form['middle']) ?>" placeholder="Middle"></div>
              <div class="form-field"><label for="last">Last name *</label><input type="text" id="last" name="last" value="<?= h($form['last']) ?>" placeholder="Last name"></div>
            </div>
            <div class="only-org">
              <div class="form-field"><label for="org_name">Organization name *</label><input type="text" id="org_name" name="org_name" value="<?= h($form['org_name']) ?>" placeholder="Organization name"></div>
            </div>
          </div>

          <div>
            <div class="group-title">Contact</div>
            <div class="grid g-contact">
              <div class="form-field"><label for="email">Email</label><input type="email" id="email" name="email" value="<?= h($form['email']) ?>" placeholder="name@email.com"></div>
              <div class="form-field"><label for="phone">Phone</label><input type="tel" id="phone" name="phone" value="<?= h($form['phone']) ?>" placeholder="(540) 555-0000"></div>
              <div class="form-field">
                <label for="preferred">Preferred contact</label>
                <select id="preferred" name="preferred">
                  <option value="">Choose&hellip;</option>
                  <?php foreach ($preferredOptions as $o): ?>
                    <option value="<?= h($o) ?>" <?= $form['preferred'] === $o ? 'selected' : '' ?>><?= h($o) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>

          <div>
            <div class="group-title">Address</div>
            <div class="grid g-addr">
              <div class="form-field"><label for="street">Street</label><input type="text" id="street" name="street" value="<?= h($form['street']) ?>" placeholder="Street address"></div>
              <div class="form-field"><label for="city">City</label><input type="text" id="city" name="city" value="<?= h($form['city']) ?>" placeholder="City"></div>
              <div class="form-field">
                <label for="state">State</label>
                <select id="state" name="state">
                  <option value="">&mdash;</option>
                  <?php foreach ($states as $s): ?>
                    <option value="<?= $s ?>" <?= $form['state'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-field"><label for="zip">ZIP</label><input type="text" id="zip" name="zip" value="<?= h($form['zip']) ?>" placeholder="ZIP"></div>
            </div>
          </div>

          <div>
            <div class="group-title">Preferences &amp; tags</div>
            <div class="checks">
              <label><input type="checkbox" name="volunteer"      <?= $form['volunteer']      ? 'checked' : '' ?>> Also a volunteer</label>
              <label><input type="checkbox" name="email_updates"  <?= $form['email_updates']  ? 'checked' : '' ?>> Send email updates</label>
              <label><input type="checkbox" name="mail_receipts"  <?= $form['mail_receipts']  ? 'checked' : '' ?>> Mail tax receipts</label>
              <label><input type="checkbox" name="do_not_contact" <?= $form['do_not_contact'] ? 'checked' : '' ?>> Do not contact</label>
            </div>
          </div>

          <p class="req">* Required</p>
          <div class="spacer"></div>

          <div class="form-foot right">
            <div class="foot-btns">
              <a href="donorProfile.php?id=<?= $id ?>" class="btn btn-outline small">Cancel</a>
              <button type="submit" name="action" value="save" class="btn btn-yellow small">Save changes</button>
            </div>
          </div>
        </form>

        <aside class="side">
          <section class="card panel">
            <h3>Record info</h3>
            <dl class="facts">
              <dt>Donor ID</dt><dd><?= h($p['code']) ?></dd>
              <dt>Created</dt><dd><?= h(date('M j, Y', strtotime($p['created']))) ?></dd>
            </dl>
          </section>

          <section class="card panel danger">
            <h3>Deactivate donor</h3>
            <p>Hides them from lists but keeps their giving history for reports.</p>
            <form method="post" action="updateDonor.php?id=<?= $id ?>"
                  onsubmit="return confirm(<?= h(json_encode('Mark ' . $displayName . ' inactive? They will be hidden from the donor list, but their giving history is kept.')) ?>);">
              <input type="hidden" name="action" value="deactivate">
              <button type="submit" class="btn btn-danger pill-btn">Mark inactive</button>
            </form>
          </section>
        </aside>
      </div>

<?php fishnet_close(); ?>