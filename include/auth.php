<?php

// Shared by login, logout, and protected application entry points.
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

// Resolve links from the application root, including installations in a subfolder.
function auth_url($page) {
    $root = str_replace('\\', '/', dirname(__DIR__));
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $relative = substr($script, strlen($root));
    $base = $relative !== '' && str_ends_with($scriptName, $relative)
        ? substr($scriptName, 0, -strlen($relative))
        : rtrim(dirname($scriptName), '/.');
    return $base . '/' . $page;
}

function require_authentication($minimumLevel = 1, $json = false) {
    auth_start_session();
    if (!auth_is_logged_in()) {
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
