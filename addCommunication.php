<?php
// Add communication: a one-on-one contact with a donor, or an outreach post linked to a campaign.
// FRONT END ONLY: empty form, nothing is saved. Backend TODO: fill the donor, campaign and staff dropdowns,
// handle the POST (check fish_can('communications', 'edit')), and save. Field names are what the backend should read.
// ?kind=outreach opens with Outreach picked (for example from a campaign page).
require_once 'include/fishnet-layout.php';

$outreachFirst = ($_GET['kind'] ?? '') === 'outreach';
$donors = $campaigns = $staff = [];   // Backend TODO: id => name

fishnet_open('Add communication', 'communications', ['forms.css', 'communications.css']);
?>

      <section class="page-head">
        <div>
          <p class="crumbs"><a href="communications.php">Communications</a> &rsaquo; Add communication</p>
          <h1>Add communication</h1>
        </div>
      </section>

      <div class="split">
        <form class="card form-card comm-form" method="post" enctype="multipart/form-data" novalidate>
          <?= auth_csrf_field() ?>

          <div>
            <div class="group-title">1 &middot; What kind of communication?</div>
            <div class="kind-choices" role="radiogroup" aria-label="Kind of communication">
              <label class="kind-choice">
                <input type="radio" name="kind" value="one_on_one" id="kindOne" <?= $outreachFirst ? '' : 'checked' ?>>
                <span><strong>One-on-one contact</strong><small>Call, email, letter, text or visit with a donor</small></span>
              </label>
              <label class="kind-choice">
                <input type="radio" name="kind" value="outreach" id="kindOut" <?= $outreachFirst ? 'checked' : '' ?>>
                <span><strong>Outreach</strong><small>Social media post or mass mailing to many people</small></span>
              </label>
            </div>
          </div>

          <!-- ===== One-on-one ===== -->
          <div class="only-one">
            <div class="group-title">2 &middot; Who</div>
            <div class="grid g-2">
              <div class="field">
                <label for="donor_id">Donor *</label>
                <select id="donor_id" name="donor_id">
                  <option value="">Choose a donor&hellip;</option>
                  <?php foreach ($donors as $id => $name): ?><option value="<?= (int) $id ?>"><?= h($name) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="field"><label for="person">Person contacted</label><input id="person" name="person_contacted" placeholder="Who you spoke with"></div>
            </div>
          </div>

          <div class="only-one">
            <div class="group-title">3 &middot; Details</div>
            <div class="grid g-comm4">
              <div class="field"><label for="comm_type">Type *</label>
                <select id="comm_type" name="comm_type"><option value="">Choose&hellip;</option><option value="phone">Phone</option><option value="email">Email</option><option value="mail">Mail</option><option value="text">Text</option><option value="in_person">In person</option><option value="other">Other</option></select></div>
              <div class="field"><label for="direction">Direction</label>
                <select id="direction" name="direction"><option value="outbound">FISH reached out</option><option value="inbound">Donor reached out</option></select></div>
              <div class="field"><label for="occurred_at">Date *</label><input type="date" id="occurred_at" name="occurred_at"></div>
              <div class="field"><label for="status">Status</label>
                <select id="status" name="status"><option value="logged">Logged</option><option value="sent">Sent</option><option value="replied">Replied</option><option value="follow_up">Follow up</option><option value="bounced">Bounced</option></select></div>
            </div>
            <div class="grid g-subject top-gap">
              <div class="field"><label for="subject">Subject</label><input id="subject" name="subject" placeholder="What it was about"></div>
              <div class="field"><label for="campaign_one">Campaign (optional)</label>
                <select id="campaign_one" name="campaign_id_one"><option value="">None</option>
                  <?php foreach ($campaigns as $id => $name): ?><option value="<?= (int) $id ?>"><?= h($name) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="field top-gap"><label for="summary">Summary</label><textarea id="summary" name="summary" rows="3" placeholder="What was said or agreed"></textarea></div>
            <div class="grid g-follow top-gap">
              <label class="check-line"><input type="checkbox" name="follow_up"> Remind me to follow up</label>
              <div class="field"><label for="follow_up_on">Follow-up date</label><input type="date" id="follow_up_on" name="follow_up_on"></div>
            </div>
          </div>

          <!-- ===== Outreach ===== -->
          <div class="only-out">
            <div class="group-title">2 &middot; Channel</div>
            <div class="channel-choices" role="radiogroup" aria-label="Channel">
              <?php foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'x' => 'X / Twitter', 'linkedin' => 'LinkedIn', 'mass_email' => 'Mass email', 'newsletter' => 'Newsletter'] as $v => $label): ?>
              <label class="channel"><input type="radio" name="channel" value="<?= $v ?>"><span><?= $label ?></span></label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="only-out">
            <div class="group-title">3 &middot; Details</div>
            <div class="grid g-out">
              <div class="field"><label for="campaign_out">Campaign</label>
                <select id="campaign_out" name="campaign_id"><option value="">None</option>
                  <?php foreach ($campaigns as $id => $name): ?><option value="<?= (int) $id ?>"><?= h($name) ?></option><?php endforeach; ?></select></div>
              <div class="field"><label for="posted_on">Post date *</label><input type="date" id="posted_on" name="posted_on"></div>
              <div class="field"><label for="posted_by">Posted by</label>
                <select id="posted_by" name="posted_by"><option value="">Choose&hellip;</option>
                  <?php foreach ($staff as $id => $name): ?><option value="<?= h($id) ?>"><?= h($name) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="field top-gap"><label for="post_url">Link to post</label><input type="url" id="post_url" name="post_url" placeholder="https://"></div>
            <div class="field top-gap"><label for="caption">Caption / content</label><textarea id="caption" name="caption" rows="3" placeholder="The post caption, or the text of the email"></textarea></div>
            <div class="field top-gap"><label for="photo">Photo or screenshot</label><input type="file" id="photo" name="photo" accept="image/*" class="file-input"></div>
          </div>

          <div class="only-out">
            <div class="group-title">4 &middot; Results <span class="muted">(optional, update later)</span></div>
            <div class="grid g-comm4">
              <div class="field"><label for="reach">Reach / views</label><input id="reach" name="reach" inputmode="numeric"></div>
              <div class="field"><label for="likes">Likes</label><input id="likes" name="likes" inputmode="numeric"></div>
              <div class="field"><label for="shares">Shares</label><input id="shares" name="shares" inputmode="numeric"></div>
              <div class="field"><label for="comments">Comments</label><input id="comments" name="comments" inputmode="numeric"></div>
            </div>
          </div>

          <div class="spacer"></div>
          <div class="form-foot right">
            <div class="foot-btns">
              <a href="communications.php" class="btn btn-outline small">Cancel</a>
              <button type="submit" class="btn btn-yellow small"><span class="only-one-inline">Save contact</span><span class="only-out-inline">Save outreach</span></button>
            </div>
          </div>
        </form>

        <aside class="side">
          <section class="card panel only-one-panel">
            <h3>Where it shows</h3>
            <p>On the donor&rsquo;s Communications tab, in One-on-one, and on the campaign if you pick one.</p>
          </section>
          <section class="card panel preview-panel only-out-panel">
            <h3>Will show on this campaign</h3>
            <p>Pick a campaign and this post appears on that campaign&rsquo;s Communications tab. Outreach isn&rsquo;t tied to one donor, so it shows in the Outreach tab, not on donor profiles.</p>
          </section>
        </aside>
      </div>

<?php fishnet_close(); ?>
