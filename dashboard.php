<?php
// this is used to pull the css for the dash and the layout for the website (i.e the logo the title serach bar and profile logo also the side bar and links to the other main pages)
require_once 'include/fishnet-layout.php';
fishnet_open('Dashboard', 'dashboard', 'dashboard.css');
?>
<!-- Html Section -->
      <section class="welcome-row">
        <div>
          <!-- needs connection to what the role of user is -->
          <p class="role">Administrator</p>
          <!-- need connection to what the users name is -->
          <h1>Welcome back, Charity!</h1>
        </div>
        <div class="actions">
          <!-- link to add a new donation -->
          <a href="addDonation.php" class="btn btn-yellow">+Add donation</a>
          <!-- link to add a new donor -->
          <a href="newDonor.php" class="btn btn-outline">+Add Donor</a>
          <!-- not working not in this sprint  (FAKE) -->
          <a href="logCommunication.php" class="btn btn-outline">+Log Communication</a>
        </div>
      </section>

      <section class="stats">
        <div class="card stat">
          <!-- needs connection to know what month it is -->
          <p class="label">Sept Cash Donations</p>
          <!-- needs connection cash.db to what has been donated this month and code to calculate the total -->
          <p class="big">$12,000</p>
          <!-- needs something to know what monht it is and code to compare that month compared to last year -->
          <p class="sub up">&#9651; 8% from last Sep</p>
        </div>
        <div class="card stat">
          <!-- needs connection to know what month it is -->
          <p class="label">Sept Non Cash Donations</p>
          <!-- needs connection to non-Cash db to show how many items have been donated this month -->
          <p class="big">400</p>
          <!-- need connection the non-cash db and code to calculate the total est value  -->
          <p class="sub">Est Value $1,200</p>
        </div>
        <div class="card stat">
          <!-- not in this sprint (FAKE) -->
          <p class="label">Volunteer hours</p>
          <p class="big">512</p>
          <p class="sub">112 Volunteers this month</p>
        </div>
        <div class="card stat alert">
          <!-- not in this sprint (FAKE) -->
          <p class="label">The last Import had</p>
          <p class="big">9</p>
          <p class="sub">Entry conflicts that still need to be resolved</p>
        </div>
      </section>

      <section class="lower">
        <!-- not in this sprint (FAKE) -->
        <div class="card chart-card">
          <h2>Giving Trends</h2>
          <div class="legend">
            <span><i class="sw noncash"></i>Non Cash</span>
            <span><i class="sw cash"></i>Cash</span>
          </div>
          <div class="chart">
            <div class="y-axis">
                              <span>$12,500</span>
                              <span>$10,000</span>
                              <span>$7,500</span>
                              <span>$5,000</span>
                              <span>$2,500</span>
                              <span>$0</span>
                          </div>
            <div class="bars">
                              <div class="bar-col">
                  <div class="bar" title="Cash $5,000 / Non cash $2,000">
                    <div class="seg noncash" style="height: 16%"></div>
                    <div class="seg cash" style="height: 40%"></div>
                  </div>
                  <span class="x-label">Sept 2025</span>
                </div>
                              <div class="bar-col">
                  <div class="bar" title="Cash $300 / Non cash $700">
                    <div class="seg noncash" style="height: 5.6%"></div>
                    <div class="seg cash" style="height: 2.4%"></div>
                  </div>
                  <span class="x-label">Sept 2026</span>
                </div>
                              <div class="bar-col">
                  <div class="bar" title="Cash $10,000 / Non cash $1,000">
                    <div class="seg noncash" style="height: 8%"></div>
                    <div class="seg cash" style="height: 80%"></div>
                  </div>
                  <span class="x-label">Oct 2025</span>
                </div>
                              <div class="bar-col">
                  <div class="bar" title="Cash $1,000 / Non cash $1,000">
                    <div class="seg noncash" style="height: 8%"></div>
                    <div class="seg cash" style="height: 8%"></div>
                  </div>
                  <span class="x-label">Oct 2026</span>
                </div>
                              <div class="bar-col">
                  <div class="bar" title="Cash $500 / Non cash $7,500">
                    <div class="seg noncash" style="height: 60%"></div>
                    <div class="seg cash" style="height: 4%"></div>
                  </div>
                  <span class="x-label">Nov 2025</span>
                </div>
                              <div class="bar-col">
                  <div class="bar" title="Cash $7,500 / Non cash $2,500">
                    <div class="seg noncash" style="height: 20%"></div>
                    <div class="seg cash" style="height: 60%"></div>
                  </div>
                  <span class="x-label">Nov 2026</span>
                </div>
                          </div>
          </div>
          <p class="x-title">Category</p>
        </div>

        <div class="card activity">
          <!-- not in this sprint (FAKE) -->
          <h2>New activity</h2>
          <ul>
            <li class="empty">No new activity yet.</li>
          </ul>
        </div>
      </section>

<?php fishnet_close(); ?>
