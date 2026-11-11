<?php
// View one outreach post or mailing (viewCommunication.php?id=N). Needs communications view. The results form
// needs communications edit, Delete needs communications delete; the photo is served by download.php.
require_once 'include/fishnet-layout.php';
require_once 'database/dbOutreach.php';

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$back = 'communications.php?tab=outreach';

if (!fish_can('communications', 'view')) {
  if ($isPost) { http_response_code(403); exit('Your account cannot view communications.'); }
  fishnet_open('View communication', 'communications', ['donor.css', 'forms.css', 'communications.css']);
  echo '<div class="notice" role="status">Your account cannot view communications.</div>';
  fishnet_close();
  exit;
}
$idParam = fish_str($_GET, 'id');
$id = ctype_digit($idParam) ? (int) $idParam : 0;
$canEdit = fish_can('communications', 'edit');
$canDelete = fish_can('communications', 'delete');

$item = null;
$loadError = false;
try {
  $item = $id > 0 ? outreach_get($id) : null;
} catch (mysqli_sql_exception $e) {
  error_log('viewCommunication.php: ' . $e->getMessage());
  $loadError = true;
}
if (!$item && !$loadError) { header('Location: ' . $back, true, 303); exit; }

$errors = [];
$vals = $item ? array_map(function ($k) use ($item) { return $item[$k] === null ? '' : (string) $item[$k]; }, array_combine(array_keys(OUTREACH_COUNT_LABELS), array_keys(OUTREACH_COUNT_LABELS))) : [];
if ($isPost) {
  if ($loadError) { http_response_code(503); exit('Could not load this post. Please try again shortly.'); }
  $action = fish_str($_POST, 'action');
  if ($action === 'delete') {
    if (!$canDelete) { http_response_code(403); exit('Your account cannot delete outreach.'); }
    if (outreach_delete($id)) { header('Location: ' . $back . '&deleted=1', true, 303); exit; }
    $errors[] = 'Could not delete the post. Please try again.';
  } elseif ($action === 'results') {
    if (!$canEdit) { http_response_code(403); exit('Your account cannot change outreach.'); }
    foreach (array_keys(OUTREACH_COUNT_LABELS) as $k) { $vals[$k] = fish_str($_POST, $k); }
    $errors = outreach_counts_validate($vals);
    if (!$errors) {
      if (outreach_update_results($id, $vals)) { header('Location: viewCommunication.php?id=' . $id . '&saved=1', true, 303); exit; }
      $errors[] = 'Could not save the results. Please try again.';
    }
  } else {
    http_response_code(400);
    exit('Unknown action.');
  }
}

$saved = fish_str($_GET, 'saved') === '1';
$dash = '&mdash;';
$num = function ($v) { return $v === null ? '&mdash;' : number_format((int) $v); };
$isEmail = $item && outreach_is_email($item['channels']);
$labels = $item ? array_map(function ($c) { return OUTREACH_CHANNELS[$c] ?? $c; }, $item['channels']) : [];
$safeUrl = $item && preg_match('#^https?://#i', (string) $item['post_url']) ? $item['post_url'] : '';
$title = $item && $item['caption'] !== null && $item['caption'] !== ''
  ? mb_strimwidth($item['caption'], 0, 80, '…', 'UTF-8') : ($isEmail ? 'Mass email' : 'Outreach post');

