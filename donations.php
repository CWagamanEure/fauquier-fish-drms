<?php

require_once 'include/fishnet-layout.php';
fishnet_open('Donations', 'donations', 'donor.css');
?>
<!-- html is for the donations main page where it list all donations cash and non cash bbut not volunteer becasue hours are not reported as donations to the IRS -->
      <section class="page-head">
        <!-- title for the page -->
        <div>
          <p class="crumbs">Donations</p>
          <h1>Donations</h1>
        </div>
        <!-- new donation button -->
        <a href="addDonation.php" class="btn btn-yellow">+ New donation</a>
      </section>
      <!-- title for the 3 cards on this page -->
       <!-- these will need code and real connections to the data base these are FAKE data -->
      <section class="tiles three">
        <div class="tile"><p class="label">Cash this month</p><p class="num">$12,530</p></div>
        <div class="tile"><p class="label">Non-cash items this month</p><p class="num">342 items</p></div>
        <div class="tile"><p class="label">Donations recorded</p><p class="num">214</p></div>
      </section>

      <section class="card table-card donations">
        <!-- Filter buttons -->
        <input type="radio" name="kind" id="fAll" checked>
        <input type="radio" name="kind" id="fCash">
        <input type="radio" name="kind" id="fItems">
        <!-- pill are the little oval things  -->
        <form class="toolbar" method="get">
          <div class="pills">
            <label for="fAll" class="pill">All</label>
            <label for="fCash" class="pill">Cash</label>
            <label for="fItems" class="pill">Non-cash</label>
          </div>
          <!--  this is the search button -->
          <div class="tools">
            <input type="search" name="q" placeholder="Search donor or campaign...">
            <select name="range">
              <!-- this will need real code but filters what donations are shown -->
              <option>Last 30 days</option>
              <option>Last 90 days</option>
              <option>This year</option>
              <option>All time</option>
            </select>
          </div>
        </form>
<!-- this will need code and connections becasue all of these are FAKE data -->
        <div class="table-wrap">
          <table>
            <thead>
              <tr><th>Date</th><th>Donor</th><th>Type</th><th>Amount / Items</th><th>Campaign</th><th>Recorded by</th><th></th><th></th></tr>
            </thead>
            <tbody>
              <tr class="row-noncash">
                <td>Oct 3</td>
                <td><strong>Main St. Church</strong></td>
                <td><span class="kind noncash">Non-cash</span></td>
                <td>320 cans &middot; est. $500</td>
                <td>Thanksgiving Food Drive</td>
                <td>Charity C.</td>
                <td><a href="addDonation.php" class="edit">Edit</a></td>
                <td><a href="viewDonation.php" class="select">View</a></td>
              </tr>
              <tr class="row-cash">
                <td>Oct 3</td>
                <td><strong>J. Miller</strong></td>
                <td><span class="kind cash">Cash</span></td>
                <td>$250.00 &middot; Check</td>
                <td>Thanksgiving Food Drive</td>
                <td>Charity C.</td>
                <td><a href="addDonation.php" class="edit">Edit</a></td>
                <td><a href="viewDonation.php" class="select">View</a></td>
              </tr>
              <tr class="row-noncash">
                <td>Oct 2</td>
                <td><strong>Oak Ridge Elementary</strong></td>
                <td><span class="kind noncash">Non-cash</span></td>
                <td>58 boxes &middot; est. $290</td>
                <td>Thanksgiving Food Drive</td>
                <td>Dana P.</td>
                <td><a href="addDonation.php" class="edit">Edit</a></td>
                <td><a href="viewDonation.php" class="select">View</a></td>
              </tr>
              <tr class="row-cash">
                <td>Oct 2</td>
                <td><strong>R. Patel</strong></td>
                <td><span class="kind cash">Cash</span></td>
                <td>$100.00 &middot; Online</td>
                <td>Year-End Giving</td>
                <td>Charity C.</td>
                <td><a href="addDonation.php" class="edit">Edit</a></td>
                <td><a href="viewDonation.php" class="select">View</a></td>
              </tr>
              <tr class="row-cash">
                <td>Oct 1</td>
                <td><strong>Warrenton Rotary</strong></td>
                <td><span class="kind cash">Cash</span></td>
                <td>$1,500.00 &middot; Check</td>
                <td>General Fund</td>
                <td>Treasurer</td>
                <td><a href="addDonation.php" class="edit">Edit</a></td>
                <td><a href="viewDonation.php" class="select">View</a></td>
              </tr>
              <tr class="row-cash">
                <td>Sep 30</td>
                <td><strong>L. Chen</strong></td>
                <td><span class="kind cash">Cash</span></td>
                <td>$75.00 &middot; Cash</td>
                <td>General Fund</td>
                <td>Dana P.</td>
                <td><a href="addDonation.php" class="edit">Edit</a></td>
                <td><a href="viewDonation.php" class="select">View</a></td>
              </tr>
              <tr class="row-noncash">
                <td>Sep 29</td>
                <td><strong>Fauquier Thrift</strong></td>
                <td><span class="kind noncash">Non-cash</span></td>
                <td>42 coats &middot; est. $630</td>
                <td>Winter Coat Collection</td>
                <td>Dana P.</td>
                <td><a href="addDonation.php" class="edit">Edit</a></td>
                <td><a href="viewDonation.php" class="select">View</a></td>
              </tr>
              <tr class="row-cash">
                <td>Sep 28</td>
                <td><strong>M. Alvarez</strong></td>
                <td><span class="kind cash">Cash</span></td>
                <td>$40.00 &middot; Card</td>
                <td>General Fund</td>
                <td>Charity C.</td>
                <td><a href="addDonation.php" class="edit">Edit</a></td>
                <td><a href="viewDonation.php" class="select">View</a></td>
              </tr>
            </tbody>
          </table>
        </div>
<!-- this will need real code these button do NOT work -->
        <div class="pager">
          <p>Showing 1&ndash;8 of 214 donations</p>
          <div class="pages">
            <a href="#" class="pg wide">&lsaquo; Previous</a>
            <a href="#" class="pg on">1</a><a href="#" class="pg">2</a><a href="#" class="pg">3</a>
            <span>&hellip;</span>
            <a href="#" class="pg">27</a>
            <a href="#" class="pg wide">Next &rsaquo;</a>
          </div>
        </div>
      </section>

<?php fishnet_close(); ?>
