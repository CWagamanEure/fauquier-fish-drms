<?php
// Front-end: sample data is hard-coded below; replace it with database values.
require_once 'include/fishnet-layout.php';
fishnet_open('View donation', 'donations', 'donation.css');
?>

      <section class="page-head">
        <div>
          <p class="crumbs"><a href="donations.php">Donations</a> &rsaquo; <strong>View donation</strong></p>
          <h1>View donation</h1>
        </div>
      </section>

      <form class="donation-layout view-only">
        <div class="card form-card">

          <fieldset class="step">
            <legend>1 &middot; Donor</legend>
            <div class="donor-box chosen">
              <span class="initials">MS</span>
              <div>
                <strong>Main St. Church</strong>
                <p class="meta">Organization &middot; Warrenton, VA &middot; Donor since 2019</p>
              </div>
              <a href="donorProfile.php" class="link">View donor</a>
            </div>
          </fieldset>

          <fieldset class="step">
            <legend>2 &middot; Donation type</legend>
            <div class="choices">
              <label class="choice">
                <input type="radio" name="kind" value="cash">
                <span><strong>Cash</strong><small>Check, cash, card, online</small></span>
              </label>
              <label class="choice on">
                <input type="radio" name="kind" value="noncash" checked>
                <span><strong>Non-cash (items)</strong><small>Food, clothing, goods</small></span>
              </label>
            </div>
          </fieldset>

          <fieldset class="step details">
            <legend>3 &middot; Item details</legend>
            <div class="grid">
              <div class="field span2"><label>Item description</label><input value="Canned vegetables &amp; soup" readonly></div>
              <div class="field"><label>Category</label><input value="Canned food" readonly></div>
              <div class="field"><label>Quantity</label><input value="320 cans" readonly></div>

              <div class="field"><label>Estimated value</label><input value="$500.00" readonly></div>
              <div class="field"><label>Date received</label><input value="10/03/2026" readonly></div>
              <div class="field"><label>Condition</label><input value="New / unopened" readonly></div>
              <div></div>

              <div class="field span2"><label>Intended use</label><input value="Thanksgiving Food Drive" readonly></div>
              <div class="field span2"><label>Notes</label><input value="Dropped off by youth group" readonly></div>
            </div>
          </fieldset>

          <div class="spacer"></div>

          <footer class="form-foot right">
            <div class="foot-btns">
              <a href="donations.php" class="btn btn-outline small">Close</a>
              <a href="addDonation.php" class="btn btn-yellow small">Edit donation</a>
            </div>
          </footer>
        </div>

        <aside class="side">
          <div class="card summary">
            <h3>Main St. Church</h3>
            <dl>
              <dt>Lifetime cash</dt><dd>$4,300</dd>
              <dt>Lifetime items</dt><dd>1,870 items</dd>
              <dt>Last gift</dt><dd>Aug 12, 2026</dd>
              <dt>Volunteers linked</dt><dd>6</dd>
            </dl>
            <a href="donorProfile.php" class="link">View full profile &rarr;</a>
          </div>

          <div class="card campaign">
            <p class="ct-title">Counts toward</p>
            <p class="ct-name">Thanksgiving Food Drive</p>
            <div class="bar"><div class="fill" style="width: 78%"></div></div>
            <p class="ct-text">1,560 of 2,000 items after this gift</p>
          </div>
        </aside>
      </form>

<?php fishnet_close(); ?>
