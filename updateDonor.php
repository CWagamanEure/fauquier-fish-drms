<?php
// Edit, deactivate or reactivate a donor through database/dbDonors.php (UF2-71, 81, 82).
require_once 'include/fishnet-layout.php';
require_once 'include/permissions.php';
require_once 'database/dbDonors.php';

$id = (int) ($_GET['id'] ?? 0);
$stored = $id > 0 ? donor_profile_fields($id) : null;
if (!$stored || $id === DONOR_ANONYMOUS_ID) { header('Location: donor.php', true, 303); exit; }

$canEdit = fish_can('donors', 'edit');
$canDelete = fish_can('donors', 'delete');
$errors = [];
$form = $stored;
$displayName = $stored['type'] === 'Organization'
  ? $stored['org_name']
  : trim(implode(' ', array_filter([$stored['first'], $stored['middle'], $stored['last']])));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = fish_str($_POST, 'action') ?: 'save';
  $byUser = auth_current_user()->get_id();

  if ($action === 'deactivate' || $action === 'reactivate') {
    if (!$canDelete) {
      http_response_code(403);
      exit('Your account cannot deactivate or reactivate donors.');
    }
    if (donor_set_active($id, $action === 'reactivate', $byUser)) {
      header('Location: ' . ($action === 'reactivate' ? 'donorProfile.php?id=' . $id . '&saved=1' : 'donor.php'), true, 303);
      exit;
    }
    $errors[] = 'Could not update the donor. Please try again.';
  } else {
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
          <h1>Edit donor<?php if (!$stored['is_active']): ?> <span class="status off">Inactive</span><?php endif; ?></h1>
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

          <?php if ($canDelete): ?>
          <?php if ($stored['is_active']): ?>
          <section class="card panel danger">
            <h3>Deactivate donor</h3>
            <p>Hides them from lists but keeps their giving history for reports.</p>
            <form method="post" action="updateDonor.php?id=<?= $id ?>"
                  onsubmit="return confirm(<?= h(json_encode('Mark ' . $displayName . ' inactive? They will be hidden from the donor list, but their giving history is kept.')) ?>);">
              <?php echo auth_csrf_field(); ?>
              <input type="hidden" name="action" value="deactivate">
              <button type="submit" class="btn btn-danger pill-btn">Mark inactive</button>
            </form>
          </section>
          <?php else: ?>
          <section class="card panel">
            <h3>Reactivate donor</h3>
            <p>Puts them back in the donor list.</p>
            <form method="post" action="updateDonor.php?id=<?= $id ?>">
              <?php echo auth_csrf_field(); ?>
              <input type="hidden" name="action" value="reactivate">
              <button type="submit" class="btn btn-yellow pill-btn">Reactivate</button>
            </form>
          </section>
          <?php endif; ?>
          <?php endif; ?>
        </aside>
      </div>

<?php fishnet_close(); ?>
