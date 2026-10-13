<?php
// Front-end: sample data is hard-coded below; replace it with database values.
require_once 'include/fishnet-layout.php';
fishnet_open('Add donation', 'donations', 'donation.css');
?>

      <section class="page-head">
        <!-- title for the page -->
        <div>
          <p class="crumbs"><a href="donations.php">Donations</a> &rsaquo; <strong>Add donation</strong></p>
          <h1>Add donation</h1>
        </div>
      </section>

      <!-- white space for the form -->
      <form class="donation-layout" method="post" id="donationForm">

        <div class="card form-card">

          <!--  title for Donor field -->
          <fieldset class="step">
            <legend>1 &middot; Donor</legend>
            <input type="hidden" name="donor" id="donorId">

            <div class="donor-box" id="donorSearchBox">
              <input type="text" id="donorSearch" placeholder="Search donors by name..." autocomplete="off">
              <!-- need code  to make this work to slect from exsisting or make a new donor-->
              <a href="newDonor.php" class="link">+Add</a>
              <ul class="results" id="donorResults" hidden></ul>
            </div>
            <!-- for test we need to type jhon for this to werk-->
            <div class="donor-box chosen" id="donorChosen" hidden>
              <span class="initials" id="chosenInitials"></span>
              <div>
                <strong id="chosenName"></strong>
                <p class="meta" id="chosenMeta"></p>
              </div>
              <button type="button" class="link" id="changeDonor">Change</button>
            </div>
          </fieldset>

          <!-- title for donation type feilds-->
          <fieldset class="step" id="stepType" disabled>
            <legend>2 &middot; Donation type</legend>
            <div class="choices">
              <!-- two type cash and no cash and this is where the user will select this  -->
              <label class="choice">
                <input type="radio" name="kind" value="cash">
                <span><strong>Cash</strong><small>Check, cash, card, online</small></span>
              </label>
              <label class="choice">
                <input type="radio" name="kind" value="noncash">
                <span><strong>Non-cash (items)</strong><small>Food, clothing, goods</small></span>
              </label>
            </div>
          </fieldset>

          <!-- 3a. Cash details -->
          <fieldset class="step details" id="cashDetails" hidden>
            <!-- this will need connection becasue right now it will add no where -->
            <legend>3 &middot; Donation details</legend>
            <div class="grid">
              <div class="field span2"><label for="amount">Amount</label><input type="number" id="amount" name="amount" step="0.01" min="0" placeholder="$0.00"></div>
              <div class="field"><label for="payType">Type</label>
                <select id="payType" name="pay_type"><option>Check</option><option>Cash</option><option>Card</option><option>Online</option></select></div>
              <div class="field" id="checkNumField"><label for="checkNum">Check Number</label><input type="text" id="checkNum" name="check_number"></div>

              <div class="field" id="signedField"><label for="signed">Signed</label>
                <select id="signed" name="signed"><option>Yes</option><option>No</option></select></div>
              <div class="field"><label for="cashDate">Date received</label><input type="date" id="cashDate" name="date_received" value="2026-10-06"></div>
              <div class="field" id="depositField"><label for="depositDate">Date to be deposited</label><input type="date" id="depositDate" name="deposit_date"></div>
              <div></div>

              <div class="field span2"><label for="cashCampaign">Campaign</label>
                <select id="cashCampaign" name="campaign" class="campaign-select">
