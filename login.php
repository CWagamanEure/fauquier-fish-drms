<?php
// FISH NET login: I thought it would look better for it to be 2 stepsTCD
// and make it easier for the users to know what step they are messed up on TCD
// step 1: type username  -> if it exists, show the password box if worng says 'No account found with that username.'  TCD
// step 2: type password  -> if wrong, say "Wrong password" or else will go to the dashboard page.TCD
session_cache_expire(30);
session_start();

ini_set("display_errors", 1);
error_reporting(E_ALL);

// Already logged in? Go straight to the dashboard.
if (isset($_SESSION['_id'])) {
	header('Location: dashboard.php');
	die();
}

$step     = 'username';   // which box to show: 'username' or 'password' TCD
$username = '';
$error    = '';

// "Not you?" link starts over TCD
if (isset($_GET['restart'])) {
	header('Location: login.php');
	die();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
	require_once('include/input-validation.php');
	require_once('domain/Person.php');
	require_once('database/dbPersons.php');

	$args     = sanitize($_POST, array('password'));
	$username = strtolower(trim($args['username'] ?? ''));

	if ($username === '') {
		$error = 'Please enter your username.';

	} else {
		$user = retrieve_person($username);

		if (!$user) {
			// Step 1 failed: no such username 
			$error = 'No account found with that username.';

		} else if (!isset($_POST['password'])) {
			// Step 1 passed: username exists, now ask for the password
			$step = 'password';

		} else {
			// Step 2: check the password
			$step = 'password';
			$password = $args['password'];

			if ($password === '') {
				$error = 'Please enter your password.';

			} else if (password_verify($password, $user->get_password())) {
				$_SESSION['logged_in']    = true;
				$_SESSION['access_level'] = $user->get_access_level();
				$_SESSION['_id']          = $user->get_id();

				if ($user->get_id() == 'vmsroot') {
					$_SESSION['access_level'] = 3;
					$_SESSION['locked'] = false;
				} else {
					$_SESSION['access_level'] = 3;
				}
				header('Location: dashboard.php');
				die();

			} else {
				$error = 'Wrong password. Please try again.';
			}
		}
	}
}
//end of page logicTCD
?>

<!DOCTYPE html>
<!-- start of the pretty part TCD-->
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
		<!--add the FISH LOGO to the Top of the page TCD-->
      <img src="images/fish-logo.png" alt="Fauquier FISH logo" class="logo">
      <div class="brand-text">
		<!--changed the header of the page to say FISH and moto TCD-->
        <h1>Fauquier FISH</h1>
        <p class="tagline">Feed. Inspire. Support. Health.</p>
      </div>
    </div>
  </header>

  <main class="login-area">
	<!-- Removed the photo casa had to have a more minimal login page --> 
    <h2 class="welcome">Welcome to the<br>FISH NET</h2>

    <form class="login-form" method="post" action="login.php">
<?php if ($error): ?>
      <p class="login-msg error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>
<?php if (isset($_GET['registerSuccess'])): ?>
      <p class="login-msg success">Registration successful! Please log in below.</p>
<?php endif; ?>

<?php if ($step === 'username'): ?>
      <!-- Step 1: username -->
      <label for="username">Username</label>
      <input type="text" id="username" name="username" value="<?= htmlspecialchars($username) ?>"
             autocomplete="username" autofocus required>

      <button type="submit" class="login-btn">Next -&gt;</button>

<?php else: ?>
      <!-- Step 2: password (username is locked in) -->
      <label>Username</label>
      <div class="user-locked">
        <span><?= htmlspecialchars($username) ?></span>
        <a href="login.php?restart=1">Not you?</a>
      </div>
      <input type="hidden" name="username" value="<?= htmlspecialchars($username) ?>">

      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password" autofocus required>

      <button type="submit" class="login-btn">Login -&gt;</button>
<?php endif; ?>
    </form>
  </main>

</body>
</html>
