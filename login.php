<?php
// FISH NET login. Two-step layout by Tristan (username first, then password) with the
// session, CSRF and credential handling from include/auth.php.
//
// Step 1 accepts any non-empty username and shows the password box without checking the
// database, so the page never confirms which usernames exist. Both steps post to this file;
// a single POST with username and password also works.
require_once __DIR__ . '/include/auth.php';
auth_start_session();

if (auth_is_logged_in()) {
	header('Location: dashboard.php');
	exit;
}

$step     = 'username';   // which box to show: 'username' or 'password'
$username = '';
$error    = '';

// "Not you?" link starts over.
if (isset($_GET['restart'])) {
	header('Location: login.php', true, 303);
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
	$password = $_POST['password'] ?? null;

	// One generic message for every credential problem (empty, malformed, too long, wrong),
	// so the response never hints at which part was rejected.
	$failed = 'Unable to log in with that username and password.';
	if (!auth_valid_csrf_token($_POST['csrf_token'] ?? null)) {
		$error = 'Your login form has expired. Please try again.';
	} elseif (!auth_valid_username($username)) {
		$error = $failed;
	} elseif ($password === null) {
		// Step 1 passed: ask for the password.
		$step = 'password';
	} elseif (!is_string($password) || $password === '') {
		$step  = 'password';
		$error = $failed;
	} else {
		$step = 'password';
		require_once __DIR__ . '/database/dbPersons.php';
		try {
			$user = retrieve_person(auth_canonical_username($username));
			// Always run the hash check so timing does not reveal whether the account exists.
			$hash = $user ? (string) $user->get_password() : AUTH_DUMMY_HASH;
			$passwordMatches = password_verify($password, $hash) && $user;
			if ($passwordMatches && $user->get_status() === 'active') {
				session_regenerate_id(true);
				$_SESSION = [
					'auth_version' => 1,
					'logged_in'    => true,
					'_id'          => $user->get_id(),
					'access_level' => auth_access_level_for_role($user->get_role()),
					'role'         => $user->get_role(),
					'locked'       => false,
				];
				header('Location: dashboard.php', true, 303);
				exit;
			}
			$error = $failed;
		} catch (Throwable $exception) {
			error_log('Login database lookup failed: ' . $exception->getMessage());
			http_response_code(503);
			$error = 'Login is temporarily unavailable. Please try again shortly.';
		}
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FISH NET | Log In</title>
  <link rel="icon" type="image/png" href="images/fish-logo.png">
  <link rel="stylesheet" href="css/login.css">
</head>
<body>

  <header class="site-header">
    <div class="brand">
      <img src="images/fish-logo.png" alt="Fauquier FISH logo" class="logo">
      <div class="brand-text">
        <h1>Fauquier FISH</h1>
        <p class="tagline">Feed. Inspire. Support. Health.</p>
      </div>
    </div>
  </header>

  <main class="login-area">
    <h2 class="welcome">Welcome to the<br>FISH NET</h2>

    <form class="login-form" method="post" action="login.php">
      <?= auth_csrf_field() ?>
<?php if ($error !== ''): ?>
      <p class="login-msg error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if (isset($_GET['loggedOut'])): ?>
      <p class="login-msg success" role="status">You have been logged out.</p>
<?php endif; ?>
<?php if (isset($_GET['registerSuccess'])): ?>
      <p class="login-msg success">Registration successful! Please log in below.</p>
<?php endif; ?>

<?php if ($step === 'username'): ?>
      <!-- Step 1: username -->
      <label for="username">Username</label>
      <input type="text" id="username" name="username" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>"
             autocomplete="username" autofocus required>

      <button type="submit" class="login-btn">Next -&gt;</button>

<?php else: ?>
      <!-- Step 2: password (username is locked in) -->
      <label>Username</label>
      <div class="user-locked">
        <span><?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></span>
        <a href="login.php?restart=1">Not you?</a>
      </div>
      <input type="hidden" name="username" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>">

      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password" autofocus required>

      <button type="submit" class="login-btn">Login -&gt;</button>
<?php endif; ?>
    </form>
  </main>

</body>
</html>
