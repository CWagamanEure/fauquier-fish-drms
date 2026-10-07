<?php
// css and php to keep the pages consistant
require_once 'include/fishnet-layout.php';
fishnet_open('Donor profile', 'donor', ['donor.css', 'forms.css']);
?>

      <p class="crumbs"><a href="donor.php">Donor</a> &rsaquo; <strong>Jhon D. Rangel Duran</strong></p>

      <section class="card profile-head">
        <span class="big-initials">JR</span>
        <div class="who">
          <h1>Jhon D. Rangel Duran</h1>
          <p>(540) 555-0173 &middot; jrangel@email.com &middot; Locust Grove, VA</p>
          <p>Donor since 2021 &middot; Prefers email &middot; Last contact Sep 28, 2026</p>
        </div>
        <a href="newDonor.php" class="btn btn-yellow">&#9998; Update profile</a>
      </section>
<!-- cards and titles for the card NEED A CONNECTION (FAKE DATA HERE) -->
      <section class="tiles">
        <div class="tile"><p class="label">Lifetime cash</p><p class="num">$11,250.74</p></div>
        <div class="tile"><p class="label">Items donated</p><p class="num">803</p></div>
        <div class="tile"><p class="label">Volunteer hours</p><p class="num">42</p></div>
        <div class="tile"><p class="label">Last gift</p><p class="num">Oct 3, 2026</p></div>
      </section>
      
      <section class="card tabs-card">
        <!-- Tabs: plain radio buttons, no JavaScript needed -->
        <input type="radio" name="tab" id="tCash" checked>
        <input type="radio" name="tab" id="tItems">
        <input type="radio" name="tab" id="tVol">
        <input type="radio" name="tab" id="tComm">
<!--  title for the tabs -->
        <div class="tab-bar">
          <label for="tCash">Cash <span class="count">3</span></label>
          <label for="tItems">Non-cash <span class="count">2</span></label>
          <label for="tVol">Volunteer <span class="count">7</span></label>
          <label for="tComm">Communications <span class="count">1</span></label>
        </div>

        <!-- Cash -->
        <div class="panel-tab p-cash">
          <div class="tab-actions"><a href="addDonation.php" class="btn btn-outline pill-btn">+ Add cash gift</a></div>
          <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>Amount</th><th>Payment</th><th>Campaign</th><th>Receipt</th><th></th></tr></thead>
            <tbody>
              <tr><td>Oct 3, 2026</td><td><strong>$10,000.74</strong></td><td>Check #1042</td><td>Year-End Giving</td><td><span class="status active">Sent</span></td><td><a href="#" class="edit">Edit</a></td></tr>
              <tr><td>Aug 1, 2026</td><td><strong>$1,000.00</strong></td><td>Online</td><td>General Fund</td><td><span class="status active">Sent</span></td><td><a href="#" class="edit">Edit</a></td></tr>
              <tr><td>Jun 1, 2026</td><td><strong>$250.00</strong></td><td>Dogecoin</td><td>Thanksgiving Food Drive</td><td><span class="status pending">Pending</span></td><td><a href="#" class="edit">Edit</a></td></tr>
            </tbody>
            <tfoot><tr><td>Total cash</td><td>$11,250.74</td><td colspan="4" class="muted">3 gifts &middot; avg $3,750</td></tr></tfoot>
          </table></div>
          <div class="pager"><p>Showing 1&ndash;3 of 3 cash gifts</p><div class="pages"><a href="#" class="pg wide">&lsaquo; Previous</a><a href="#" class="pg on">1</a><a href="#" class="pg wide">Next &rsaquo;</a></div></div>
        </div>

        <!-- Non-cash -->
        <div class="panel-tab p-items">
          <div class="tab-actions"><a href="addDonation.php" class="btn btn-outline pill-btn">+ Add non-cash gift</a></div>
          <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>Quantity</th><th>Item</th><th>Est. value</th><th>Campaign</th><th>Receipt</th><th></th></tr></thead>
            <tbody>
              <tr><td>Aug 1, 2026</td><td><strong>800</strong></td><td>Pumpkins</td><td>$2,400.09</td><td>&mdash;</td><td><span class="status active">Sent</span></td><td><a href="#" class="edit">Edit</a></td></tr>
              <tr><td>Nov 27, 2025</td><td><strong>3</strong></td><td>Turkeys</td><td>$100.80</td><td>Thanksgiving Food Drive</td><td><span class="status pending">Pending</span></td><td><a href="#" class="edit">Edit</a></td></tr>
            </tbody>
            <tfoot><tr><td>Totals</td><td>803</td><td></td><td>$2,500.89</td><td colspan="3"></td></tr></tfoot>
          </table></div>
          <div class="pager"><p>Showing 1&ndash;2 of 2 non-cash gifts</p><div class="pages"><a href="#" class="pg wide">&lsaquo; Previous</a><a href="#" class="pg on">1</a><a href="#" class="pg wide">Next &rsaquo;</a></div></div>
        </div>

        <!-- Volunteer -->
        <div class="panel-tab p-vol">
          <div class="tab-actions"><a href="#" class="btn btn-outline pill-btn">+ Log hours</a></div>
          <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>Hours</th><th>Job</th><th>Campaign</th><th></th></tr></thead>
            <tbody>
              <tr><td>Oct 3, 2026</td><td><strong>4</strong></td><td>Line Cook</td><td>Year-End Giving</td><td><a href="#" class="edit">Edit</a></td></tr>
              <tr><td>Aug 1, 2026</td><td><strong>12</strong></td><td>Handing out Food</td><td>General Fund</td><td><a href="#" class="edit">Edit</a></td></tr>
              <tr><td>Jun 1, 2026</td><td><strong>5</strong></td><td>Unloading Food</td><td>Thanksgiving Food Drive</td><td><a href="#" class="edit">Edit</a></td></tr>
              <tr><td>Jun 1, 2026</td><td><strong>4</strong></td><td>Giving Good Vibes</td><td>Thanksgiving Food Drive</td><td><a href="#" class="edit">Edit</a></td></tr>
            </tbody>
            <tfoot><tr><td>Total hours</td><td>42</td><td colspan="3" class="muted">6 hours served on avg</td></tr></tfoot>
          </table></div>
          <div class="pager"><p>Showing 1&ndash;4 of 7 volunteer shifts</p><div class="pages"><a href="#" class="pg wide">&lsaquo; Previous</a><a href="#" class="pg on">1</a><a href="#" class="pg">2</a><a href="#" class="pg wide">Next &rsaquo;</a></div></div>
        </div>

        <!-- Communications -->
        <div class="panel-tab p-comm">
          <div class="tab-actions"><a href="logCommunication.php" class="btn btn-outline pill-btn">+ Log communication</a></div>
          <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>Type</th><th>Subject line</th><th>Campaign</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr><td>Dec 30, 2025</td><td><strong>Email</strong></td><td>[your] 2025 giving wrapped</td><td>End of Year Thanks</td><td><span class="status active">Sent</span></td><td><a href="#" class="edit">Edit</a></td></tr>
            </tbody>
          </table></div>
          <div class="pager"><p>Showing 1&ndash;1 of 1 communications</p><div class="pages"><a href="#" class="pg wide">&lsaquo; Previous</a><a href="#" class="pg on">1</a><a href="#" class="pg wide">Next &rsaquo;</a></div></div>
        </div>
      </section>

<?php fishnet_close(); ?>
