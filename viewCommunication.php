<?php
// View one outreach item: a social media post (type=post) or a mass email (type=email).
// FRONT END ONLY: empty layout, no data. Backend TODO: load the item by ?id=, set $type from it, and fill $item,
// $results and $recipients. Every "—" below is where a value goes.
require_once 'include/fishnet-layout.php';

$type = ($_GET['type'] ?? 'post') === 'email' ? 'email' : 'post';
$item = ['title' => '', 'channel' => $type === 'email' ? 'Mass email' : '', 'status' => '', 'date' => '', 'by' => '',
         'campaign' => '', 'caption' => '', 'url' => '', 'notes' => '', 'from' => '', 'audience' => ''];   // Backend TODO
$recipients = [];   // Backend TODO (mass email only)
$dash = '&mdash;';
$v = fn($s) => $s !== '' ? h($s) : '&mdash;';

fishnet_open('View communication', 'communications', ['donor.css', 'forms.css', 'communications.css']);
?>

      <p class="crumbs"><a href="communications.php">Communications</a> &rsaquo; <a href="communications.php?tab=outreach">Outreach</a> &rsaquo; <strong><?= $type === 'email' ? 'Mass email' : 'Social media post' ?></strong></p>

      <section class="card comm-head">
        <div class="who">
          <h1><?= $item['title'] !== '' ? h($item['title']) : 'Untitled' ?></h1>
          <p>
            <span class="ctag <?= $type === 'email' ? 'email' : 'social' ?>"><?= $item['channel'] !== '' ? h($item['channel']) : 'Channel' ?></span>
            <span class="status off"><?= $item['status'] !== '' ? h($item['status']) : 'Status' ?></span>
            <span class="meta">Date <?= $v($item['date']) ?> &middot; by <?= $v($item['by']) ?> &middot; Campaign: <?= $v($item['campaign']) ?></span>
          </p>
        </div>
        <div class="head-btns">
          <a href="#" class="btn btn-outline small">Edit</a>
          <a href="#" class="btn btn-outline small">Duplicate</a>
          <button type="button" class="btn btn-danger small">Delete</button>
        </div>
      </section>

      <div class="view-grid">
        <?php if ($type === 'post'): ?>
        <section class="card panel">
          <h3>The post</h3>
          <div class="post-frame">
            <div class="post-top">
              <span class="post-avatar">F</span>
              <div><strong>Fauquier FISH</strong><small>Date <?= $v($item['date']) ?></small></div>
            </div>
            <p class="post-caption"><?= $item['caption'] !== '' ? nl2br(h($item['caption'])) : '<span class="muted">No caption added.</span>' ?></p>
            <div class="post-photo"><span>No photo uploaded</span></div>
            <p class="post-stats">Likes <?= $dash ?> &nbsp; Comments <?= $dash ?> &nbsp; Shares <?= $dash ?></p>
            <?php if ($item['url'] !== ''): ?><a href="<?= h($item['url']) ?>" class="link" target="_blank" rel="noopener">View original post &#8599;</a><?php endif; ?>
          </div>
        </section>

        <div class="view-side">
          <section class="tiles three">
            <div class="tile"><p class="label">Reach</p><p class="num"><?= $dash ?></p></div>
            <div class="tile"><p class="label">Likes</p><p class="num"><?= $dash ?></p></div>
            <div class="tile"><p class="label">Shares</p><p class="num"><?= $dash ?></p></div>
          </section>
          <section class="card panel">
            <h3>Linked campaign</h3>
            <div class="linked-box">
              <?php if ($item['campaign'] !== ''): ?>
              <strong><?= h($item['campaign']) ?></strong>
              <?php else: ?><p class="muted">Not linked to a campaign.</p><?php endif; ?>
            </div>
          </section>
          <section class="card panel">
            <h3>Notes</h3>
            <p><?= $item['notes'] !== '' ? nl2br(h($item['notes'])) : '<span class="muted">No notes.</span>' ?></p>
          </section>
        </div>

        <?php else: ?>
        <section class="card panel">
          <h3>Email content</h3>
          <div class="email-frame">
            <p class="email-meta">From: <?= $v($item['from']) ?><br>To: <?= $v($item['audience']) ?></p>
            <div class="email-body"><?= $item['caption'] !== '' ? nl2br(h($item['caption'])) : '<span class="muted">No email content added. Paste or upload it when importing the mailing.</span>' ?></div>
          </div>
        </section>

        <div class="view-side">
          <section class="tiles">
            <div class="tile"><p class="label">Recipients</p><p class="num"><?= $dash ?></p></div>
            <div class="tile"><p class="label">Opened</p><p class="num"><?= $dash ?></p></div>
            <div class="tile"><p class="label">Replies</p><p class="num"><?= $dash ?></p></div>
            <div class="tile"><p class="label">Bounced</p><p class="num"><?= $dash ?></p></div>
          </section>
          <section class="card panel recipients">
            <div class="rec-head">
              <h3>Recipients</h3>
              <div class="pills">
                <a href="#" class="pill on">All</a><a href="#" class="pill">Opened</a><a href="#" class="pill">Bounced</a><a href="#" class="pill">Replied</a>
              </div>
            </div>
            <div class="table-wrap"><table>
              <thead><tr><th>Donor</th><th>Email</th><th>Status</th></tr></thead>
              <tbody>
                <?php if (!$recipients): ?><tr><td colspan="3" class="empty">No recipients yet.</td></tr><?php endif; ?>
              </tbody>
            </table></div>
            <div class="pager"><p>Showing 0 of 0</p><a href="#" class="link">Export list</a></div>
          </section>
        </div>
        <?php endif; ?>
      </div>

<?php fishnet_close(); ?>
