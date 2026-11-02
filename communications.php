<?php
// Communications list: One-on-one tab and Outreach tab (communications.php?tab=outreach).
// FRONT END ONLY: no data yet. Backend TODO: fill $contacts (one-on-one rows) and $outreach (social posts and
// mass mailings, one row per send) and the two counts; the markup for each row is in the commented example.
require_once 'include/fishnet-layout.php';

$tab = ($_GET['tab'] ?? '') === 'outreach' ? 'outreach' : 'one';
$contacts = [];   // Backend TODO
$outreach = [];   // Backend TODO

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
          <a href="addCommunication.php" class="btn btn-yellow">+ Log communication</a>
        </div>
      </section>

      <section class="card tabs-card comm-card">
        <nav class="tab-bar comm-tabs" aria-label="Communication type">
          <a href="communications.php" class="<?= $tab === 'one' ? 'on' : '' ?>">One-on-one <span class="count"><?= count($contacts) ?></span></a>
          <a href="communications.php?tab=outreach" class="<?= $tab === 'outreach' ? 'on' : '' ?>">Outreach <span class="count"><?= count($outreach) ?></span></a>
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
              <?php /* Row example for the backend:
              <tr>
                <td>Oct 23</td>
                <td><strong>Donor name</strong></td>
                <td><span class="ctag phone">Phone</span></td>
                <td><span class="dir in" title="Donor reached out">&darr;</span> Subject</td>
                <td>Person contacted</td>
                <td><span class="status followup">Follow up</span></td>
                <td><a href="viewCommunication.php?id=1" class="select">Open &rarr;</a></td>
              </tr> */ ?>
              <?php if (!$contacts): ?>
              <tr><td colspan="7" class="empty">No conversations logged yet. <a href="addCommunication.php" class="select">Log one</a></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="pager">
          <p>Showing 0 of 0 &middot; <span class="dir out">&uarr;</span> FISH reached out &nbsp; <span class="dir in">&darr;</span> donor reached out</p>
        </div>

        <?php else: ?>
        <form class="toolbar comm-toolbar" method="get">
          <input type="hidden" name="tab" value="outreach">
          <div class="tools">
            <select name="channel" aria-label="Channel">
              <option value="">Channel: All</option><option>Mass email</option><option>Newsletter</option><option>Facebook</option><option>Instagram</option><option>X / Twitter</option><option>LinkedIn</option>
            </select>
            <select name="status" aria-label="Status">
              <option value="">Status: All</option><option>Sent / posted</option><option>Scheduled</option><option>Draft</option>
            </select>
            <select name="campaign" aria-label="Campaign"><option value="">Campaign: All</option></select>
          </div>
          <div class="tools"><input type="search" name="q" placeholder="Search subject or caption..." aria-label="Search"></div>
        </form>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Date</th><th>Subject / caption</th><th>Channel</th><th>Audience</th><th>Reach</th><th>Opened</th><th>Replies</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <?php /* Row example for the backend:
              <tr>
                <td>Oct 15</td>
                <td><strong>Subject</strong><small class="sub">by Staff name &middot; Campaign name</small></td>
                <td><span class="ctag email">Mass email</span></td>
                <td>All active donors</td>
                <td><strong>1,012</strong></td>
                <td><div class="mini-bar"><span style="width:46%"></span></div> 46%</td>
                <td>14</td>
                <td><span class="status sent">Sent</span></td>
                <td><a href="viewCommunication.php?id=1&amp;type=email" class="select">Open &rarr;</a></td>
              </tr> */ ?>
              <?php if (!$outreach): ?>
              <tr><td colspan="9" class="empty">No outreach yet. Import a mailing or log a social media post.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <p class="note-bar">One row per mailing or post, not per person. Open one to see who received it, bounces and replies.</p>
        <div class="pager"><p>Showing 0 of 0</p></div>
        <?php endif; ?>
      </section>

<?php fishnet_close(); ?>
