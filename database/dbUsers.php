<?php
// Staff accounts for Manage Users (UF2-83..86, 97): dbpersons rows plus their user_permissions matrix.
// Every query binds its parameters; area and action names only ever come from the constants below, so
// the fixed column names (can_view/can_edit/can_delete) are never built from request input.
// Writes return true, or a short word the page turns into a message ('duplicate', 'last_admin'), or
// false after logging a database fault. The password hash is never selected into page arrays.
require_once __DIR__ . '/dbinfo.php';
require_once __DIR__ . '/../include/permissions.php';   // FISH_PERMISSION_ACTIONS

const FISH_USER_AREAS = ['donors', 'donations', 'campaigns', 'communications', 'reports', 'data_import', 'users'];
const FISH_USER_AREA_LABELS = [
    'donors' => 'Donors & profiles', 'donations' => 'Donations (cash & non-cash)', 'campaigns' => 'Campaigns',
    'communications' => 'Communications', 'reports' => 'Reports', 'data_import' => 'Data imports', 'users' => 'Users',
];
const FISH_USER_ROLES = ['admin' => 'Administrator', 'manager' => 'Manager', 'staff' => 'Staff', 'viewer' => 'Read only'];
// Migration 001 / UF2-7: data imports are never deletable, whatever the admin ticks.
const FISH_USER_NEVER = ['data_import' => ['delete']];
const FISH_USER_COLUMNS = 'id, first_name, last_name, name, email, role, status, created_at, updated_at, UNIX_TIMESTAMP(created_at) AS created_ts';
const FISH_USER_RECOVERY_ADMIN = 'vmsroot';   // excluded from the last-admin count; nobody may know its password

function user_role_label($role) { return FISH_USER_ROLES[$role] ?? ucfirst((string) $role); }
function user_area_label($area) { return FISH_USER_AREA_LABELS[$area] ?? ucfirst(str_replace('_', ' ', (string) $area)); }

// An all-zero matrix: [area => ['view' => 0, 'edit' => 0, 'delete' => 0]].
function user_empty_matrix() {
    $m = [];
    foreach (FISH_USER_AREAS as $area) {
        $m[$area] = array_fill_keys(FISH_PERMISSION_ACTIONS, 0);
    }
    return $m;
}

// [role => matrix] from the role_permission_presets view, read once per request.
function role_presets() {
    static $presets = null;
    if ($presets !== null) { return $presets; }
    $presets = [];
    foreach (array_keys(FISH_USER_ROLES) as $role) { $presets[$role] = user_empty_matrix(); }
    $con = connect();
    $result = $con->query('SELECT role, area, can_view, can_edit, can_delete FROM role_permission_presets');
    while ($row = $result->fetch_assoc()) {
        if (isset($presets[$row['role']][$row['area']])) {
            $presets[$row['role']][$row['area']] = ['view' => (int) $row['can_view'], 'edit' => (int) $row['can_edit'], 'delete' => (int) $row['can_delete']];
        }
    }
    mysqli_close($con);
    return $presets;
}

// Apply the product rules to a matrix: never-granted cells are 0 and an admin's users row is always the
// admin preset, so an active admin can always reach Manage Users (the last-admin rule relies on it).
function user_matrix_normalise(array $matrix, $role) {
    $out = user_empty_matrix();
    foreach (FISH_USER_AREAS as $area) {
        foreach (FISH_PERMISSION_ACTIONS as $action) {
            $out[$area][$action] = !empty($matrix[$area][$action]) ? 1 : 0;
        }
    }
    foreach (FISH_USER_NEVER as $area => $actions) {
        foreach ($actions as $action) { $out[$area][$action] = 0; }
    }
    if ($role === 'admin') { $out['users'] = role_presets()['admin']['users']; }
    return $out;
}

// The matrix from a posted perm[<area>][<action>] checkbox set: only the fixed areas and actions are
// read, a missing box is 0 (unticking revokes), every other key is ignored.
function user_matrix_from_post(array $post, $role) {
    $perm = isset($post['perm']) && is_array($post['perm']) ? $post['perm'] : [];
    $m = user_empty_matrix();
    foreach (FISH_USER_AREAS as $area) {
        foreach (FISH_PERMISSION_ACTIONS as $action) {
            $m[$area][$action] = isset($perm[$area][$action]) ? 1 : 0;
        }
    }
    return user_matrix_normalise($m, $role);
}

