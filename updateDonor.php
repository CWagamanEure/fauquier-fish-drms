<?php
// Edit, deactivate or reactivate a donor through database/dbDonors.php (UF2-71, 81, 82).
require_once 'include/fishnet-layout.php';
require_once 'include/permissions.php';
require_once 'database/dbDonors.php';

$id = (int) ($_GET['id'] ?? 0);
try {
  $stored = $id > 0 ? donor_profile_fields($id) : null;
} catch (mysqli_sql_exception $e) {
  error_log('updateDonor.php: ' . $e->getMessage());
  header('Location: donor.php?error=load', true, 303);   // the list page shows the generic notice
  exit;
}
if (!$stored || $id === DONOR_ANONYMOUS_ID) { header('Location: donor.php', true, 303); exit; }

$canEdit = fish_can('donors', 'edit');
$errors = [];
$form = $stored;
$displayName = $stored['type'] === 'Organization'
  ? $stored['org_name']
  : trim(implode(' ', array_filter([$stored['first'], $stored['middle'], $stored['last']])));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = fish_str($_POST, 'action') ?: 'save';
  $byUser = auth_current_user()->get_id();

    if (!$canEdit) {
      http_response_code(403);
      exit('Your account can view donors but not change them.');
    }
    $form = donor_form_from_post($_POST, $stored);
    $errors = donor_form_validate($form, $stored);
    if (!$errors) {
      if (donor_update($id, $form, $byUser)) {
        header('Location: donorProfile.php?id=' . $id . '&saved=1', true, 303);
        exit;
      }
      $errors[] = 'Could not save the donor. Please try again.';
    }
  }

$readOnly = !$canEdit;
$formAction = 'updateDonor.php?id=' . $id;
$buttons = '<a href="donorProfile.php?id=' . $id . '" class="btn btn-outline small">Cancel</a>'
         . '<button type="submit" name="action" value="save" class="btn btn-yellow small">Save changes</button>';

fishnet_open('Edit donor', 'donor', 'forms.css');
?>

      <section class="page-head">
        <div>
          <p class="crumbs"><a href="donor.php">Donor</a> &rsaquo; <a href="donorProfile.php?id=<?= $id ?>"><strong><?= h($displayName) ?></strong></a> &rsaquo; <strong>Edit</strong></p>
          <h1>Edit donor</h1>
        </div>
      </section>

      <div class="split">
        <?php require 'include/donorForm.php'; ?>

        <aside class="side">
          <section class="card panel">
            <h3>Record info</h3>
            <dl class="facts">
              <dt>Donor ID</dt><dd><?= h($stored['code']) ?></dd>
              <dt>Record created</dt><dd><?= h(date('M j, Y', strtotime($stored['created']))) ?></dd>
            </dl>
          </section>
        </aside>
      </div>

<?php fishnet_close(); ?>
