<?php
// Front-end: sample data is hard-coded below; replace it with database values.
require_once 'include/fishnet-layout.php';
fishnet_open('Manage Users', 'users', 'donor.css');
?>

      <section class="page-head">
        <h1>Manage Users</h1>
        <a href="addUser.php" class="btn btn-yellow">+ Add user</a>
      </section>

      <section class="card table-card users-card">
        <div class="table-wrap">
          <table>
            <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Permissions</th><th>Last login</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr>
                <td><span class="initials teal">TC</span><strong>Tristan Chavez-Duran</strong></td>
                <td>tchavezduran</td>
                <td>Administrator</td>
                <td><span class="chip">Admin</span><span class="chip">Donor Coord.</span></td>
                <td>Today 3:52 PM</td>
                <td><span class="status active">Active</span></td>
                <td class="actions"><a href="editUser.php" class="edit">Edit</a><a href="userPassword.php" class="pw-link">Change password</a></td>
              </tr>
              <tr>
                <td><span class="initials navy">CW</span><strong>Cory Wagaman-Eure</strong></td>
                <td>cwagaman</td>
                <td>Administrator</td>
                <td><span class="chip">Admin</span><span class="chip">Data imports</span></td>
                <td>Today 11:08 AM</td>
                <td><span class="status active">Active</span></td>
                <td class="actions"><a href="editUser.php" class="edit">Edit</a><a href="userPassword.php" class="pw-link">Change password</a></td>
              </tr>
              <tr>
                <td><span class="initials sage">JA</span><strong>James Andrews</strong></td>
                <td>jandrews</td>
                <td>Volunteer Coordinator</td>
                <td><span class="chip">Volunteers</span><span class="chip">Campaigns</span></td>
                <td>Oct 2, 2026</td>
                <td><span class="status active">Active</span></td>
                <td class="actions"><a href="editUser.php" class="edit">Edit</a><a href="userPassword.php" class="pw-link">Change password</a></td>
              </tr>
              <tr>
                <td><span class="initials yellow">CC</span><strong>Charity Coleman</strong></td>
                <td>ccoleman</td>
                <td>Donor Coordinator</td>
                <td><span class="chip">Donors</span><span class="chip">Donations</span></td>
                <td>Oct 3, 2026</td>
                <td><span class="status active">Active</span></td>
                <td class="actions"><a href="editUser.php" class="edit">Edit</a><a href="userPassword.php" class="pw-link">Change password</a></td>
              </tr>
              <tr>
                <td><span class="initials teal">DP</span><strong>Dana Price</strong></td>
                <td>dprice</td>
                <td>Treasurer</td>
                <td><span class="chip">Reports</span><span class="chip">Donations (view)</span></td>
                <td>Sep 30, 2026</td>
                <td><span class="status active">Active</span></td>
                <td class="actions"><a href="editUser.php" class="edit">Edit</a><a href="userPassword.php" class="pw-link">Change password</a></td>
              </tr>
              <tr>
                <td><span class="initials grey">ME</span><strong>Mark Ellis</strong></td>
                <td>mellis</td>
                <td>Volunteer Coordinator</td>
                <td><span class="chip">Volunteers</span></td>
                <td>Jun 14, 2026</td>
                <td><span class="status off">Inactive</span></td>
                <td class="actions"><a href="editUser.php" class="edit">Edit</a><a href="userPassword.php" class="pw-link">Change password</a></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="pager">
          <p>Showing 1&ndash;6 of 6 users</p>
          <div class="pages"><a href="#" class="pg wide">&lsaquo; Previous</a><a href="#" class="pg on">1</a><a href="#" class="pg wide">Next &rsaquo;</a></div>
        </div>
      </section>

<?php fishnet_close(); ?>
