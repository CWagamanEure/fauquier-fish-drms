<?php
// Add a staff account with its role, initial password and permissions (UF2-86).
require_once 'include/fishnet-layout.php';
require_once 'include/users-common.php';

users_require_view();
$canEdit = fish_can('users', 'edit');
$errors = [];
$form = ['first' => '', 'last' => '', 'username' => '', 'email' => '', 'role' => 'staff'];
$matrix = user_matrix_normalise(role_presets()['staff'], 'staff');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$canEdit) {                 // the form token was already checked by require_authentication()
    http_response_code(403);
    exit('Your account can view users but not add them.');
  }
  $form = user_form_from_post($_POST, $form);
  $errors = user_form_validate($form);
  $password = $_POST['new_password'] ?? '';
  $errors = array_merge($errors, user_password_validate($password, $_POST['confirm_password'] ?? ''));
  $role = isset(FISH_USER_ROLES[$form['role']]) ? $form['role'] : 'staff';
  // The boxes were drawn for preset_role; a different role (no JavaScript) takes that role's defaults.
  $matrix = fish_str($_POST, 'preset_role') === $role
    ? user_matrix_from_post($_POST, $role)
    : user_matrix_normalise(role_presets()[$role], $role);
  if (!$errors) {
    $result = user_create($form, password_hash($password, PASSWORD_BCRYPT), $matrix);
    if ($result === true) {
      header('Location: manageUsers.php?user=' . rawurlencode($form['username']) . '&did=created', true, 303);
      exit;
    }
    $errors[] = $result === 'duplicate' ? 'That username or email is already in use.' : 'Could not save the user. Please try again.';
  }
  unset($password);
}

$readOnly = !$canEdit;
$isNew = true;
$lockRole = false;
$notice = $readOnly ? 'Your account can view users but not add them.' : '';

fishnet_open('Add user', 'users', 'forms.css');
?>

      <section class="page-head">
        <div>
          <p class="crumbs"><a href="manageUsers.php">Manage Users</a> &rsaquo; <strong>Add user</strong></p>
          <h1>Add user</h1>
        </div>
      </section>

      <form method="post" action="addUser.php" class="single" novalidate>
        <?= auth_csrf_field() ?>
        <div class="card form-card">
          <?php require 'include/userForm.php'; ?>
          <div class="spacer"></div>
          <footer class="form-foot">
            <p class="muted small-text">The new user can sign in as soon as the account is saved.</p>
            <div class="foot-btns">
              <a href="manageUsers.php" class="btn btn-outline small">Cancel</a>
              <?php if (!$readOnly): ?><button type="submit" class="btn btn-yellow small wide">Create user</button><?php endif; ?>
            </div>
          </footer>
        </div>
      </form>

<script src="js/userForm.js"></script>

<?php fishnet_close(); ?>
