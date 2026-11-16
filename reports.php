<?php
// Reports: filters (date range, campaign), four tabs (Giving, Donors, Campaigns, Volunteers), each with a
// chart and a breakdown, and headline tiles.
// FRONT END ONLY: no data yet. Backend TODO (all for the chosen $range and $campaign):
//   - $campaigns: [id => name] for the campaign select
//   - $tiles: ['raised' => float, 'donors' => int, 'retention' => float (0-100), 'hours' => float]
//   - giving tab:     $monthly = [['label' => 'Jan', 'cash' => float, 'noncash' => float], ...] (oldest first)
//   - donors tab:     $status  = ['active' => int, 'lybunt' => int, 'sybunt' => int, 'inactive' => int] (donor_summary.status)
//                     $topDonors = [['name' => ..., 'total' => float], ...] (up to 10)
//   - campaigns tab:  $campaignRows = [['name' => ..., 'goal' => float, 'raised' => float, 'donors' => int], ...]
//   - volunteers tab: $hoursByMonth = [['label' => 'Jan', 'hours' => float], ...]; $topVolunteers = [['name' => ..., 'hours' => float], ...]
//   - "Download PDF" needs its own handler.
require_once 'include/fishnet-layout.php';

$tabs = ['giving' => 'Giving', 'donors' => 'Donors', 'campaigns' => 'Campaigns', 'volunteers' => 'Volunteers'];
$tab  = array_key_exists(fish_str($_GET, 'tab'), $tabs) ? fish_str($_GET, 'tab') : 'giving';
$ranges = ['this_year' => 'This year', 'last_12' => 'Last 12 months', 'last_year' => 'Last year'];
$range  = array_key_exists(fish_str($_GET, 'range'), $ranges) ? fish_str($_GET, 'range') : 'this_year';
$campaigns = [];                                                      // Backend TODO
$campaign  = isset($campaigns[(int) fish_str($_GET, 'campaign')]) ? (int) fish_str($_GET, 'campaign') : 0;

$tiles = ['raised' => null, 'donors' => null, 'retention' => null, 'hours' => null];   // Backend TODO
$monthly = []; $status = []; $topDonors = []; $campaignRows = []; $hoursByMonth = []; $topVolunteers = [];

$money = fn($n) => '$' . number_format((float) $n);
$tileVal = fn($v, callable $fmt) => $v === null ? '&mdash;' : h($fmt($v));
$link = fn(array $over) => 'reports.php?' . http_build_query(array_merge(['tab' => $tab, 'range' => $range, 'campaign' => $campaign ?: null], $over));

// Bar heights as % of the tallest value in a list.
function rep_scale(array $rows, array $keys) {
  $max = 0.0;
  foreach ($rows as $r) { foreach ($keys as $k) { $max = max($max, (float) $r[$k]); } }
  return fn($v) => $max > 0 ? round((float) $v / $max * 100, 1) : 0;
}

// Donor status donut: conic-gradient stops from the counts.
$statusMeta = ['active' => ['Active', '#458c9c'], 'lybunt' => ['LYBUNT', '#f2c744'], 'sybunt' => ['SYBUNT', '#e59a5b'], 'inactive' => ['Inactive', '#b9bdb3']];
$statusTotal = array_sum(array_map('intval', $status));
$donut = []; $at = 0.0;
foreach ($statusMeta as $k => [$label, $color]) {
  $share = $statusTotal ? (int) ($status[$k] ?? 0) / $statusTotal * 100 : 0;
  $donut[] = sprintf('%s %.2f%% %.2f%%', $color, $at, $at + $share);
  $at += $share;
}