fishnet_open('View communication', 'communications', ['donor.css', 'forms.css', 'communications.css']);
?>

      <p class="crumbs"><a href="communications.php">Communications</a> &rsaquo; <a href="<?= h($back) ?>">Outreach</a> &rsaquo; <strong><?= $isEmail ? 'Mailing' : 'Post' ?></strong></p>
      <?php if ($saved): ?><div class="notice" role="status">Results saved.</div><?php endif; ?>
      <?php if ($loadError): ?><div class="notice" role="alert">Could not load this post. Please try again shortly.</div><?php endif; ?>
      <?php if ($errors): ?>
      <div class="form-errors" role="alert"><strong>Please fix the following:</strong>
        <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>

      <?php if ($item): ?>
      <section class="card comm-head">
        <div class="who">
          <h1><?= h($title) ?></h1>
          <p>
            <?php foreach ($labels as $i => $label): ?><span class="ctag <?= in_array($item['channels'][$i], OUTREACH_EMAIL_CHANNELS, true) ? 'email' : 'social' ?>"><?= h($label) ?></span> <?php endforeach; ?>
            <span class="meta">Date <?= h(date('M j, Y', strtotime($item['posted_on']))) ?>
              &middot; by <?= $item['by_name'] !== null && $item['by_name'] !== '' ? h($item['by_name']) : $dash ?>
              &middot; Campaign: <?php if ($item['campaign'] === null): ?><?= $dash ?><?php elseif (fish_can('campaigns', 'view')): ?><a href="campaign.php?id=<?= (int) $item['campaign_id'] ?>"><?= h($item['campaign']) ?></a><?php else: ?><?= h($item['campaign']) ?><?php endif; ?></span>
          </p>
        </div>
        <?php if ($canDelete): ?>
        <div class="head-btns">
          <form method="post" action="viewCommunication.php?id=<?= (int) $item['id'] ?>" onsubmit="return confirm('Delete this outreach post? This cannot be undone.');">
            <?= auth_csrf_field() ?>
            <button type="submit" name="action" value="delete" class="btn btn-danger small">Delete</button>
          </form>
        </div>
        <?php endif; ?>
      </section>

      <div class="view-grid">
        <?php if ($isEmail): ?>
        <section class="card panel">
          <h3>Email content</h3>
          <div class="email-frame">
            <p class="email-meta">Sent <?= h(date('M j, Y', strtotime($item['posted_on']))) ?> as <?= h(implode(' + ', $labels)) ?></p>
            <div class="email-body"><?= $item['caption'] !== null && $item['caption'] !== '' ? nl2br(h($item['caption'])) : '<span class="muted">No content added.</span>' ?></div>
            <?php if ($item['file']): ?><p><img src="download.php?type=outreach&amp;id=<?= (int) $item['file']['id'] ?>" alt="<?= h($item['file']['original_name']) ?>" class="outreach-photo"></p><?php endif; ?>
            <?php if ($safeUrl !== ''): ?><a href="<?= h($safeUrl) ?>" class="link" target="_blank" rel="noopener noreferrer">View online &#8599;</a><?php endif; ?>
          </div>
        </section>
        <?php else: ?>
        <section class="card panel">
          <h3>The post</h3>
          <div class="post-frame">
            <div class="post-top">
              <span class="post-avatar">F</span>
              <div><strong>Fauquier FISH</strong><small><?= h(date('M j, Y', strtotime($item['posted_on']))) ?></small></div>
            </div>
            <p class="post-caption"><?= $item['caption'] !== null && $item['caption'] !== '' ? nl2br(h($item['caption'])) : '<span class="muted">No caption added.</span>' ?></p>
            <?php if ($item['file']): ?>
            <div class="post-photo"><img src="download.php?type=outreach&amp;id=<?= (int) $item['file']['id'] ?>" alt="<?= h($item['file']['original_name']) ?>" class="outreach-photo"></div>
            <?php else: ?>
            <div class="post-photo"><span>No photo uploaded</span></div>
            <?php endif; ?>
            <p class="post-stats">Likes <?= $num($item['likes']) ?> &nbsp; Comments <?= $num($item['comments']) ?> &nbsp; Shares <?= $num($item['shares']) ?></p>
            <?php if ($safeUrl !== ''): ?><a href="<?= h($safeUrl) ?>" class="link" target="_blank" rel="noopener noreferrer">View original post &#8599;</a><?php endif; ?>
          </div>
        </section>
        <?php endif; ?>

        <div class="view-side">
          <section class="tiles">
            <div class="tile"><p class="label">Reach</p><p class="num"><?= $num($item['reach']) ?></p></div>
            <div class="tile"><p class="label">Likes</p><p class="num"><?= $num($item['likes']) ?></p></div>
            <div class="tile"><p class="label">Shares</p><p class="num"><?= $num($item['shares']) ?></p></div>
            <div class="tile"><p class="label">Comments</p><p class="num"><?= $num($item['comments']) ?></p></div>
          </section>

          <?php if ($canEdit): ?>
          <section class="card panel">
            <h3>Update results</h3>
            <form method="post" action="viewCommunication.php?id=<?= (int) $item['id'] ?>" novalidate>
              <?= auth_csrf_field() ?>
              <div class="grid g-2">
                <?php foreach (OUTREACH_COUNT_LABELS as $k => $label): ?>
                <div class="field"><label for="r_<?= $k ?>"><?= $k === 'reach' ? 'Reach / views' : h($label) ?></label><input id="r_<?= $k ?>" name="<?= $k ?>" inputmode="numeric" value="<?= h($vals[$k] ?? '') ?>"></div>
                <?php endforeach; ?>
              </div>
              <p class="top-gap"><button type="submit" name="action" value="results" class="btn btn-yellow small">Save results</button></p>
            </form>
          </section>
          <?php endif; ?>

          <section class="card panel">
            <h3>Linked campaign</h3>
            <div class="linked-box">
              <?php if ($item['campaign'] !== null): ?>
              <strong><?= h($item['campaign']) ?></strong>
              <?php else: ?><p class="muted">Not linked to a campaign.</p><?php endif; ?>
            </div>
          </section>
          <section class="card panel">
            <h3>Logged</h3>
            <p><?= h(date('M j, Y', (int) $item['created_ts'])) ?><?= $item['recorder'] !== '' ? ' by ' . h($item['recorder']) : '' ?></p>
          </section>
        </div>
      </div>
      <?php endif; ?>

<?php fishnet_close(); ?>