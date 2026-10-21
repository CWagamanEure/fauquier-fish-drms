<?php
// Add a donor: writes a donors row through database/dbDonors.php (UF2-58, 59, 60).
require_once 'include/fishnet-layout.php';
require_once 'include/permissions.php';
require_once 'database/dbDonors.php';

$canEdit = fish_can('donors', 'edit');
$canAddGift = fish_can_record_donations();   // "Save & add donation" only leads somewhere useful with this
$errors = [];
$form = [
  'type'=>'Individual', 'first'=>'', 'middle'=>'', 'last'=>'', 'org_name'=>'',
  'email'=>'', 'phone'=>'', 'preferred'=>'',
  'street'=>'', 'city'=>'', 'state'=>'VA', 'zip'=>'',
  'volunteer'=>0, 'email_updates'=>1, 'mail_receipts'=>0, 'do_not_contact'=>0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$canEdit) {                 // the form token was already checked by require_authentication()
    http_response_code(403);
    exit('Your account can view donors but not add them.');
  }
  $form = donor_form_from_post($_POST, $form);
  $errors = donor_form_validate($form);
  if (!$errors) {
    $newId = donor_create($form, auth_current_user()->get_id());
    if ($newId === false) {
      $errors[] = 'Could not save the donor. Please try again.';
    } elseif (fish_str($_POST, 'action') === 'save_add' && $canAddGift) {
      // "Save & add donation": open the donation form with the new donor already chosen (UF2-49).
      header('Location: addDonation.php?donor=' . $newId, true, 303);
      exit;
    } else {
      header('Location: donorProfile.php?id=' . $newId . '&saved=1', true, 303);
      exit;
    }
  }
}

$readOnly = !$canEdit;
$formAction = 'addDonor.php';
$buttons = '<a href="donor.php" class="btn btn-outline small">Cancel</a>'
         . ($canAddGift ? '<button type="submit" name="action" value="save_add" class="btn btn-outline small">Save &amp; add donation</button>' : '')
         . '<button type="submit" name="action" value="save" class="btn btn-yellow small">Save donor</button>';

fishnet_open('Add donor', 'donor', 'forms.css');
?>

      <section class="page-head">
        <div>
          <p class="crumbs"><a href="donor.php">Donor</a> &rsaquo; Add donor</p>
          <h1>Add donor</h1>
        </div>
      </section>

      <div class="split">
        <?php require 'include/donorForm.php'; ?>

        <aside class="side">
          <section class="card panel">
            <h3>Tips</h3>
            <p>Only first and last name are required.</p>
            <p>Pick Organization for churches, schools and businesses.</p>
            <?php if ($canAddGift): ?><p>Use &ldquo;Save &amp; add donation&rdquo; to record a first gift right away.</p><?php endif; ?>
          </section>
        </aside>
      </div>

<?php fishnet_close(); ?>
