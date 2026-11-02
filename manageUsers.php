<?php
// Manage Users: every staff account from dbpersons with its effective permissions (UF2-83, UF2-97).
// Markup follows the front-end mock-up; only the data source changed.
require_once 'include/fishnet-layout.php';
require_once 'include/users-common.php';

users_require_view();
$canEdit = fish_can('users', 'edit');
$loadError = false;
$users = [];
$notice = '';
try {
  $users = users_list();
  $notice = users_saved_notice();
} catch (mysqli_sql_exception $e) {
  error_log('manageUsers.php: ' . $e->getMessage());
  $loadError = true;
}

// Chips: the areas the account can add/edit, then view-only areas, or "None".
function permission_chips(array $matrix) {
  $edit = $view = [];
  foreach ($matrix as $area => $cell) {
    if ($cell['edit']) { $edit[] = user_area_label($area); }
    elseif ($cell['view']) { $view[] = user_area_label($area); }
  }
  if (!$edit && !$view) { return '<span class="chip">None</span>'; }
  $out = '';
  foreach ($edit as $label) { $out .= '<span class="chip">' . h($label) . '</span>'; }
  foreach ($view as $label) { $out .= '<span class="chip">' . h($label) . ' (view)</span>'; }
  return $out;
}
$colours = ['teal', 'navy', 'sage', 'yellow', 'grey'];

fishnet_open('Manage Users', 'users', 'donor.css');
?>

      <section class="page-head">
        <h1>Manage Users</h1>
        <?php if ($canEdit): ?><a href="addUser.php" class="btn btn-yellow">+ Add user</a><?php endif; ?>
      </section>

      <?php if ($notice !== ''): ?><div class="notice" role="status"><?= h($notice) ?></div><?php endif; ?>
      <?php if ($loadError): ?><div class="notice" role="alert">Could not load users. Please try again shortly.</div><?php endif; ?>

      <section class="card table-card users-card">
        <div class="table-wrap">
          <table>
            <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Permissions</th><th>Last login</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($users as $i => $u):
                $name = users_full_name($u);
                $link = rawurlencode($u['id']); ?>
              <tr>
                <td><span class="initials <?= $colours[$i % count($colours)] ?>"><?= h(initials($name)) ?></span><strong><?= h($name) ?></strong></td>
                <td><?= h($u['id']) ?></td>
                <td><?= h(user_role_label($u['role'])) ?></td>
                <td><?= permission_chips($u['matrix']) ?></td>
                <td>&mdash;</td><!-- no sign-in times are recorded yet -->
                <td><span class="status <?= $u['status'] === 'active' ? 'active' : 'off' ?>"><?= $u['status'] === 'active' ? 'Active' : 'Inactive' ?></span></td>
                <td class="actions"><a href="editUser.php?id=<?= $link ?>" class="edit">Edit</a><a href="userPassword.php?id=<?= $link ?>" class="pw-link">Change password</a></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="pager">
          <p>Showing <?= count($users) ? '1&ndash;' . count($users) : '0' ?> of <?= count($users) ?> users</p>
          <div class="pages"><a href="#" class="pg wide">&lsaquo; Previous</a><a href="#" class="pg on">1</a><a href="#" class="pg wide">Next &rsaquo;</a></div>
        </div>
      </section>

<?php fishnet_close(); ?>
