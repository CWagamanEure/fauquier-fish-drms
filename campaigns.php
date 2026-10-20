<?php
// Campaign list: FRONT END ONLY. The data below is fake so the page can be styled and reviewed.
// Backend TODO: replace $campaigns / $tiles with real queries (dbevents doubles as the campaigns table).
require_once 'include/fishnet-layout.php';

// ---------- fake data ----------
$tiles = ['active' => 3, 'raised_year' => 48920, 'items_year' => 4310, 'ending_soon' => 1];
$campaigns = [
  ['id' => 1, 'name' => 'Thanksgiving Food Drive', 'type' => 'both',       'start' => '2026-11-01', 'end' => '2026-11-24', 'pct' => 68,  'label' => '$8,160 of $12,000',       'owner' => 'Charity C.', 'status' => 'active'],
  ['id' => 2, 'name' => 'Winter Coat Collection',  'type' => 'items',      'start' => '2026-10-15', 'end' => '2026-12-15', 'pct' => 41,  'label' => '205 of 500 coats',        'owner' => 'James A.',   'status' => 'active'],
  ['id' => 3, 'name' => 'Fall Volunteer Push',     'type' => 'volunteers', 'start' => '2026-10-01', 'end' => '2026-10-31', 'pct' => 75,  'label' => '30 of 40 new volunteers', 'owner' => 'James A.',   'status' => 'active'],
  ['id' => 4, 'name' => 'Year-End Giving',         'type' => 'cash',       'start' => '2026-12-01', 'end' => '2026-12-31', 'pct' => 0,   'label' => '$0 of $30,000',           'owner' => 'Dana P.',    'status' => 'upcoming'],
  ['id' => 5, 'name' => 'Spring Pantry Restock',   'type' => 'both',       'start' => '2027-03-01', 'end' => '2027-03-31', 'pct' => 0,   'label' => '$0 of $5,000',            'owner' => 'Charity C.', 'status' => 'upcoming'],
  ['id' => 6, 'name' => 'Back to School Kits',     'type' => 'items',      'start' => '2026-07-15', 'end' => '2026-08-20', 'pct' => 112, 'label' => '560 of 500 kits',         'owner' => 'Charity C.', 'status' => 'completed'],
  ['id' => 7, 'name' => 'Giving Tuesday 2025',     'type' => 'cash',       'start' => '2025-12-02', 'end' => '2025-12-02', 'pct' => 94,  'label' => '$9,420 of $10,000',       'owner' => 'Dana P.',    'status' => 'completed'],
];
// ---------- end fake data ----------

$typeLabels   = ['cash' => 'Cash', 'items' => 'Items', 'both' => 'Cash + items', 'volunteers' => 'Volunteers'];
$statusLabels = ['active' => 'Active', 'upcoming' => 'Upcoming', 'completed' => 'Completed'];
$counts = array_count_values(array_column($campaigns, 'status'));
$counts['all'] = count($campaigns);

fishnet_open('Campaigns', 'campaigns', ['donor.css', 'campaigns.css']);
?>

      <section class="page-head">
        <div>
          <p class="crumbs">Campaigns</p>
          <h1>Campaigns</h1>
          <p class="lede">Fundraisers, drives and events, and how close each one is to its goal</p>
        </div>
        <a href="addCampaign.php" class="btn btn-yellow">+ New campaign</a>
      </section>

      <section class="tiles">
        <div class="tile"><p class="label">Active campaigns</p><p class="num"><?= $tiles['active'] ?></p></div>
        <div class="tile"><p class="label">Raised this year</p><p class="num">$<?= number_format($tiles['raised_year']) ?></p></div>
        <div class="tile"><p class="label">Items collected this year</p><p class="num"><?= number_format($tiles['items_year']) ?></p></div>
        <div class="tile warn"><p class="label">Ending in 14 days</p><p class="num"><?= $tiles['ending_soon'] ?></p></div>
      </section>

      <section class="card table-card" data-filter-list>
        <div class="toolbar">
          <div class="pills">
            <?php foreach (['active' => 'Active', 'upcoming' => 'Upcoming', 'completed' => 'Completed', 'all' => 'All'] as $v => $label): ?>
              <button type="button" class="pill<?= $v === 'active' ? ' on' : '' ?>" data-filter="<?= $v ?>"><?= $label ?> (<?= $counts[$v] ?? 0 ?>)</button>
            <?php endforeach; ?>
          </div>
          <div class="tools">
            <input type="search" placeholder="Search campaigns..." aria-label="Search campaigns" data-search>
          </div>
        </div>
        <div class="table-wrap">
          <table class="campaign-table">
            <thead>
              <tr><th>Campaign</th><th>Type</th><th>Dates</th><th>Progress</th><th>Owner</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
              <?php foreach ($campaigns as $c): ?>
              <tr data-status="<?= $c['status'] ?>" data-name="<?= h(strtolower($c['name'])) ?>">
                <td><a href="campaign.php?id=<?= $c['id'] ?>"><strong><?= h($c['name']) ?></strong></a></td>
                <td><span class="ctype <?= $c['type'] ?>"><?= $typeLabels[$c['type']] ?></span></td>
                <td><?= date('M j', strtotime($c['start'])) ?> &ndash; <?= date('M j', strtotime($c['end'])) ?></td>
                <td class="progress-cell">
                  <div class="progress-top">
                    <div class="bar" role="img" aria-label="<?= $c['pct'] ?>% of goal"><span class="<?= $c['pct'] >= 100 ? 'done' : '' ?>" style="width: <?= min(100, $c['pct']) ?>%"></span></div>
                    <strong><?= $c['pct'] ?>%</strong>
                  </div>
                  <small><?= h($c['label']) ?></small>
                </td>
                <td><?= h($c['owner']) ?></td>
                <td><span class="status <?= $c['status'] ?>"><?= $statusLabels[$c['status']] ?></span></td>
                <td><a href="campaign.php?id=<?= $c['id'] ?>" class="edit">Open &rarr;</a></td>
              </tr>
              <?php endforeach; ?>
              <tr class="no-match" hidden><td colspan="7" class="empty">No campaigns match.</td></tr>
            </tbody>
          </table>
        </div>
        <div class="pager">
          <p>Showing <span data-shown>3</span> of 16 campaigns</p>
          <div class="pages">
            <a href="#" class="pg on">1</a><a href="#" class="pg">2</a><a href="#" class="pg">3</a>
            <a href="#" class="pg wide">Next &rsaquo;</a>
          </div>
        </div>
      </section>

      <script src="js/campaigns.js"></script>
<?php fishnet_close(); ?>
