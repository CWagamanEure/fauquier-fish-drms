<?php
// An administrator sets another account's password (UF2-84). The values are never echoed or logged.
require_once 'include/fishnet-layout.php';
require_once 'include/users-common.php';

users_require_view();
$target = users_target_or_404();
$canEdit = users_may_manage($target);
$errors = [];
$link = rawurlencode($target['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$canEdit) {                 // the form token was already checked by require_authentication()
    http_response_code(403);
    exit('Your account cannot change this user\'s password.');
  }
  $password = $_POST['new_password'] ?? '';
  $errors = user_password_validate($password, $_POST['confirm_password'] ?? '');
  if (!$errors) {
    try {
      $ok = change_password($target['id'], password_hash($password, PASSWORD_BCRYPT));
    } catch (mysqli_sql_exception $e) {
      users_log_fault('userPassword.php', $e);
      $ok = false;
    }
    if ($ok) {
      header('Location: manageUsers.php?user=' . $link . '&did=password', true, 303);
      exit;
    }
    $errors[] = 'Could not update the password. Please try again.';
  }
  unset($password);
}

$name = users_full_name($target);
$readOnly = !$canEdit;

fishnet_open('Change password', 'users', 'forms.css');
?>

      <section class="page-head">
        <div>
          <p class="crumbs"><a href="manageUsers.php">Manage Users</a> &rsaquo; <a href="editUser.php?id=<?= $link ?>"><?= h($name) ?></a> &rsaquo; <strong>Change password</strong></p>
          <h1>Change password</h1>
        </div>
      </section>

      <form class="split" method="post" action="userPassword.php?id=<?= $link ?>">
        <?= auth_csrf_field() ?>
        <div class="card form-card narrow">
          <div class="user-chip">
            <span class="initials yellow"><?= h(initials($name)) ?></span>
            <div><strong><?= h($name) ?></strong><p class="muted"><?= h($target['id']) ?> &middot; <?= h(user_role_label($target['role'])) ?></p></div>
          </div>

          <?php if ($readOnly): ?>
          <div class="notice" role="status">Your account cannot change this user&rsquo;s password.</div>
          <?php else: ?>
          <?php if ($errors): ?>
          <div class="form-errors" role="alert">
            <strong>Please fix the following:</strong>
            <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
          </div>
          <?php endif; ?>
          <div class="field">
            <label for="pw1">New password *</label>
            <div class="pw-wrap">
              <input type="password" id="pw1" name="new_password" autocomplete="new-password" minlength="<?= FISH_USER_PASSWORD_MIN ?>" required>
              <button type="button" class="link show-pw" data-for="pw1">Show</button>
            </div>
          </div>
          <div class="field">
            <label for="pw2">Confirm new password *</label>
            <input type="password" id="pw2" name="confirm_password" autocomplete="new-password" required>
          </div>
          <p class="match" id="pwMatch">&nbsp;</p>
          <?php endif; ?>

          <div class="spacer"></div>
          <footer class="form-foot right">
            <a href="editUser.php?id=<?= $link ?>" class="btn btn-outline small">Cancel</a>
            <?php if (!$readOnly): ?><button type="submit" class="btn btn-yellow small">Update password</button><?php endif; ?>
          </footer>
        </div>

        <aside class="side">
          <div class="card panel">
            <h3>Who can do this</h3>
            <p>Administrators can reset anyone&rsquo;s password. Other staff can only change their own from their profile menu.</p>
            <p>At least <?= FISH_USER_PASSWORD_MIN ?> characters. Share the new password with the user directly.</p>
          </div>
        </aside>
      </form>

<?php if (!$readOnly): ?><script src="js/userPassword.js"></script><?php endif; ?>

<?php fishnet_close(); ?>
