<?php
// opens the css and php that keep the pages consistan
require_once 'include/fishnet-layout.php';
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
//fake data, replace once schema is complete
require_once 'include/donorDataStopgap.php';
$allDonors = stopgap_donors();

$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all','individuals','organizations','lapsed'], true)) $filter = 'all';
$q    = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'last_gift';
if (!in_array($sort, ['last_gift','name','lifetime'], true)) $sort = 'last_gift';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 8;


//filters based on fake dict, replace with SQL once schema is set
$tiles = [
  'total'     => count($allDonors),
  'new_month' => count(array_filter($allDonors, fn($d) => $d['status'] === 'new')),
  'lapsed'    => count(array_filter($allDonors, fn($d) => $d['status'] === 'lapsed')),
  'dupes'     => 5,   // STOPGAP: hardcoded; later count the 'resolution required' records
];

$filtered = array_filter($allDonors, function ($d) use ($filter, $q) {
  if ($filter === 'individuals'   && $d['type'] !== 'Individual')   return false;
  if ($filter === 'organizations' && $d['type'] !== 'Organization') return false;
  if ($filter === 'lapsed'        && $d['status'] !== 'lapsed')     return false;
  if ($q !== '' && stripos($d['name'] . ' ' . $d['contact'], $q) === false) return false;
  return true;
});

usort($filtered, function ($a, $b) use ($sort) {
  if ($sort === 'name')     return strcasecmp($a['name'], $b['name']);
  if ($sort === 'lifetime') return $b['lifetime'] <=> $a['lifetime'];
  return strcmp($b['last_gift'], $a['last_gift']);          // last_gift, newest first
});

$totalFiltered = count($filtered);
$donors = array_slice($filtered, ($page - 1) * $perPage, $perPage);
//end of stopgap

$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
$page   = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// ---- View helpers (keep) ----
function initials($name) {
  $w = preg_split('/\s+/', trim($name));
  return strtoupper(substr($w[0], 0, 1) . (count($w) > 1 ? substr(end($w), 0, 1) : ''));
}
function pageUrl($p) { return '?' . http_build_query(array_merge($_GET, ['page' => $p])); }
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
        <a href="addDonor.php" class="btn btn-yellow">+ New donor</a>
      </section>

      <section class="tiles">
        <div class="tile"><p class="label">Total donors</p><p class="num">1,284</p></div>
        <div class="tile"><p class="label">New this month</p><p class="num">19</p></div>
        <div class="tile"><p class="label">Lapsed (12+ months)</p><p class="num">146</p></div>
        <div class="tile warn">
          <p class="label">Possible duplicates</p>
          <div class="row"><p class="num"><?= (int)$tiles['dupes'] ?></p><a href="dataImports.php">Review &rarr;</a></div>
        </div>
      </section>
<!-- Cards at the top of the page  NEEDS  real Connections  -->
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
            <select name="sort" onchange="this.form.submit()">
              <option value="last_gift" <?= $sort === 'last_gift' ? 'selected' : '' ?>>Sort: Last gift</option>
              <option value="name"      <?= $sort === 'name'      ? 'selected' : '' ?>>Sort: Name</option>
              <option value="lifetime"  <?= $sort === 'lifetime'  ? 'selected' : '' ?>>Sort: Lifetime giving</option>
            </select>
          </div>
        </form>
<!-- NEEDS Connection to db.donors i think is the right table this is FAKE data  -->
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
<!-- needs to be coded does not actually work  -->
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