<option>Thanksgiving Food Drive</option><option>Winter Coat Drive</option><option>General Fund</option>                </select></div>
              <div class="field span2"><label for="cashNotes">Notes</label><input type="text" id="cashNotes" name="notes"></div>
            </div>
          </fieldset>

          <!-- 3b. Item details -->
          <fieldset class="step details" id="itemDetails" hidden>
            <legend>3 &middot; Item details</legend>
            <div class="grid">
              <div class="field span2"><label for="itemDesc">Item description</label><input type="text" id="itemDesc" name="item_description" placeholder="e.g. Canned vegetables &amp; soup"></div>
              <div class="field"><label for="category">Category</label>
                <select id="category" name="category"><option>Canned food</option><option>Dry goods</option><option>Fresh produce</option><option>Clothing</option><option>Household items</option><option>Hygiene products</option><option>Other</option></select></div>
              <div class="field"><label for="quantity">Quantity</label><input type="number" id="quantity" name="quantity" min="1" placeholder="e.g. 320"></div>
              <!-- this will need connection becasue right now it will add no where -->
              <div class="field"><label for="estValue">Estimated value</label><input type="number" id="estValue" name="estimated_value" step="0.01" min="0" placeholder="$0.00"></div>
              <div class="field"><label for="itemDate">Date received</label><input type="date" id="itemDate" name="date_received" value="2026-10-06"></div>
              <div class="field"><label for="condition">Condition</label>
                <select id="condition" name="condition"><option>New / unopened</option><option>Gently used</option><option>Used</option></select></div>
              <div></div>
              <!-- this will need connection becasue right it does not conneect to campigns theses are fake ones -->
              <div class="field span2"><label for="itemCampaign">Intended use</label>
                <select id="itemCampaign" name="campaign" class="campaign-select">
<option>Thanksgiving Food Drive</option><option>Winter Coat Drive</option><option>General Fund</option>                </select></div>
              <div class="field span2"><label for="itemNotes">Notes</label><input type="text" id="itemNotes" name="notes"></div>
            </div>
          </fieldset>

          <div class="spacer"></div>
          <!-- check box if the donor want a recipt  -->
          <footer class="form-foot">
            <label class="check"><input type="checkbox" name="send_receipt" checked> Email a thank-you &amp; tax receipt</label>
            <div class="foot-btns">
              <a href="donations.php" class="btn btn-outline small">Cancel</a>
              <button type="submit" name="next" value="another" class="btn btn-outline small" id="saveAnother" disabled>Save &amp; add another</button>
              <button type="submit" name="next" value="done" class="btn btn-yellow small" id="saveBtn" disabled>Save donation</button>
            </div>
          </footer>
        </div>

        <!-- Side panel -->
        <aside class="side">
          <div class="card summary" id="donorSummary" hidden>
            <h3 id="sumName"></h3>
            <!--  this will need code and a connection to the data base this information is fake for example -->
            <dl>
              <dt>Lifetime cash</dt><dd id="sumCash"></dd>
              <dt>Lifetime items</dt><dd id="sumItems"></dd>
              <dt>Last gift</dt><dd id="sumLast"></dd>
              <dt>Volunteers linked</dt><dd id="sumVol"></dd>
            </dl>
            <!-- this will need code to take the user to this persons profile in a new tab -->
            <a href="#" id="sumProfile" class="link">View full profile &rarr;</a>
          </div>
          <!-- this will need code and a connection this is not real data  -->
          <div class="card campaign" id="campaignCard" hidden>
            <p class="ct-title">Counts toward</p>
            <p class="ct-name" id="ctName"></p>
            <div class="bar"><div class="fill" id="ctFill"></div></div>
            <p class="ct-text" id="ctText"></p>
          </div>
        </aside>

      </form>

<script>
const DONORS = {"main-st-church":{"name":"Main St. Church","type":"Organization","town":"Warrenton, VA","since":2019,"cash":4250,"items":1870,"lastGift":"Aug 12, 2026","volunteers":6},"jhon-rangel":{"name":"Jhon D. Rangel Duran","type":"Individual","town":"Warrenton, VA","since":2021,"cash":11250.74,"items":40,"lastGift":"Oct 3, 2026","volunteers":0},"oak-ridge":{"name":"Oak Ridge Elementary","type":"Organization","town":"Bealeton, VA","since":2022,"cash":800,"items":610,"lastGift":"Oct 2, 2026","volunteers":3},"warrenton-rotary":{"name":"Warrenton Rotary","type":"Organization","town":"Warrenton, VA","since":2017,"cash":9500,"items":0,"lastGift":"Oct 1, 2026","volunteers":12}};
const CAMPAIGNS = {"Thanksgiving Food Drive":[2000,1240],"Winter Coat Drive":[500,120],"General Fund":[0,0]};
</script>
<script src="js/addDonation.js"></script>

<?php fishnet_close(); ?>
