<?php
// Edit a staff account: names, email, role, per-area permissions; deactivate or reactivate (UF2-85, UF2-97).
require_once 'include/fishnet-layout.php';
require_once 'include/users-common.php';

users_require_view();
$stored = users_target_or_404();
$self = users_is_self($stored);
$canEdit = users_may_manage($stored);       // users edit, and admin accounts only for admins
$errors = [];
$form = ['first' => (string) $stored['first_name'], 'last' => (string) $stored['last_name'],
         'username' => $stored['id'], 'email' => (string) $stored['email'], 'role' => $stored['role']];
$matrix = $stored['matrix'];
$link = rawurlencode($stored['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = fish_str($_POST, 'action') ?: 'save';
  if (!$canEdit) {                 // the form token was already checked by require_authentication()
    http_response_code(403);
    exit('Your account can view this user but not change them.');
  }
  if ($action === 'deactivate' || $action === 'reactivate') {
    // Handled before any field validation so legacy rows with no email can still be switched.
    if ($self && $action === 'deactivate') {
      http_response_code(403);
      exit('You cannot deactivate your own account.');
    }
    $result = user_set_status($stored, $action === 'reactivate');
    if ($result === true) {
      header('Location: manageUsers.php?user=' . $link . '&did=' . $action . 'd', true, 303);
      exit;
    }
    if ($result === 'last_admin') {
      http_response_code(403);
      exit('This is the last active administrator and cannot be deactivated.');
    }
    $errors[] = 'Could not update the user. Please try again.';
  } else {
    $form = user_form_from_post($_POST, $form);
    $form['username'] = $stored['id'];                   // never posted: the field is read-only
    if ($self) { $form['role'] = $stored['role']; }      // own role and users row come from the record
    $errors = user_form_validate($form, $stored);
    // What Save writes, computed before validation so an error re-renders it. A changed role resets to
    // its presets; so do boxes drawn for another role (preset_role, as addUser.php does: after an error
    // page the select may have been put back without the boxes following). Otherwise the posted boxes.
    $reset = isset(FISH_USER_ROLES[$form['role']])
      && ($form['role'] !== $stored['role'] || fish_str($_POST, 'preset_role') !== $form['role']);
    $matrix = $reset ? user_matrix_normalise(role_presets()[$form['role']], $form['role'])
                     : user_matrix_from_post($_POST, $form['role']);
    if ($self) { $matrix['users'] = $stored['matrix']['users']; }
    if (!$errors) {
      $result = user_update($stored, $form, $matrix);
      if ($result === true) {
        header('Location: manageUsers.php?user=' . $link . '&did=updated', true, 303);
        exit;
      }
      if ($result === 'last_admin') {
        http_response_code(403);
        exit('This is the last active administrator and cannot be demoted.');
      }
      $errors[] = $result === 'duplicate' ? 'That email is already used by another account.' : 'Could not save the user. Please try again.';
    }
  }
}

$readOnly = !$canEdit;
$isNew = false;
$lockRole = $self;
$storedRole = $stored['role'];
$name = users_full_name($stored);
$active = $stored['status'] === 'active';
$notice = $readOnly
  ? ($stored['role'] === 'admin' && !users_current_is_admin() ? 'Only an administrator can change an administrator account.' : 'Your account can view users but not change them.')
  : '';
// created_ts is UNIX_TIMESTAMP(created_at), so the date does not depend on the database session zone.
function fmt_when($ts) {
  return $ts ? h(date('M j, Y', (int) $ts)) : '&mdash;';
}

fishnet_open('Edit user', 'users', 'forms.css');
?>

      <section class="page-head">
        <div>
          <p class="crumbs"><a href="manageUsers.php">Manage Users</a> &rsaquo; <strong><?= h($name) ?></strong> &rsaquo; <strong>Edit</strong></p>
          <h1>Edit user</h1>
        </div>
      </section>

      <form class="split" method="post" action="editUser.php?id=<?= $link ?>">
        <?= auth_csrf_field() ?>
        <div class="card form-card">
          <?php if (!$active): ?><div class="notice" role="status">This account is inactive: the user cannot sign in. Their records are kept.</div><?php endif; ?>
          <?php require 'include/userForm.php'; ?>
          <div class="spacer"></div>
          <footer class="form-foot">
            <p class="muted small-text">Changing the role resets permissions to that role&rsquo;s defaults.</p>
            <div class="foot-btns">
              <a href="manageUsers.php" class="btn btn-outline small">Cancel</a>
              <?php if (!$readOnly): ?><button type="submit" name="action" value="save" class="btn btn-yellow small wide">Save changes</button><?php endif; ?>
            </div>
          </footer>
        </div>

        <aside class="side">
          <div class="card panel">
            <h3>Account info</h3>
            <dl class="facts">
              <dt>Created</dt><dd><?= fmt_when($stored['created_ts']) ?></dd>
              <dt>Last login</dt><dd>&mdash;</dd>
              <dt>Password changed</dt><dd>&mdash;</dd>
              <dt>Created by</dt><dd>&mdash;</dd>
            </dl>
          </div>
          <?php if (!$readOnly): ?>
          <div class="card panel">
            <h3>Password</h3>
            <a href="userPassword.php?id=<?= $link ?>" class="btn btn-outline pill-btn">Change password &rarr;</a>
          </div>
          <?php if ($self): ?>
          <div class="card panel">
            <h3>Deactivate user</h3>
            <p>You cannot deactivate your own account.</p>
          </div>
          <?php elseif ($active): ?>
          <div class="card panel danger">
            <h3>Deactivate user</h3>
            <p>Blocks sign-in but keeps everything they entered.</p>
            <button type="submit" name="action" value="deactivate" class="btn btn-danger pill-btn" formnovalidate>Deactivate</button>
          </div>
          <?php else: ?>
          <div class="card panel">
            <h3>Reactivate user</h3>
            <p>Lets the user sign in again with their existing password.</p>
            <button type="submit" name="action" value="reactivate" class="btn btn-outline pill-btn" formnovalidate>Reactivate</button>
          </div>
          <?php endif; ?>
          <?php endif; ?>
        </aside>
      </form>

<script src="js/userForm.js"></script>

<?php fishnet_close(); ?>
