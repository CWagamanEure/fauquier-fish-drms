<?php
// Per-area permissions (migration 001): fish_can('donors', 'edit') answers whether the signed-in account
// may do $action in $area. The user's own user_permissions row wins; when the account has none for that
// area (accounts created before migration 008's backfill, or by code that forgot to seed rows), the
// role_permission_presets row for the account's role applies. Nothing bypasses it, admin included.
// Fails closed: no signed-in user, an action outside the list, or no matching row all mean false.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../database/dbinfo.php';

const FISH_PERMISSION_ACTIONS = ['view', 'edit', 'delete'];

function fish_can($area, $action) {
    static $cache = [];
    $user = auth_current_user();
    if (!$user || !is_string($area) || !in_array($action, FISH_PERMISSION_ACTIONS, true)) {
        return false;
    }
    $userId = $user->get_id();
    $key = $userId . ':' . $area . ':' . $action;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $column = 'can_' . $action;   // $action is one of three constants, checked above
    $role = (string) $user->get_role();
    try {
        $con = connect();
        $stmt = $con->prepare("SELECT COALESCE(u.$column, r.$column)
                               FROM role_permission_presets r
                               LEFT JOIN user_permissions u ON u.user_id = ? AND u.area = r.area
                               WHERE r.role = ? AND r.area = ?");
        $stmt->bind_param('sss', $userId, $role, $area);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        mysqli_close($con);
    } catch (mysqli_sql_exception $e) {
        error_log('fish_can failed: ' . $e->getMessage());
        return $cache[$key] = false;   // a database fault denies, it never grants
    }
    return $cache[$key] = ($row !== null && (int) $row[0] === 1);
}

// Recording or editing a gift (addDonation.php and every link to it): donations view and edit, plus donors
// view to pick the donor. Edit without view must not reach the form, which shows the stored gift.
function fish_can_record_donations() {
    return fish_can('donations', 'view') && fish_can('donations', 'edit') && fish_can('donors', 'view');
}

// Logging or editing volunteer hours (addHours.php, hours.php and the profile's "+ Log hours"): donors view
// and edit. The users enum has no volunteers area, so donor permissions govern volunteer records.
function fish_can_log_hours() {
    return fish_can('donors', 'view') && fish_can('donors', 'edit');
}

// Deleting a volunteer hours entry: the edit page's rights plus donors delete, so the delete panel only
// appears on a page that is not read-only.
function fish_can_delete_hours() {
    return fish_can_log_hours() && fish_can('donors', 'delete');
}
