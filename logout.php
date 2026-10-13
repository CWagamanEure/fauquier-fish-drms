<?php
// Sign out. A POST with a valid form token ends the session; a plain GET (old bookmarks,
// typed URLs) shows a confirmation form so a link alone can never terminate a session.
require_once __DIR__ . '/include/auth.php';
auth_start_session();

if (!auth_is_logged_in()) {
    auth_logout();
    header('Location: login.php?loggedOut=1', true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_valid_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Your sign-out form has expired. Return to the application and try again.');
    }
    auth_logout();
    header('Location: login.php?loggedOut=1', true, 303);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FISH NET | Sign Out</title>
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
    <h2 class="welcome">Sign out of<br>the FISH NET?</h2>

    <form class="login-form" method="post" action="logout.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <button type="submit" class="login-btn">Sign Out</button>
      <p class="login-msg"><a href="dashboard.php">Return to dashboard</a></p>
    </form>
  </main>

</body>
</html>
