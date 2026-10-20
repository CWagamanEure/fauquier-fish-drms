<?php
// Shared by manageUsers.php, addUser.php, editUser.php and userPassword.php: the access gate, form
// parsing and validation, and the small 403/404 page. Expects include/fishnet-layout.php to be loaded
// first (h(), fish_str(), auth_current_user()).
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/../database/dbUsers.php';

const FISH_USER_PASSWORD_MIN = 8;    // characters
const FISH_USER_PASSWORD_MAX = 72;   // bytes: bcrypt reads no further

// Render a short page inside the layout with the given HTTP status and stop. Used when the account
// lacks the users area (403) or the requested username does not exist (404).
function users_stop_page($status, $title, $message) {
    http_response_code($status);
    fishnet_open($title, 'users', ['donor.css', 'forms.css']);
    ?>
      <section class="page-head"><h1><?= h($title) ?></h1></section>
      <div class="notice" role="alert"><?= h($message) ?></div>
      <p><a href="<?= $status === 404 ? 'manageUsers.php' : 'dashboard.php' ?>" class="btn btn-outline small">&lsaquo; Back</a></p>
    <?php
    fishnet_close();
    exit;
}

// Every Manage Users page starts here: no users view, no page.
function users_require_view() {
    if (!fish_can('users', 'view')) {
        users_stop_page(403, 'Manage Users', 'Your account does not include Manage Users.');
    }
}

// The account named by ?id=, or the 404 page.
function users_target_or_404() {
    $id = fish_str($_GET, 'id');
    $user = $id !== '' ? user_get($id) : null;
    if (!$user) {
        users_stop_page(404, 'Manage Users', 'User not found.');
    }
    return $user;
}

function users_is_self(array $target) {
    return strcasecmp($target['id'], auth_current_user()->get_id()) === 0;
}

function users_current_is_admin() {
    return auth_current_user()->get_role() === 'admin';
}

// Admin accounts, and the admin role itself, are managed by admins only.
function users_may_manage(array $target) {
    return fish_can('users', 'edit') && ($target['role'] !== 'admin' || users_current_is_admin());
}

function users_full_name(array $user) {
    $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    return $name !== '' ? $name : (string) $user['name'];
}

// The posted account fields (passwords are read separately and never kept in $form).
function user_form_from_post(array $post, array $base) {
    foreach (['first', 'last', 'email'] as $k) { $base[$k] = fish_str($post, $k); }
    if (array_key_exists('username', $base)) { $base['username'] = fish_str($post, 'username'); }
    if (array_key_exists('role', $base)) { $base['role'] = fish_str($post, 'role'); }
    return $base;
}

// $stored is null for a new account. Returns a list of messages.
function user_form_validate(array $form, ?array $stored = null) {
    $errors = [];
    foreach (['first' => 'First name', 'last' => 'Last name'] as $k => $label) {
        if ($form[$k] === '') { $errors[] = $label . ' is required.'; }
        elseif (mb_strlen($form[$k], 'UTF-8') > 100) { $errors[] = $label . ' must be 100 characters or fewer.'; }
    }
    if ($stored === null) {
        if (!preg_match('/\A[A-Za-z0-9._-]{3,64}\z/', $form['username'])) {
            $errors[] = 'Username must be 3 to 64 letters, digits, dots, underscores or hyphens.';
        } elseif (users_username_taken($form['username'])) {
            $errors[] = 'That username is already taken.';
        }
    }
    if ($form['email'] === '') { $errors[] = 'Work email is required.'; }
    elseif (strlen($form['email']) > 255 || filter_var($form['email'], FILTER_VALIDATE_EMAIL) === false) { $errors[] = 'Enter a valid email address.'; }
    elseif (users_email_taken($form['email'], $stored['id'] ?? null)) { $errors[] = 'That email is already used by another account.'; }
    if (array_key_exists('role', $form)) {
        if (!isset(FISH_USER_ROLES[$form['role']])) { $errors[] = 'Choose a role.'; }
        elseif ($form['role'] === 'admin' && !users_current_is_admin() && ($stored === null || $stored['role'] !== 'admin')) {
            $errors[] = 'Only an administrator can assign the Administrator role.';
        }
    }
    return $errors;
}

// The two password fields; the values themselves are never echoed or logged.
function user_password_validate($password, $confirm) {
    $errors = [];
    if (!is_string($password) || !is_string($confirm)) { return ['Enter the new password twice.']; }
    if (mb_strlen($password, 'UTF-8') < FISH_USER_PASSWORD_MIN) { $errors[] = 'Password must be at least ' . FISH_USER_PASSWORD_MIN . ' characters.'; }
    if (strlen($password) > FISH_USER_PASSWORD_MAX) { $errors[] = 'Password is too long (' . FISH_USER_PASSWORD_MAX . ' bytes at most).'; }
    if ($password !== $confirm) { $errors[] = 'Passwords do not match.'; }
    return $errors;
}

// Presets for js/userForm.js, safe inside a <script> block.
function users_presets_json() {
    return json_encode(role_presets(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

// Message for the list page after a redirect-after-POST.
function users_saved_notice() {
    $did = fish_str($_GET, 'did');
    $username = fish_str($_GET, 'user');
    $messages = ['created' => 'User %s created.', 'updated' => 'User %s saved.', 'password' => 'Password updated for %s.',
                 'deactivated' => 'User %s deactivated.', 'reactivated' => 'User %s reactivated.'];
    if (!isset($messages[$did]) || $username === '') { return ''; }
    $user = user_get($username);
    return $user ? sprintf($messages[$did], users_full_name($user)) : '';
}
