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
require_once __DIR__ . '/permissions.php';
require_authentication(1);
date_default_timezone_set('America/New_York');

// ---------- View helpers shared by every FISH page (pages must not redefine these) ----------
if (!function_exists('h')) {
	// HTML-escape anything printed into a page.
	function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('initials')) {
	// First letters of the first and last word of a name; Unicode-safe.
	function initials($name) {
		$words = preg_split('/\s+/u', trim((string) $name)) ?: [];
		$first = $words[0] ?? '';
		$last = count($words) > 1 ? end($words) : '';
		$pick = function ($w) { return preg_match('/^(.)/us', $w, $m) ? $m[1] : ''; };
		return mb_strtoupper($pick($first) . $pick($last), 'UTF-8');
	}
}
if (!function_exists('fish_str')) {
	// A trimmed string parameter, or '' when the value is missing or not a string (arrays never reach trim()).
	function fish_str(array $source, $key) {
		return isset($source[$key]) && is_string($source[$key]) ? trim($source[$key]) : '';
	}
}

// The signed-in person (pages can use $fishnetUser too)
require_once 'database/dbPersons.php';
require_once 'domain/Person.php';
$fishnetUser = auth_current_user();   // loaded by require_authentication(); never null here


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
	if ($key === 'users' && !fish_can('users', 'view')) { continue; }   // Manage Users needs the users area
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
