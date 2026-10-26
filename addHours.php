<?php
// Log volunteer hours for one donor (addHours.php?donor_id=N), from the profile's "+ Log hours" (UF2-117/118).
// The donor comes only from the URL and must be active and not anonymous; the form posts back to the same
// URL. Saving writes one volunteer_hours row per volunteer (the donor plus any "same shift" volunteers)
// and returns to the profile's Volunteer tab. Editing one entry lives in hours.php.
require_once 'include/fishnet-layout.php';
require_once 'include/permissions.php';
require_once 'database/dbVolunteerHours.php';
require_once 'include/hoursForm.php';

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$title = 'Log volunteer hours';

// Order: donors view, then the donor, then the right to log.
if (!fish_can('donors', 'view')) {
  if ($isPost) { http_response_code(403); exit('Your account cannot view donors.'); }
  fishnet_open($title, 'donor', ['forms.css', 'volunteer.css']);
  echo '<div class="notice" role="status">Your account cannot view donors or their volunteer hours.</div>';
  fishnet_close();
  exit;
}

$donorParam = fish_str($_GET, 'donor_id');
$donor = null;
$loadError = false;
try {
  $donor = ctype_digit($donorParam) ? donor_get((int) $donorParam) : null;
} catch (mysqli_sql_exception $e) {
  error_log('addHours.php: ' . $e->getMessage());
  $loadError = true;
}
if (!$loadError && (!$donor || (int) $donor['is_anonymous'] === 1 || (int) $donor['is_active'] !== 1)) {
  header('Location: donor.php', true, 303);
  exit;
}
$canLog = fish_can_log_hours() && !$loadError;
if ($isPost && !$canLog) {
  http_response_code($loadError ? 503 : 403);
  exit($loadError ? 'Could not load this donor. Please try again shortly.' : 'Your account cannot log volunteer hours.');
}

$donorId = $donor ? (int) $donor['id'] : 0;
$action = 'addHours.php?donor_id=' . $donorId;
$form = hours_form_blank();
$errors = [];
$jobs = $campaigns = $summary = [];
if ($canLog) {
  try {
    $jobs = hours_job_options();
    $campaigns = hours_campaign_names();
    $summary = hours_summary($donorId);
  } catch (mysqli_sql_exception $e) {
    error_log('addHours.php: ' . $e->getMessage());
    $loadError = true;
  }
}

if ($isPost) {
  $form = array_merge($form, hours_form_from_post($_POST));
  if ($loadError) {
    $errors[] = 'Could not load everything this form needs. Please try again shortly.';
  } else {
    try {
      $errors = hours_form_validate($form, null, $jobs, $campaigns, $donorId);
    } catch (mysqli_sql_exception $e) {
      error_log('addHours.php: ' . $e->getMessage());
      $errors = ['Could not save the hours. Please try again.'];
    }
  }
  if (!$errors) {
    $ids = array_merge([$donorId], array_column($form['extra'], 'id'));
    $count = hours_create($ids, $form, auth_current_user()->get_id());
    if ($count !== false) {
      header('Location: donorProfile.php?id=' . $donorId . '&tab=vol&hours=' . $count, true, 303);
      exit;
    }
    $errors[] = 'Could not save the hours. A volunteer may have just been made inactive; please check and try again.';
  }
}

fishnet_open($title, 'donor', ['forms.css', 'volunteer.css']);
?>

      <section class="page-head">
        <div>
          <p class="crumbs"><a href="donor.php">Donor</a> &rsaquo; <?php if ($donor): ?><a href="donorProfile.php?id=<?= $donorId ?>"><?= h($donor['display_name']) ?></a> &rsaquo; <?php endif; ?><?= $title ?></p>
          <h1><?= $title ?></h1>
        </div>
      </section>

      <?php if (!$donor): ?>
      <div class="notice" role="alert">Could not load this donor. Please try again shortly.</div>
      <?php elseif (!fish_can_log_hours()): ?>
      <div class="notice" role="status">Your account cannot log volunteer hours.</div>
      <?php else: ?>
      <?php if ($loadError && !$isPost): ?><div class="notice" role="alert">Could not load everything this form needs. Please try again shortly.</div><?php endif; ?>
      <?php hours_form_render(['edit' => false, 'action' => $action, 'donor' => ['id' => $donorId, 'name' => (string) $donor['display_name']],
                               'form' => $form, 'errors' => $errors, 'jobs' => $jobs, 'campaigns' => $campaigns, 'summary' => $summary,
                               'history' => [], 'readonly' => false, 'delete_action' => '', 'entry_hours' => '']); ?>
<script src="js/volunteerHours.js"></script>
      <?php endif; ?>

<?php fishnet_close(); ?>