fishnet_open('Reports', 'reports', ['donor.css', 'forms.css', 'reports.css']);
?>

      <section class="page-head">
        <div>
          <p class="crumbs">Reports</p>
          <h1>Reports</h1>
          <p class="lede">Giving and volunteer trends</p>
        </div>
        <form class="rp-filters" method="get" action="reports.php">
          <input type="hidden" name="tab" value="<?= h($tab) ?>">
          <select name="range" aria-label="Date range" onchange="this.form.submit()">
            <?php foreach ($ranges as $k => $label): ?><option value="<?= h($k) ?>"<?= $k === $range ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
          </select>
          <select name="campaign" aria-label="Campaign" onchange="this.form.submit()">
            <option value="">All campaigns</option>
            <?php foreach ($campaigns as $id => $name): ?><option value="<?= (int) $id ?>"<?= $id === $campaign ? ' selected' : '' ?>><?= h($name) ?></option><?php endforeach; ?>
          </select>
          <noscript><button type="submit" class="btn btn-outline">Apply</button></noscript>
          <a href="#" class="btn btn-outline">Download PDF</a>
        </form>
      </section>

      <section class="card tabs-card rp-card">
        <nav class="rp-tabs" aria-label="Report sections">
          <?php foreach ($tabs as $k => $label): ?>
          <a href="<?= h($link(['tab' => $k])) ?>"<?= $k === $tab ? ' class="on" aria-current="page"' : '' ?>><?= h($label) ?></a>
          <?php endforeach; ?>
        </nav>

        <?php if ($tab === 'giving'): $sc = rep_scale($monthly, ['cash', 'noncash']); ?>
        <h2 class="rp-title">Giving by month</h2>
        <p class="rp-note">Cash gifts and the estimated value of non-cash gifts</p>
        <?php if (!$monthly): ?><p class="chart-empty">No giving in this range yet.</p><?php else: ?>
        <div class="rp-bars" role="img" aria-label="Giving by month">
          <?php foreach ($monthly as $m): ?><div class="grp"><i class="c1" style="height:<?= $sc($m['cash']) ?>%" title="<?= h($m['label'] . ' cash ' . $money($m['cash'])) ?>"></i><i class="c2" style="height:<?= $sc($m['noncash']) ?>%" title="<?= h($m['label'] . ' non-cash ' . $money($m['noncash'])) ?>"></i></div><?php endforeach; ?>
        </div>
        <div class="rp-labels"><?php foreach ($monthly as $m): ?><span><?= h($m['label']) ?></span><?php endforeach; ?></div>
        <?php endif; ?>
        <div class="legend"><span><i class="c1"></i>Cash</span><span><i class="c2"></i>Non-cash (est.)</span></div>

        <?php elseif ($tab === 'donors'): ?>
        <div class="rp-split">
          <div>
            <h2 class="rp-title">Donors by status</h2>
            <p class="rp-note">Based on each donor's last gift</p>
            <?php if (!$statusTotal): ?><p class="chart-empty">No donors in this range yet.</p><?php else: ?>
            <div class="donut" style="background: conic-gradient(<?= h(implode(', ', $donut)) ?>)" role="img" aria-label="Donors by status"></div>
            <?php endif; ?>
            <div class="legend col">
              <?php foreach ($statusMeta as $k => [$label, $color]): $n = (int) ($status[$k] ?? 0); ?>
              <span><i style="background:<?= h($color) ?>"></i><?= h($label) ?><?= $statusTotal ? ' ' . round($n / $statusTotal * 100) . '% (' . $n . ')' : '' ?></span>
              <?php endforeach; ?>
            </div>
          </div>
          <div>
            <h2 class="rp-title">Top donors</h2>
            <p class="rp-note">Most given in this range</p>
            <table><thead><tr><th>Donor</th><th class="num-col">Total</th></tr></thead><tbody>
              <?php foreach ($topDonors as $d): ?><tr><td><?= h($d['name']) ?></td><td class="num-col"><?= h($money($d['total'])) ?></td></tr><?php endforeach; ?>
              <?php if (!$topDonors): ?><tr><td colspan="2" class="empty">No gifts in this range yet.</td></tr><?php endif; ?>
            </tbody></table>
          </div>
        </div>

        <?php elseif ($tab === 'campaigns'): ?>
        <h2 class="rp-title">Campaign results</h2>
        <p class="rp-note">Raised against goal</p>
        <table><thead><tr><th>Campaign</th><th>Progress</th><th class="num-col">Raised</th><th class="num-col">Goal</th><th class="num-col">Donors</th></tr></thead><tbody>
          <?php foreach ($campaignRows as $c): $p = (float) $c['goal'] > 0 ? min(100, round((float) $c['raised'] / (float) $c['goal'] * 100)) : 0; ?>
          <tr><td><?= h($c['name']) ?></td><td><div class="meter"><span style="width:<?= $p ?>%"></span></div><small><?= $p ?>%</small></td><td class="num-col"><?= h($money($c['raised'])) ?></td><td class="num-col"><?= h($money($c['goal'])) ?></td><td class="num-col"><?= (int) $c['donors'] ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$campaignRows): ?><tr><td colspan="5" class="empty">No campaigns in this range yet.</td></tr><?php endif; ?>
        </tbody></table>

        <?php else: $sc = rep_scale($hoursByMonth, ['hours']); ?>
        <div class="rp-split">
          <div>
            <h2 class="rp-title">Volunteer hours by month</h2>
            <p class="rp-note">All logged hours</p>
            <?php if (!$hoursByMonth): ?><p class="chart-empty">No hours in this range yet.</p><?php else: ?>
            <div class="rp-bars" role="img" aria-label="Volunteer hours by month">
              <?php foreach ($hoursByMonth as $m): ?><div class="grp"><i class="c1" style="height:<?= $sc($m['hours']) ?>%" title="<?= h($m['label'] . ': ' . $m['hours'] . ' hours') ?>"></i></div><?php endforeach; ?>
            </div>
            <div class="rp-labels"><?php foreach ($hoursByMonth as $m): ?><span><?= h($m['label']) ?></span><?php endforeach; ?></div>
            <?php endif; ?>
          </div>
          <div>
            <h2 class="rp-title">Top volunteers</h2>
            <p class="rp-note">Most hours in this range</p>
            <table><thead><tr><th>Volunteer</th><th class="num-col">Hours</th></tr></thead><tbody>
              <?php foreach ($topVolunteers as $v): ?><tr><td><?= h($v['name']) ?></td><td class="num-col"><?= h(number_format((float) $v['hours'], 1)) ?></td></tr><?php endforeach; ?>
              <?php if (!$topVolunteers): ?><tr><td colspan="2" class="empty">No hours in this range yet.</td></tr><?php endif; ?>
            </tbody></table>
          </div>
        </div>
        <?php endif; ?>
      </section>

      <section class="tiles rp-tiles">
        <div class="tile"><p class="label">Raised</p><p class="num"><?= $tileVal($tiles['raised'], $money) ?></p><p class="sub"><?= h($ranges[$range]) ?></p></div>
        <div class="tile"><p class="label">Donors who gave</p><p class="num"><?= $tileVal($tiles['donors'], fn($v) => number_format((int) $v)) ?></p><p class="sub"><?= h($ranges[$range]) ?></p></div>
        <div class="tile"><p class="label">Retention rate</p><p class="num"><?= $tileVal($tiles['retention'], fn($v) => round((float) $v) . '%') ?></p><p class="sub">gave again after a prior year</p></div>
        <div class="tile"><p class="label">Volunteer hours</p><p class="num"><?= $tileVal($tiles['hours'], fn($v) => number_format((float) $v)) ?></p><p class="sub"><?= h($ranges[$range]) ?></p></div>
      </section>

<?php fishnet_close(); ?>
