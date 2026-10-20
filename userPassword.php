<?php
// Front-end: sample data is hard-coded below; replace it with database values.
require_once 'include/fishnet-layout.php';
fishnet_open('Change password', 'users', 'forms.css');
?>

      <section class="page-head">
        <div>
          <p class="crumbs"><a href="manageUsers.php">Manage Users</a> &rsaquo; <a href="editUser.php">Charity Coleman</a> &rsaquo; <strong>Change password</strong></p>
          <h1>Change password</h1>
        </div>
      </section>

      <form class="split" method="post">
        <?= auth_csrf_field() ?>
        <div class="card form-card narrow">
          <div class="user-chip">
            <span class="initials yellow">CC</span>
            <div><strong>Charity Coleman</strong><p class="muted">ccoleman &middot; Donor Coordinator</p></div>
          </div>

          <div class="field">
            <label for="pw1">New password *</label>
            <div class="pw-wrap">
              <input type="password" id="pw1" name="new_password" autocomplete="new-password" required>
              <button type="button" class="link show-pw" data-for="pw1">Show</button>
            </div>
          </div>
          <div class="field">
            <label for="pw2">Confirm new password *</label>
            <input type="password" id="pw2" name="confirm_password" autocomplete="new-password" required>
          </div>
          <p class="match" id="pwMatch">&nbsp;</p>

          <div class="spacer"></div>
          <footer class="form-foot right">
            <a href="editUser.php" class="btn btn-outline small">Cancel</a>
            <button type="submit" class="btn btn-yellow small">Update password</button>
          </footer>
        </div>

        <aside class="side">
          <div class="card panel">
            <h3>Who can do this</h3>
            <p>Administrators can reset anyone&rsquo;s password. Other staff can only change their own from their profile menu.</p>
            <p>Every reset is logged with the date and who did it.</p>
          </div>
        </aside>
      </form>

<script src="js/userPassword.js"></script>

<?php fishnet_close(); ?>
