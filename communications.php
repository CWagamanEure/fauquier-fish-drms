<?php
// Communications list: One-on-one tab (still front end only) and Outreach tab (communications.php?tab=outreach),
// which reads the outreach tables through database/dbOutreach.php.
require_once 'include/fishnet-layout.php';
require_once 'database/dbOutreach.php';

$tab = ($_GET['tab'] ?? '') === 'outreach' ? 'outreach' : 'one';
$canView = fish_can('communications', 'view');
$canLog = fish_can_log_communications();
$contacts = [];   // Backend TODO (one-on-one)
$loadError = false;
$channel = fish_str($_GET, 'channel');
$campaign = fish_str($_GET, 'campaign');
$q = fish_str($_GET, 'q');
$page = max(1, (int) fish_str($_GET, 'page'));
$list = ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => OUTREACH_PER_PAGE];
$outTotal = 0;
$campaigns = [];
if ($canView) {
  try {
    $outTotal = outreach_list('', '', '', 1)['total'];
    if ($tab === 'outreach') {
      $list = outreach_list($channel, $campaign, $q, $page);
      $campaigns = outreach_filter_campaigns();
    }
  } catch (mysqli_sql_exception $e) {
    error_log('communications.php: ' . $e->getMessage());
    $loadError = true;
  }
}
$rows = $list['rows'];
$page = $list['page'];
$offset = ($page - 1) * $list['per_page'];
$saved = fish_str($_GET, 'saved') === '1';
$deleted = fish_str($_GET, 'deleted') === '1';

function comm_page_url($p) {
  $params = array_filter($_GET, 'is_string');
  unset($params['saved'], $params['deleted']);
  $params['page'] = $p;
  return '?' . http_build_query($params);
}

