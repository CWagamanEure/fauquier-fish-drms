<?php
// Front-end: sample data is hard-coded below; replace it with database values.
require_once 'include/fishnet-layout.php';
fishnet_open('Add user', 'users', 'forms.css');
?>

      <section class="page-head">
        <div>
          <p class="crumbs"><a href="manageUsers.php">Manage Users</a> &rsaquo; <strong>Add user</strong></p>
          <h1>Add user</h1>
        </div>
      </section>

      <form method="post" class="single">
        <div class="card form-card">
          <div class="group">
            <p class="group-title">Account</p>
            <div class="grid g-2">
              <div class="field"><label for="fn">First name *</label><input id="fn" name="first_name" value="" required></div>
              <div class="field"><label for="ln">Last name *</label><input id="ln" name="last_name" value="" required></div>
              <div class="field"><label for="un">Username *</label><input id="un" name="username" value="" required></div>
              <div class="field"><label for="em">Work email *</label><input type="email" id="em" name="email" value="" required></div>
            </div>
          </div>

          <div class="group">
            <p class="group-title">Role</p>
            <div class="grid g-2">
              <div class="field"><label for="role">Role</label>
                <select id="role" name="role">
                  <option value="" selected>Choose a role...</option>
                  <option>Administrator</option>
                  <option>Donor Coordinator</option>
                  <option>Treasurer</option>
                  <option>Volunteer Coordinator</option>
                  <option>Read only</option>
                </select></div>
            </div>
          </div>

          <div class="group">
            <p class="group-title">Permissions</p>
            <div class="table-wrap"><table class="perms">
              <thead><tr><th>Area</th><th>View</th><th>Add / edit</th><th>Delete</th></tr></thead>
              <tbody>
                <tr><td>Donors &amp; profiles</td>
                  <td><input type="checkbox" name="perm[0][view]" aria-label="Donors &amp; profiles view"></td>
                  <td><input type="checkbox" name="perm[0][edit]" aria-label="Donors &amp; profiles edit"></td>
                  <td><input type="checkbox" name="perm[0][delete]" aria-label="Donors &amp; profiles delete"></td>
                </tr>
                <tr><td>Donations (cash &amp; non-cash)</td>
                  <td><input type="checkbox" name="perm[1][view]" aria-label="Donations (cash &amp; non-cash) view"></td>
                  <td><input type="checkbox" name="perm[1][edit]" aria-label="Donations (cash &amp; non-cash) edit"></td>
                  <td><input type="checkbox" name="perm[1][delete]" aria-label="Donations (cash &amp; non-cash) delete"></td>
                </tr>
                <tr><td>Campaigns</td>
                  <td><input type="checkbox" name="perm[2][view]" aria-label="Campaigns view"></td>
                  <td><input type="checkbox" name="perm[2][edit]" aria-label="Campaigns edit"></td>
                  <td><input type="checkbox" name="perm[2][delete]" aria-label="Campaigns delete"></td>
                </tr>
                <tr><td>Communications</td>
                  <td><input type="checkbox" name="perm[3][view]" aria-label="Communications view"></td>
                  <td><input type="checkbox" name="perm[3][edit]" aria-label="Communications edit"></td>
                  <td><input type="checkbox" name="perm[3][delete]" aria-label="Communications delete"></td>
                </tr>
                <tr><td>Reports</td>
                  <td><input type="checkbox" name="perm[4][view]" aria-label="Reports view"></td>
                  <td><input type="checkbox" name="perm[4][edit]" aria-label="Reports edit"></td>
                  <td><input type="checkbox" name="perm[4][delete]" aria-label="Reports delete"></td>
                </tr>
                <tr><td>Data imports &amp; users</td>
                  <td><input type="checkbox" name="perm[5][view]" aria-label="Data imports &amp; users view"></td>
                  <td><input type="checkbox" name="perm[5][edit]" aria-label="Data imports &amp; users edit"></td>
                  <td><input type="checkbox" name="perm[5][delete]" aria-label="Data imports &amp; users delete"></td>
                </tr>
              </tbody>
            </table></div>
          </div>

          <div class="spacer"></div>
          <footer class="form-foot">
            <p class="muted small-text">Changing the role resets permissions to that role&rsquo;s defaults.</p>
            <div class="foot-btns">
              <a href="manageUsers.php" class="btn btn-outline small">Cancel</a>
              <button type="submit" class="btn btn-yellow small wide">Create user</button>
            </div>
          </footer>
        </div>
      </form>

<?php fishnet_close(); ?>
