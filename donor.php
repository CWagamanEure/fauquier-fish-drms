<?php
// Donor list: reads the donor_summary view through database/dbDonors.php (UF2-61, 67, 68, 70).
require_once 'include/fishnet-layout.php';
require_once 'include/permissions.php';
require_once 'database/dbDonors.php';

$filter = fish_str($_GET, 'filter');
$q      = fish_str($_GET, 'q');
$sort   = fish_str($_GET, 'sort');
$page   = max(1, (int) ($_GET['page'] ?? 1));

$loadError = fish_str($_GET, 'error') === 'load';   // set by updateDonor.php when its read failed
try {
  $tiles = donors_tiles();
  $list  = donors_list($filter, $q, $sort, $page);
} catch (mysqli_sql_exception $e) {
  // A database fault must not print the exception into the page; log it and render an empty list.
  error_log('donor.php: ' . $e->getMessage());
  $loadError = true;
  $tiles = ['total' => 0, 'new_month' => 0, 'lapsed' => 0, 'dupes' => 0];
  $list  = ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'filter' => in_array($filter, DONOR_FILTERS, true) ? $filter : 'all',
            'sort' => in_array($sort, DONOR_SORTS, true) ? $sort : 'last_gift', 'per_page' => DONORS_PER_PAGE];
}
$donors = $list['rows'];
$filter = $list['filter'];
$sort   = $list['sort'];
$page   = $list['page'];
$totalFiltered = $list['total'];
$totalPages = $list['pages'];
$perPage = $list['per_page'];
$offset = ($page - 1) * $perPage;
$canEdit = fish_can('donors', 'edit');

function pageUrl($p) {
  $params = array_filter($_GET, 'is_string') + ['page' => $p];
  $params['page'] = $p;
  return '?' . http_build_query($params);
}
$colors = ['teal', 'navy', 'sage', 'yellow'];
$statusLabels = ['active' => 'Active', 'new' => 'New', 'lapsed' => 'Lapsed'];

fishnet_open('Donors', 'donor', 'donor.css');
?>

      <section class="page-head">
        <div>
          <p class="crumbs">Donor</p>
          <h1>Donors</h1>
          <p class="lede">Everyone who gives to Fauquier FISH &mdash; people and organizations</p>
        </div>
        <?php if ($canEdit): ?><a href="addDonor.php" class="btn btn-yellow">+ New donor</a><?php endif; ?>
      </section>
      <?php if ($loadError): ?><div class="notice" role="alert">Could not load donors. Please try again shortly.</div><?php endif; ?>

      <section class="tiles">
        <div class="tile"><p class="label">Total donors</p><p class="num"><?= number_format($tiles['total']) ?></p></div>
        <div class="tile"><p class="label">New this month</p><p class="num"><?= number_format($tiles['new_month']) ?></p></div>
        <div class="tile"><p class="label">Lapsed (12+ months)</p><p class="num"><?= number_format($tiles['lapsed']) ?></p></div>
        <div class="tile warn">
          <p class="label">Possible duplicates</p>
          <div class="row"><p class="num"><?= number_format($tiles['dupes']) ?></p><a href="dataImports.php">Review &rarr;</a></div>
        </div>
      </section>

      <section class="card table-card">
        <form class="toolbar" method="get">
          <!-- Hidden default button: pressing Enter in the search box keeps the current filter instead of resetting to "All" -->
          <button type="submit" name="filter" value="<?= h($filter) ?>" hidden></button>
          <div class="pills">
            <?php foreach (['all'=>'All','individuals'=>'Individuals','organizations'=>'Organizations','lapsed'=>'Lapsed'] as $v => $label): ?>
              <button type="submit" name="filter" value="<?= $v ?>" class="pill<?= $filter === $v ? ' on' : '' ?>"><?= $label ?></button>
            <?php endforeach; ?>
          </div>
          <div class="tools">
            <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search name, email or phone...">
            <select name="sort" onchange="this.form.querySelector('button[name=filter][hidden]').click()">
              <option value="last_gift" <?= $sort === 'last_gift' ? 'selected' : '' ?>>Sort: Last gift</option>
              <option value="name"      <?= $sort === 'name'      ? 'selected' : '' ?>>Sort: Name</option>
              <option value="lifetime"  <?= $sort === 'lifetime'  ? 'selected' : '' ?>>Sort: Lifetime giving</option>
            </select>
          </div>
        </form>
        <div class="table-wrap">
          <table>
            <thead>
              <tr><th>Name</th><th>Type</th><th>Contact</th><th>Lifetime giving</th><th>Last gift</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
              <?php foreach ($donors as $d): ?>
              <tr>
                <td><span class="initials <?= $colors[$d['id'] % 4] ?>"><?= h(initials($d['name'])) ?></span><strong><?= h($d['name']) ?></strong></td>
                <td><?= h($d['type']) ?></td>
                <td><?= h($d['contact']) ?></td>
                <td>$<?= number_format($d['lifetime'], 2) ?></td>
                <td><?= $d['last_gift'] ? date('M j, Y', strtotime($d['last_gift'])) : '&mdash;' ?></td>
                <td><span class="status <?= h($d['status']) ?>"><?= $statusLabels[$d['status']] ?? '' ?></span></td>
                <td><a href="donorProfile.php?id=<?= (int)$d['id'] ?>" class="select">Select</a></td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$donors): ?>
              <tr><td colspan="7">No donors match your search.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="pager">
          <p>Showing <?= $totalFiltered ? $offset + 1 : 0 ?>&ndash;<?= min($offset + $perPage, $totalFiltered) ?> of <?= number_format($totalFiltered) ?> donors</p>
          <div class="pages">
            <?php if ($page > 1): ?><a href="<?= h(pageUrl($page - 1)) ?>" class="pg wide">&lsaquo; Previous</a><?php endif; ?>
            <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
              <a href="<?= h(pageUrl($p)) ?>" class="pg<?= $p === $page ? ' on' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?><a href="<?= h(pageUrl($page + 1)) ?>" class="pg wide">Next &rsaquo;</a><?php endif; ?>
          </div>
        </div>
      </section>

<?php fishnet_close(); ?>