fishnet_open('Communications', 'communications', ['donor.css', 'forms.css', 'communications.css']);
?>

      <section class="page-head">
        <div>
          <p class="crumbs">Communications</p>
          <h1>Communications</h1>
          <p class="lede">Conversations with donors, and the outreach that goes out to many of them</p>
        </div>
        <div class="head-btns">
          <a href="dataImports.php" class="btn btn-outline">Import mailing</a>
          <?php if ($canLog): ?><a href="addCommunication.php<?= $tab === 'outreach' ? '?kind=outreach' : '' ?>" class="btn btn-yellow">+ Log communication</a><?php endif; ?>
        </div>
      </section>

      <?php if (!$canView): ?>
      <div class="notice" role="status">Your account cannot view communications.</div>
      <?php else: ?>
      <?php if ($saved): ?><div class="notice" role="status">Outreach post saved.</div><?php endif; ?>
      <?php if ($deleted): ?><div class="notice" role="status">Outreach post deleted.</div><?php endif; ?>
      <?php if ($loadError): ?><div class="notice" role="alert">Could not load outreach. Please try again shortly.</div><?php endif; ?>

      <section class="card tabs-card comm-card">
        <nav class="tab-bar comm-tabs" aria-label="Communication type">
          <a href="communications.php" class="<?= $tab === 'one' ? 'on' : '' ?>">One-on-one <span class="count"><?= count($contacts) ?></span></a>
          <a href="communications.php?tab=outreach" class="<?= $tab === 'outreach' ? 'on' : '' ?>">Outreach <span class="count"><?= (int) $outTotal ?></span></a>
        </nav>

        <?php if ($tab === 'one'): ?>
        <form class="toolbar comm-toolbar" method="get">
          <div class="tools">
            <select name="type" aria-label="Type">
              <option value="">Type: All</option><option>Email</option><option>Phone</option><option>Mail</option><option>Text</option><option>In person</option>
            </select>
            <select name="status" aria-label="Status">
              <option value="">Status: All</option><option>Logged</option><option>Sent</option><option>Replied</option><option>Follow up</option><option>Bounced</option>
            </select>
            <select name="range" aria-label="Date range">
              <option>Last 30 days</option><option>Last 90 days</option><option>This year</option><option>All time</option>
            </select>
          </div>
          <div class="tools"><input type="search" name="q" placeholder="Search donor, person or subject..." aria-label="Search"></div>
        </form>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Date</th><th>Donor</th><th>Type</th><th>Subject</th><th>Person contacted</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr><td colspan="7" class="empty">No conversations logged yet.<?php if ($canLog): ?> <a href="addCommunication.php" class="select">Log one</a><?php endif; ?></td></tr>
            </tbody>
          </table>
        </div>
        <div class="pager"><p>Showing 0 of 0</p></div>

        <?php else: ?>
        <form class="toolbar comm-toolbar" method="get">
          <input type="hidden" name="tab" value="outreach">
          <div class="tools">
            <select name="channel" aria-label="Channel" onchange="this.form.submit()">
              <option value="">Channel: All</option>
              <?php foreach (OUTREACH_CHANNELS as $v => $label): ?><option value="<?= h($v) ?>"<?= $channel === $v ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
            </select>
            <select name="campaign" aria-label="Campaign" onchange="this.form.submit()">
              <option value="">Campaign: All</option>
              <?php foreach ($campaigns as $id => $name): ?><option value="<?= (int) $id ?>"<?= $campaign === (string) $id ? ' selected' : '' ?>><?= h($name) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="tools"><input type="search" name="q" value="<?= h($q) ?>" placeholder="Search caption or poster..." aria-label="Search"></div>
        </form>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Date</th><th>Caption</th><th>Channels</th><th>Campaign</th><th>Reach</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= $r['date'] !== '' ? h(date('M j, Y', strtotime($r['date']))) : '&mdash;' ?></td>
                <td><strong><?= $r['caption'] !== '' ? h(mb_strimwidth($r['caption'], 0, 80, '…', 'UTF-8')) : 'No caption' ?></strong><?php if ($r['by'] !== ''): ?><small class="sub">by <?= h($r['by']) ?></small><?php endif; ?></td>
                <td><?php foreach ($r['channels'] as $i => $c): ?><span class="ctag <?= in_array($c, OUTREACH_EMAIL_CHANNELS, true) ? 'email' : 'social' ?>"><?= h($r['labels'][$i] ?? $c) ?></span> <?php endforeach; ?></td>
                <td><?= $r['campaign'] !== '' ? h($r['campaign']) : '&mdash;' ?></td>
                <td><?= $r['reach'] === null ? '&mdash;' : number_format($r['reach']) ?></td>
                <td><a href="viewCommunication.php?id=<?= (int) $r['id'] ?>" class="select">Open &rarr;</a></td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$rows): ?>
              <tr><td colspan="6" class="empty"><?= ($channel !== '' || $campaign !== '' || $q !== '') ? 'No outreach matches your filters.' : 'No outreach yet.' ?><?php if ($canLog && $channel === '' && $campaign === '' && $q === ''): ?> <a href="addCommunication.php?kind=outreach" class="select">Log a post</a><?php endif; ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <p class="note-bar">One row per post or mailing, not per person. A post that went out on several channels is one row.</p>
        <div class="pager">
          <p>Showing <?= $list['total'] ? $offset + 1 : 0 ?>&ndash;<?= min($offset + $list['per_page'], $list['total']) ?> of <?= number_format($list['total']) ?></p>
          <div class="pages">
            <?php if ($page > 1): ?><a href="<?= h(comm_page_url($page - 1)) ?>" class="pg wide">&lsaquo; Previous</a><?php endif; ?>
            <?php for ($p = max(1, $page - 2); $p <= min($list['pages'], $page + 2); $p++): ?>
              <a href="<?= h(comm_page_url($p)) ?>" class="pg<?= $p === $page ? ' on' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($page < $list['pages']): ?><a href="<?= h(comm_page_url($page + 1)) ?>" class="pg wide">Next &rsaquo;</a><?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </section>
      <?php endif; ?>

<?php fishnet_close(); ?>