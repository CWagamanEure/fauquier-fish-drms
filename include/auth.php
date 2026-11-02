<?php
// Shared by login, logout, and every protected application entry point.

// bcrypt hash of a random secret; verified against when a username does not exist so
// response time does not reveal which accounts are real.
const AUTH_DUMMY_HASH = '$2y$10$.YmlgMPa0vsmeYNxb9re4eIbx7DX3dkLIEv/rRvpj6v2BHts9E5cG';

function auth_start_session() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        $cookie = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookie['path'],
            'domain' => $cookie['domain'],
            'secure' => $cookie['secure'] || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function auth_is_logged_in() {
    // Require a fresh login after replacing the legacy privilege-escalating flow.
    return ($_SESSION['auth_version'] ?? null) === 1
        && ($_SESSION['logged_in'] ?? false) === true
        && is_string($_SESSION['_id'] ?? null)
        && $_SESSION['_id'] !== ''
        && in_array($_SESSION['access_level'] ?? null, [1, 2, 3], true);
}

function auth_valid_username($username) {
    if (!is_string($username) || preg_match('//u', $username) !== 1) {
        return false;
    }
    // VARCHAR(256) counts characters in the HTML-encoded ID registration stores.
    // PCRE's Unicode mode works without requiring the optional mbstring extension.
    $stored = htmlspecialchars(trim($username), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return preg_match('/\A.{1,256}\z/us', $stored) === 1;
}

// The representation registration stores in dbpersons.id: trimmed and HTML-encoded, case kept.
function auth_stored_username($username) {
    return htmlspecialchars(trim((string) $username), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// The lookup form of a username: the stored form lower-cased (the id column's collation is
// case-insensitive, so this matches however the account was typed at registration).
function auth_canonical_username($username) {
    return strtolower(auth_stored_username($username));
}

// Resolve a user-supplied file name inside a private directory. Returns the real path or false.
// Rejects path separators, NUL, dotfiles and anything outside $extensions, so callers can only
// ever reach plain documents directly inside $directory.
function auth_private_file($directory, $name, array $extensions) {
    $root = realpath($directory);
    if ($root === false || !is_string($name) || $name === '' || $name[0] === '.'
        || strpbrk($name, "/\\\0") !== false) {
        return false;
    }
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($extension, $extensions, true)) {
        return false;
    }
    $path = realpath($root . '/' . $name);
    if ($path === false || dirname($path) !== $root || !is_file($path)) {
        return false;
    }
    return $path;
}

// role (migration 001) is the source of truth; the numeric level only feeds the legacy page gate.
function auth_access_level_for_role($role) {
    return $role === 'admin' ? 3 : ($role === 'manager' ? 2 : 1);
}

// Resolve links from the application root, including installations in a subfolder.
function auth_url($page) {
    $root = realpath(dirname(__DIR__));
    $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $base = '';
    if ($root !== false && $script !== false && str_starts_with($script, $root . DIRECTORY_SEPARATOR)) {
        // Walk up one URL directory for every filesystem directory between the root and the script.
        $depth = substr_count(substr($script, strlen($root) + 1), DIRECTORY_SEPARATOR);
        $base = dirname($scriptName);
        for ($i = 0; $i < $depth; $i++) {
            $base = dirname($base);
        }
    }
    $base = rtrim(str_replace('\\', '/', $base), '/');
    return $base . '/' . $page;
}

// The Person behind the current session, loaded once per request by require_authentication().
function auth_current_user() {
    return $GLOBALS['auth_current_user'] ?? null;
}

// Re-read the account behind the session so changes made since login take effect on the next
// request: deactivated accounts are signed out and role changes update the access level.
function auth_refresh_account() {
    require_once __DIR__ . '/../database/dbPersons.php';
    try {
        $user = retrieve_person($_SESSION['_id']);
    } catch (Throwable $error) {
        error_log('Session account lookup failed: ' . $error->getMessage());
        return true; // the page reports database trouble itself; do not sign everyone out
    }
    if (!$user || $user->get_status() !== 'active') {
        return false;
    }
    $GLOBALS['auth_current_user'] = $user;
    $_SESSION['role'] = $user->get_role();
    $_SESSION['access_level'] = auth_access_level_for_role($user->get_role());
    return true;
}

function require_authentication($minimumLevel = 1, $json = false) {
    auth_start_session();
    $loggedIn = auth_is_logged_in();
    if ($loggedIn && !auth_refresh_account()) {
        auth_logout();
        $loggedIn = false;
    }
    if (!$loggedIn) {
        if ($json) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Please log in to continue.']);
        } else {
            header('Location: ' . auth_url('login.php'), true, 303);
        }
        exit;
    }
    if ($_SESSION['access_level'] < $minimumLevel) {
        http_response_code(403);
        if ($json) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'You do not have permission to access this resource.']);
        } else {
            echo 'You do not have permission to access this page.';
        }
        exit;
    }
    // Every authenticated POST must carry the form token (field, or X-CSRF-Token header for
    // JavaScript callers), so no handler can be driven from another site.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        auth_require_csrf($json);
    }
}

function auth_csrf_token() {
    if (!isset($_SESSION['auth_csrf_token'])) {
        $_SESSION['auth_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['auth_csrf_token'];
}

function auth_valid_csrf_token($token) {
    return is_string($token) && isset($_SESSION['auth_csrf_token'])
        && hash_equals($_SESSION['auth_csrf_token'], $token);
}

// Hidden input for forms that post to a protected handler.
function auth_csrf_field() {
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(auth_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

// Only accept a POST carrying a valid form token. Called by require_authentication() for every
// POST; handlers that must be POST-only can call it directly to reject GET as well.
function auth_require_csrf($json = false) {
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !auth_valid_csrf_token($token)) {
        http_response_code(403);
        if ($json) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Your form has expired. Reload the page and try again.']);
        } else {
            echo 'Your form has expired. Go back, reload the page and try again.';
        }
        exit;
    }
}

function auth_logout() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $cookie['path'],
            'domain' => $cookie['domain'],
            'secure' => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}
