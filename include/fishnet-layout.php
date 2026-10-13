<?php
/*
 * FISH NET shared page layout
 */

// took from casa but changed to our stuffSidebar links: 
const FISHNET_LINKS = [
	'dashboard'      => ['dashboard.php',      'Dashboard'],
	'donor'          => ['donor.php',          'Donor'],
	'donations'      => ['donations.php',      'Donations'],
	//above this are pages done in sprint 1
  'campaigns'      => ['campaigns.php',      'Campaigns'],
	'communications' => ['communications.php', 'Communications'],
	'treasurer'      => ['treasurer.php',      'Treasurer'],
	'reports'        => ['reports.php',        'Reports'],
	'imports'        => ['dataImports.php',    'Data &amp; Imports'],
  // users was also done in sprint 1
	'users'          => ['manageUsers.php',    'Manage Users'],
];

// Login check: runs as soon as a page includes this file. include/auth.php owns the session
// settings, the CSRF token and the redirect to login.php for anonymous visitors.
require_once __DIR__ . '/auth.php';
require_authentication(1);
date_default_timezone_set('America/New_York');

// The signed-in person (pages can use $fishnetUser too)
require_once 'database/dbPersons.php';
require_once 'domain/Person.php';
$fishnetUser = isset($_SESSION['_id']) ? retrieve_person($_SESSION['_id']) : null;


// ---------- Top of every page ----------
function fishnet_open($pageTitle, $activePage, $pageCss = []) {
	global $fishnetUser;
	$initial = $fishnetUser ? strtoupper(substr($fishnetUser->get_name(), 0, 1)) : '?';
	?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FISH NET | <?= htmlspecialchars($pageTitle) ?></title>
  <link rel="icon" type="image/png" href="images/fish-logo.png">
  <link rel="stylesheet" href="css/fishnet.css">
<?php foreach ((array) $pageCss as $css): ?>
  <link rel="stylesheet" href="css/<?= $css ?>">
<?php endforeach; ?>
</head>
<body>

  <header class="topbar">
  <!-- the logo and the FISH net Text that will take you back to dashboard -->
    <a href="dashboard.php" class="brand">
      <img src="images/fish-logo.png" alt="Fauquier FISH logo">
      <span>FISH NET</span>
    </a>
    <!-- //serach bar  -->
    <form class="search" action="donor.php" method="get">
      <input type="search" name="q" placeholder="Search Donors...">
    </form>

    <form method="post" action="logout.php" class="logout-form">
      <?= auth_csrf_field() ?>
      <button type="submit" class="logout">Log Out</button>
    </form>
    
    <a href="viewProfile.php" class="avatar" title="Your profile"><?= htmlspecialchars($initial) ?></a>
  </header>

  <div class="layout">
    <nav class="sidebar">
<?php foreach (FISHNET_LINKS as $key => [$href, $label]):
	$classes = trim(($key === $activePage ? 'active' : '') . ($key === 'users' ? ' manage' : '')); ?>
      <a href="<?= $href ?>"<?= $classes ? " class=\"$classes\"" : '' ?>><?= $label ?></a>
<?php endforeach; ?>
    </nav>

    <main class="content">
<?php
}


// Bottom of every page 
function fishnet_close() {
	?>
    </main>
  </div>

</body>
</html>
<?php
}
