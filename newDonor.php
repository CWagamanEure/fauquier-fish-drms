<?php
// data here is faked needs real connections will be marked with needs connection
require_once 'include/fishnet-layout.php';
// php and css doc to keep all pages syncy
fishnet_open('Add donor', 'donor', 'forms.css');
?>

      <section class="page-head">
        <!-- title for the page -->
        <div>
          <p class="crumbs"><a href="donor.php">Donor</a> &rsaquo; <strong>Add donor</strong></p>
          <h1>Add donor</h1>
        </div>
      </section>

      <form class="split" method="post">
        <div class="card form-card">

          <div class="group">
            <!-- lable for user to decide what type of donor they are creating -->
            <p class="group-title">Donor type</p>
            <div class="seg">
              <!-- how the user will selet the type of donor this selction will change what the user see 1. indicdual 2. organization -->
              <input type="radio" name="donor_type" id="typeInd" value="individual" checked>
              <label for="typeInd">Individual</label>
              <input type="radio" name="donor_type" id="typeOrg" value="organization">
              <label for="typeOrg">Organization</label>
            </div>
          </div>

          <!-- the feilds the user will see when they choose individual -->
          <div class="group only-ind">
            <p class="group-title">Name</p>
            <div class="grid g-name">
              <div class="field"><label for="first">First name *</label><input id="first" name="first_name" placeholder="First name"></div>
              <div class="field"><label for="middle">Middle</label><input id="middle" name="middle_name" placeholder="Middle"></div>
              <div class="field"><label for="last">Last name *</label><input id="last" name="last_name" placeholder="Last name"></div>
            </div>
          </div>
          <!--  the feilds the user will see when they select organzation -->
          <div class="group only-org">
            <p class="group-title">Organization</p>
            <div class="grid g-2">
              <div class="field"><label for="org">Organization name *</label><input id="org" name="org_name" placeholder="e.g. Main St. Church"></div>
              <div class="field"><label for="orgContact">Contact person</label><input id="orgContact" name="org_contact" placeholder="Full name"></div>
            </div>
          </div>
          <!-- contact feilds will stay the same despite what the user will pick -->
          <div class="group">
            <p class="group-title">Contact</p>
            <div class="grid g-contact">
              <div class="field"><label for="email">Email</label><input type="email" id="email" name="email" placeholder="name@email.com"></div>
              <div class="field"><label for="phone">Phone</label><input type="tel" id="phone" name="phone" placeholder="(540) 555-0000"></div>
              <div class="field"><label for="pref">Preferred contact</label>
                <select id="pref" name="preferred_contact"><option value="">Choose...</option><option>Email</option><option>Phone</option><option>Mail</option><option>Text</option></select></div>
            </div>
          </div>
          <!-- Address feilds will stay the same despite what the user will pick -->
          <div class="group">
            <p class="group-title">Address</p>
            <div class="grid g-addr">
              <div class="field"><label for="street">Street</label><input id="street" name="street" placeholder="Street address"></div>
              <div class="field"><label for="city">City</label><input id="city" name="city" placeholder="City"></div>
              <div class="field"><label for="state">State</label><select id="state" name="state"><option>VA</option><option>MD</option><option>DC</option><option>WV</option><option>NC</option></select></div>
              <div class="field"><label for="zip">ZIP</label><input id="zip" name="zip" placeholder="ZIP" inputmode="numeric"></div>
            </div>
          </div>
           <!-- prefrence  feilds will stay the same despite what the user will pick also  -->
          <div class="group">
            <p class="group-title">Preferences</p>
            <div class="checks">
              <label><input type="checkbox" name="volunteer"> Also a volunteer</label>
              <label><input type="checkbox" name="email_updates" checked> Send email updates</label>
              <label><input type="checkbox" name="mail_receipts"> Mail tax receipts</label>
              <label><input type="checkbox" name="do_not_contact"> Do not contact</label>
            </div>
          </div>

          <p class="req">* Required</p>
          <div class="spacer"></div>
          <!-- buttons at the bottom of the page for the user to click to cancel making a new user save this new user and move to new page to make the next -->
          <!--  or just if they are only wanting to make 1 is to save current donor -->
          <footer class="form-foot right">
            <a href="donor.php" class="btn btn-outline small">Cancel</a>
            <button type="submit" name="next" value="donation" class="btn btn-outline small">Save &amp; add donation</button>
            <button type="submit" name="next" value="done" class="btn btn-yellow small">Save donor</button>
          </footer>
        </div>
        <!-- feilds that are requierd to filled out will need enforced by the code -->
        <aside class="side">
          <div class="card panel">
            <h3>Tips</h3>
            <p>Only first and last name are required.</p>
            <p>Pick Organization for churches, schools and businesses.</p>
            <p>Use &ldquo;Save &amp; add donation&rdquo; to record a first gift right away.</p>
          </div>
        </aside>
      </form>

<?php fishnet_close(); ?>
