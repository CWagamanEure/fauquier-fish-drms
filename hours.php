<?php
// View or edit one volunteer hours entry (hours.php?id=N), from the profile's Volunteer tab (UF2-119/120).
// The entry id comes only from the URL and the donor only from the stored row; forms post back to the same
// URL, and any posted id or donor is ignored. Without the right to log hours the page is read-only.
// Entries of the anonymous donor (999) are always read-only. Delete is a separate form (action=delete).
require_once 'include/fishnet-layout.php';
require_once 'include/permissions.php';
require_once 'database/dbVolunteerHours.php';
require_once 'include/hoursForm.php';

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

// Order: donors view, then the entry, then the right to save or delete.
if (!fish_can('donors', 'view')) {
  if ($isPost) { http_response_code(403); exit('Your account cannot view donors.'); }
  fishnet_open('Volunteer hours', 'donor', ['forms.css', 'volunteer.css']);
  echo '<div class="notice" role="status">Your account cannot view donors or their volunteer hours.</div>';
  fishnet_close();
  exit;
}

$id = fish_str($_GET, 'id');
if (!ctype_digit($id) || (int) $id === 0) { header('Location: donor.php', true, 303); exit; }
$id = (int) $id;
$entry = null;
$loadError = false;
try {
  $entry = hours_get($id);
} catch (mysqli_sql_exception $e) {
  error_log('hours.php: ' . $e->getMessage());
  $loadError = true;
}
if (!$entry && !$loadError) { header('Location: donor.php', true, 303); exit; }

$isAnonymous = $entry && $entry['donor_id'] === DONOR_ANONYMOUS_ID;
$canWrite = fish_can_log_hours() && !$isAnonymous && !$loadError;
$canDelete = fish_can_delete_hours() && !$isAnonymous && !$loadError;
$action = 'hours.php?id=' . $id;
$form = $entry ? hours_form_from_row($entry) : hours_form_blank();
$errors = [];
$jobs = $campaigns = $summary = $changes = [];
if ($entry) {
  try {
    $jobs = hours_job_options();
    $campaigns = hours_campaign_names($entry['campaign_id']);
    $summary = hours_summary($entry['donor_id']);
    $changes = hours_changes($id);
  } catch (mysqli_sql_exception $e) {
    error_log('hours.php: ' . $e->getMessage());
    $loadError = true;
    $canWrite = $canDelete = false;
  }
}

if ($isPost) {
  $doDelete = fish_str($_POST, 'action') === 'delete';
  if (!($doDelete ? $canDelete : $canWrite)) {
    http_response_code($loadError ? 503 : 403);
    exit($loadError ? 'Could not load this entry. Please try again shortly.'
                    : ($doDelete ? 'Your account cannot delete volunteer hours.' : 'Your account cannot change volunteer hours.'));
  }
  $byUser = auth_current_user()->get_id();
  if ($doDelete) {
    if (hours_delete($id, $byUser)) {
      header('Location: donorProfile.php?id=' . $entry['donor_id'] . '&tab=vol&hours_deleted=1', true, 303);
      exit;
    }
    $errors[] = 'Could not delete the entry. Please try again.';
  } else {
    $form = array_merge($form, hours_form_from_post($_POST));
    try {
      $errors = hours_form_validate($form, $entry, $jobs, $campaigns);
    } catch (mysqli_sql_exception $e) {
      error_log('hours.php: ' . $e->getMessage());
      $errors = ['Could not save the changes. Please try again.'];
    }
    if (!$errors) {
      if (hours_update($id, $form, $byUser, $campaigns)) {
        header('Location: ' . $action . '&saved=1', true, 303);
        exit;
      }
      $errors[] = 'Could not save the changes. Please try again.';
    }
  }
}

// Change history, newest first, ending with the entry being logged.
$when = function ($ts, $by) { return date('M j, Y', $ts) . ($by !== '' ? ' · ' . $by : ''); };
$history = [];
foreach ($changes as $c) { $history[] = [$c['summary'], $when($c['ts'], $c['by'])]; }
if ($entry) { $history[] = ['Entry logged', $when((int) $entry['created_ts'], $entry['recorder'])]; }

$title = $canWrite ? 'Edit volunteer hours' : 'Volunteer hours';
$saved = fish_str($_GET, 'saved') === '1';
fishnet_open($title, 'donor', ['forms.css', 'volunteer.css']);
?>

      <section class="page-head">
        <div>
          <p class="crumbs"><a href="donor.php">Donor</a> &rsaquo; <?php if ($entry): ?><a href="donorProfile.php?id=<?= $entry['donor_id'] ?>&amp;tab=vol"><?= h($entry['donor_name']) ?></a> &rsaquo; <?php endif; ?><?= $title ?></p>
          <h1><?= $title ?></h1>
        </div>
      </section>

      <?php if ($saved && !$isPost): ?><div class="notice" role="status">Hours saved.</div><?php endif; ?>
      <?php if (!$entry): ?>
      <div class="notice" role="alert">Could not load this entry. Please try again shortly.</div>
      <?php else: ?>
      <?php if ($loadError): ?><div class="notice" role="alert">Could not load everything this page needs. Please try again shortly.</div><?php endif; ?>
      <?php hours_form_render(['edit' => true, 'action' => $action, 'donor' => ['id' => $entry['donor_id'], 'name' => (string) $entry['donor_name']],
                               'form' => $form, 'errors' => $errors, 'jobs' => $jobs, 'campaigns' => $campaigns, 'summary' => $summary,
                               'history' => $history, 'readonly' => !$canWrite, 'delete_action' => $canDelete ? $action : '',
                               'entry_hours' => hours_label($entry['hours'])]); ?>
<script src="js/volunteerHours.js"></script>
      <?php endif; ?>

<?php fishnet_close(); ?>