// Effective matrix for one account, the way fish_can() decides: the user's row wins, the role preset
// fills a missing row, nothing at all means no access.
function user_permission_matrix($username, $role) {
    $m = role_presets()[$role] ?? user_empty_matrix();
    $con = connect();
    $stmt = $con->prepare('SELECT area, can_view, can_edit, can_delete FROM user_permissions WHERE user_id = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        if (isset($m[$row['area']])) {
            $m[$row['area']] = ['view' => (int) $row['can_view'], 'edit' => (int) $row['can_edit'], 'delete' => (int) $row['can_delete']];
        }
    }
    $stmt->close();
    mysqli_close($con);
    return $m;
}

// Every account with its effective matrix, active first, then by last name (legacy rows: by name).
function users_list() {
    $con = connect();
    $rows = $con->query('SELECT ' . FISH_USER_COLUMNS . ' FROM dbpersons
                         ORDER BY (status = \'active\') DESC, COALESCE(last_name, name), first_name, id')->fetch_all(MYSQLI_ASSOC);
    $perms = [];
    foreach ($con->query('SELECT user_id, area, can_view, can_edit, can_delete FROM user_permissions')->fetch_all(MYSQLI_ASSOC) as $p) {
        $perms[strtolower($p['user_id'])][$p['area']] = ['view' => (int) $p['can_view'], 'edit' => (int) $p['can_edit'], 'delete' => (int) $p['can_delete']];
    }
    mysqli_close($con);
    $presets = role_presets();
    foreach ($rows as &$row) {
        $m = $presets[$row['role']] ?? user_empty_matrix();
        foreach ($perms[strtolower($row['id'])] ?? [] as $area => $cell) {
            if (isset($m[$area])) { $m[$area] = $cell; }
        }
        $row['matrix'] = $m;
    }
    unset($row);
    return $rows;
}

// One account (without its hash) plus its effective matrix, or null when the username is unknown.
function user_get($username) {
    $con = connect();
    $stmt = $con->prepare('SELECT ' . FISH_USER_COLUMNS . ' FROM dbpersons WHERE id = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    mysqli_close($con);
    if (!$row) { return null; }
    $row['matrix'] = user_permission_matrix($row['id'], $row['role']);
    return $row;
}

function users_username_taken($username) {
    $con = connect();
    $stmt = $con->prepare('SELECT 1 FROM dbpersons WHERE id = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $taken = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    mysqli_close($con);
    return $taken;
}

// Case-insensitive (the column's collation); $exceptId skips the account being edited.
function users_email_taken($email, $exceptId = null) {
    $con = connect();
    $stmt = $con->prepare('SELECT 1 FROM dbpersons WHERE email = ? AND NOT (id <=> ?)');
    $stmt->bind_param('ss', $email, $exceptId);
    $stmt->execute();
    $taken = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    mysqli_close($con);
    return $taken;
}

// Write the seven permission rows for one account inside the caller's transaction.
function user_write_matrix(mysqli $con, $username, array $matrix) {
    $stmt = $con->prepare('INSERT INTO user_permissions (user_id, area, can_view, can_edit, can_delete) VALUES (?, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_edit = VALUES(can_edit), can_delete = VALUES(can_delete)');
    foreach (FISH_USER_AREAS as $area) {
        $cell = $matrix[$area];
        $stmt->bind_param('ssiii', $username, $area, $cell['view'], $cell['edit'], $cell['delete']);
        $stmt->execute();
    }
    $stmt->close();
}

// Active admins other than the recovery account, with their rows locked for the rest of the
// transaction so two concurrent demotions cannot both pass the last-admin check.
function users_lock_active_admins(mysqli $con) {
    $stmt = $con->prepare('SELECT id FROM dbpersons WHERE role = \'admin\' AND status = \'active\' AND id <> ? FOR UPDATE');
    $recovery = FISH_USER_RECOVERY_ADMIN;
    $stmt->bind_param('s', $recovery);
    $stmt->execute();
    $ids = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
    $stmt->close();
    return array_map('strtolower', $ids);
}

function users_log_fault($where, Throwable $e) {
    error_log($where . ' failed: ' . $e->getMessage());   // never includes posted values
}

// Create the account and its seven permission rows in one transaction. $form: username, first, last,
// email, role; $matrix: already normalised. The trigger derives accessLevel from role.
function user_create(array $form, $hash, array $matrix) {
    try {
        $con = connect();
        $con->begin_transaction();
        $name = trim($form['first'] . ' ' . $form['last']);
        $stmt = $con->prepare('INSERT INTO dbpersons (id, name, password, first_name, last_name, email, role, status)
                               VALUES (?, ?, ?, ?, ?, ?, ?, \'active\')');
        $stmt->bind_param('sssssss', $form['username'], $name, $hash, $form['first'], $form['last'], $form['email'], $form['role']);
        $stmt->execute();
        $stmt->close();
        user_write_matrix($con, $form['username'], $matrix);
        $con->commit();
        mysqli_close($con);
        return true;
    } catch (mysqli_sql_exception $e) {
        if (isset($con)) { $con->rollback(); mysqli_close($con); }
        if ((int) $e->getCode() === 1062) { return 'duplicate'; }
        users_log_fault('user_create', $e);
        return false;
    }
}

// Save names, email and role. A role change resets the rows to the new role's presets (the posted
// matrix is ignored); otherwise $matrix is written. Demoting the last active admin is refused.
function user_update(array $stored, array $form, array $matrix) {
    $username = $stored['id'];
    $roleChanged = $form['role'] !== $stored['role'];
    try {
        $con = connect();
        $con->begin_transaction();
        if ($roleChanged && $stored['role'] === 'admin') {
            $others = array_diff(users_lock_active_admins($con), [strtolower($username)]);
            if ($stored['status'] === 'active' && count($others) === 0) {
                $con->rollback();
                mysqli_close($con);
                return 'last_admin';
            }
        }
        $name = trim($form['first'] . ' ' . $form['last']);
        $stmt = $con->prepare('UPDATE dbpersons SET first_name = ?, last_name = ?, name = ?, email = ?, role = ? WHERE id = ?');
        $stmt->bind_param('ssssss', $form['first'], $form['last'], $name, $form['email'], $form['role'], $username);
        $stmt->execute();
        $stmt->close();
        if ($roleChanged) {
            $stmt = $con->prepare('DELETE FROM user_permissions WHERE user_id = ?');
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $stmt->close();
            $matrix = user_matrix_normalise(role_presets()[$form['role']], $form['role']);
        }
        user_write_matrix($con, $username, $matrix);
        $con->commit();
        mysqli_close($con);
        return true;
    } catch (mysqli_sql_exception $e) {
        if (isset($con)) { $con->rollback(); mysqli_close($con); }
        if ((int) $e->getCode() === 1062) { return 'duplicate'; }
        users_log_fault('user_update', $e);
        return false;
    }
}

// Deactivate (status inactive; the trigger zeroes accessLevel so login refuses and the open session ends
// on its next request) or reactivate. Nothing is deleted. The last active admin cannot be deactivated.
function user_set_status(array $stored, $active) {
    $username = $stored['id'];
    try {
        $con = connect();
        $con->begin_transaction();
        if (!$active && $stored['role'] === 'admin') {
            $others = array_diff(users_lock_active_admins($con), [strtolower($username)]);
            if (count($others) === 0) {
                $con->rollback();
                mysqli_close($con);
                return 'last_admin';
            }
        }
        $status = $active ? 'active' : 'inactive';
        $stmt = $con->prepare('UPDATE dbpersons SET status = ? WHERE id = ?');
        $stmt->bind_param('ss', $status, $username);
        $stmt->execute();
        $stmt->close();
        $con->commit();
        mysqli_close($con);
        return true;
    } catch (mysqli_sql_exception $e) {
        if (isset($con)) { $con->rollback(); mysqli_close($con); }
        users_log_fault('user_set_status', $e);
        return false;
    }
}
