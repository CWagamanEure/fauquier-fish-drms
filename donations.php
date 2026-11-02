<?php
// Donations list: cash and non-cash gifts from database/dbDonations.php (UF2-42, 43, 44, 45). Volunteer
// hours are not listed because they are not reported as donations to the IRS.
require_once 'include/fishnet-layout.php';
require_once 'include/permissions.php';
require_once 'database/dbDonations.php';
$canView = fish_can('donations', 'view');
$canEdit = fish_can_record_donations();
$canViewDonors = fish_can('donors', 'view');
$kind  = fish_str($_GET, 'kind');
$q     = fish_str($_GET, 'q');
$range = fish_str($_GET, 'range');
$sort  = fish_str($_GET, 'sort');
$page  = max(1, (int) fish_str($_GET, 'page'));
$loadError = false;
$tiles = ['cash_month' => 0, 'noncash_month' => 0, 'noncash_value' => 0, 'recorded' => 0];
$list  = ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1,
          'kind' => in_array($kind, DONATION_KINDS, true) ? $kind : 'all',
          'range' => in_array($range, DONATION_RANGES, true) ? $range : '30',
          'sort' => in_array($sort, DONATION_SORTS, true) ? $sort : 'newest', 'per_page' => DONATIONS_PER_PAGE];
if ($canView) {
  try {
    $tiles = donations_tiles();
    $list  = donations_list($kind, $q, $range, $sort, $page);
  } catch (mysqli_sql_exception $e) {
    // A database fault must not print the exception into the page; log it and render an empty list.
    error_log('donations.php: ' . $e->getMessage());
    $loadError = true;
  }
}
$rows  = $list['rows'];
$kind  = $list['kind'];
$range = $list['range'];
$sort  = $list['sort'];
$page  = $list['page'];
$totalFiltered = $list['total'];
$totalPages = $list['pages'];
$perPage = $list['per_page'];
$offset = ($page - 1) * $perPage;
function pageUrl($p) {
  $params = array_filter($_GET, 'is_string');
  unset($params['saved']);
  $params['page'] = $p;
  return '?' . http_build_query($params);
}
// "320 items · 150 lbs · est. $500" / "$250.00 · Check"
function amountText(array $r) {
  if ($r['kind'] === 'cash') {
    $method = DONATION_PAYMENT_LABELS[$r['detail']] ?? '';
    return ($r['value'] === null ? '&mdash;' : '$' . number_format($r['value'], 2)) . ($method ? ' &middot; ' . h($method) : '');
  }
  return h(donation_qty_text($r['quantity'], $r['weight_lbs'])) . ($r['value'] === null ? '' : ' &middot; est. $' . number_format($r['value'], 2));
}
fishnet_open('Donations', 'donations', 'donor.css');
?>
      <section class="page-head">
        <div>
          <p class="crumbs">Donations</p>
          <h1>Donations</h1>
        </div>
        <?php if ($canEdit): ?><a href="addDonation.php" class="btn btn-yellow">+ New donation</a><?php endif; ?>
      </section>
      <?php if (!$canView): ?>
      <div class="notice" role="status">Your account cannot view donations.</div>
      <?php else: ?>
      <?php if ($loadError): ?><div class="notice" role="alert">Could not load donations. Please try again shortly.</div><?php endif; ?>
      <section class="tiles three">
        <div class="tile"><p class="label">Cash this month</p><p class="num">$<?= number_format($tiles['cash_month'], 2) ?></p></div>
        <div class="tile"><p class="label">Non-cash this month</p><p class="num"><?= number_format($tiles['noncash_month']) ?> gift<?= $tiles['noncash_month'] === 1 ? '' : 's' ?></p><p class="sub">est. $<?= number_format($tiles['noncash_value'], 2) ?></p></div>
        <div class="tile"><p class="label">Donations recorded</p><p class="num"><?= number_format($tiles['recorded']) ?></p></div>
      </section>

      <section class="card table-card donations">
        <form class="toolbar" method="get">
          <!-- Hidden default button: pressing Enter in the search box keeps the current filter instead of resetting to "All" -->
          <button type="submit" name="kind" value="<?= h($kind) ?>" hidden></button>
          <div class="pills">
            <?php foreach (['all' => 'All', 'cash' => 'Cash', 'noncash' => 'Non-cash'] as $v => $label): ?>
              <button type="submit" name="kind" value="<?= $v ?>" class="pill<?= $kind === $v ? ' on' : '' ?>"><?= $label ?></button>
            <?php endforeach; ?>
          </div>
          <div class="tools">
            <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search donor or campaign...">
            <select name="range" onchange="this.form.querySelector('button[name=kind][hidden]').click()">
              <?php foreach (['30' => 'Last 30 days', '90' => 'Last 90 days', 'year' => 'This year', 'all' => 'All time'] as $v => $label): ?>
              <option value="<?= $v ?>" <?= $range === (string) $v ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
            <select name="sort" onchange="this.form.querySelector('button[name=kind][hidden]').click()">
              <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Sort: Newest</option>
              <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Sort: Oldest</option>
              <option value="amount" <?= $sort === 'amount' ? 'selected' : '' ?>>Sort: Amount</option>
            </select>
          </div>
        </form>

        <div class="table-wrap">
          <table>
            <thead>
              <tr><th>Date</th><th>Donor</th><th>Type</th><th>Amount / Items</th><th>Campaign</th><th>Recorded by</th><th></th></tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): $ref = 'kind=' . $r['kind'] . '&amp;id=' . $r['id']; ?>
              <tr class="row-<?= $r['kind'] ?>">
                <td><?= $r['date'] ? date('M j, Y', strtotime($r['date'])) : '&mdash;' ?></td>
                <td><strong><?php if ($canViewDonors): ?><a href="donorProfile.php?id=<?= $r['donor_id'] ?>"><?= h($r['donor'] ?: 'Unknown donor') ?></a><?php else: ?><?= h($r['donor'] ?: 'Unknown donor') ?><?php endif; ?></strong></td>
                <td><span class="kind <?= $r['kind'] ?>"><?= $r['kind'] === 'cash' ? 'Cash' : 'Non-cash' ?></span></td>
                <td><?= amountText($r) ?></td>
                <td><?= $r['campaign'] !== '' ? h($r['campaign']) : '&mdash;' ?></td>
                <td><?= $r['recorded_by'] !== '' ? h($r['recorded_by']) : '&mdash;' ?></td>
                <td class="actions"><?php if ($canEdit): ?><a href="addDonation.php?<?= $ref ?>" class="edit">Edit</a><?php endif; ?><a href="viewDonation.php?<?= $ref ?>" class="select">View</a></td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$rows): ?>
              <tr><td colspan="7">No donations match your filters.<?= $range !== 'all' ? ' Try &ldquo;All time&rdquo;.' : '' ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="pager">
          <p>Showing <?= $totalFiltered ? $offset + 1 : 0 ?>&ndash;<?= min($offset + $perPage, $totalFiltered) ?> of <?= number_format($totalFiltered) ?> donations</p>
          <div class="pages">
            <?php if ($page > 1): ?><a href="<?= h(pageUrl($page - 1)) ?>" class="pg wide">&lsaquo; Previous</a><?php endif; ?>
            <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
              <a href="<?= h(pageUrl($p)) ?>" class="pg<?= $p === $page ? ' on' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?><a href="<?= h(pageUrl($page + 1)) ?>" class="pg wide">Next &rsaquo;</a><?php endif; ?>
          </div>
        </div>
      </section>
      <?php endif; ?>

<?php fishnet_close(); ?>
