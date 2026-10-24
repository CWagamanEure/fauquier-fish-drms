<?php
// Campaign list: reads the campaign_summary view through database/dbCampaigns.php.
require_once 'include/fishnet-layout.php';
require_once 'include/permissions.php';
require_once 'database/dbCampaigns.php';

if (!fish_can('campaigns', 'view')) {
  http_response_code(403);
  exit('Your account cannot view campaigns.');
}

$loadError = fish_str($_GET, 'error') === 'load';   // set by campaign.php when its read failed
try {
  $tiles     = campaigns_tiles();
  $campaigns = campaigns_list();
} catch (mysqli_sql_exception $e) {
  // A database fault must not print the exception into the page; log it and render an empty list.
  error_log('campaigns.php: ' . $e->getMessage());
  $loadError = true;
  $tiles     = ['active' => 0, 'raised_year' => 0, 'items_year' => 0, 'ending_soon' => 0];
  $campaigns = [];
}
$canEdit = fish_can('campaigns', 'edit');

$typeLabels   = CAMPAIGN_TYPES;
$statusLabels = CAMPAIGN_STATUS_LABELS;
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
        <?php if ($canEdit): ?><a href="addCampaign.php" class="btn btn-yellow">+ New campaign</a><?php endif; ?>
      </section>
      <?php if ($loadError): ?><div class="notice" role="alert">Could not load campaigns. Please try again shortly.</div><?php endif; ?>

      <section class="tiles">
        <div class="tile"><p class="label">Active campaigns</p><p class="num"><?= number_format($tiles['active']) ?></p></div>
        <div class="tile"><p class="label">Raised this year</p><p class="num">$<?= number_format($tiles['raised_year']) ?></p></div>
        <div class="tile"><p class="label">Items collected this year</p><p class="num"><?= number_format($tiles['items_year']) ?></p></div>
        <div class="tile warn"><p class="label">Ending in <?= (int) CAMPAIGN_ENDING_DAYS ?> days</p><p class="num"><?= number_format($tiles['ending_soon']) ?></p></div>
      </section>

      <section class="card table-card" data-filter-list>
        <div class="toolbar">
          <div class="pills">
            <?php foreach (['active' => 'Active', 'upcoming' => 'Upcoming', 'completed' => 'Completed', 'all' => 'All'] as $v => $label): ?>
              <button type="button" class="pill<?= $v === 'active' ? ' on' : '' ?>" data-filter="<?= $v ?>"><?= $label ?> (<?= (int) ($counts[$v] ?? 0) ?>)</button>
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
              <tr data-status="<?= h($c['status']) ?>" data-name="<?= h(mb_strtolower($c['name'])) ?>">
                <td><a href="campaign.php?id=<?= (int) $c['id'] ?>"><strong><?= h($c['name']) ?></strong></a></td>
                <td><span class="ctype <?= h($c['type']) ?>"><?= h($typeLabels[$c['type']] ?? $c['type']) ?></span></td>
                <td><?= $c['start'] ? date('M j', strtotime($c['start'])) : '&mdash;' ?> &ndash; <?= $c['end'] ? date('M j', strtotime($c['end'])) : '&mdash;' ?></td>
                <td class="progress-cell">
                  <div class="progress-top">
                    <div class="bar" role="img" aria-label="<?= (int) $c['pct'] ?>% of goal"><span class="<?= $c['pct'] >= 100 ? 'done' : '' ?>" style="width: <?= min(100, (int) $c['pct']) ?>%"></span></div>
                    <strong><?= (int) $c['pct'] ?>%</strong>
                  </div>
                  <small><?= h($c['label']) ?></small>
                </td>
                <td><?= h($c['owner']) ?></td>
                <td><span class="status <?= h($c['status']) ?>"><?= h($statusLabels[$c['status']] ?? $c['status']) ?></span></td>
                <td><a href="campaign.php?id=<?= (int) $c['id'] ?>" class="edit">Open &rarr;</a></td>
              </tr>
              <?php endforeach; ?>
              <tr class="no-match" hidden><td colspan="7" class="empty">No campaigns match.</td></tr>
            </tbody>
          </table>
        </div>
        <div class="pager">
          <p>Showing <span data-shown><?= count($campaigns) ?></span> of <?= count($campaigns) ?> campaigns</p>
        </div>
      </section>

      <script src="js/campaigns.js"></script>
<?php fishnet_close(); ?>
