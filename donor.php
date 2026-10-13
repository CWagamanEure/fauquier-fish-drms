<?php
// opens the css and php that keep the pages consistan
require_once 'include/fishnet-layout.php';
fishnet_open('Donors', 'donor', 'donor.css');
?>

      <section class="page-head">
        <div>
          <p class="crumbs">Donor</p>
          <h1>Donors</h1>
          <p class="lede">Everyone who gives to Fauquier FISH &mdash; people and organizations</p>
        </div>
        <a href="newDonor.php" class="btn btn-yellow">+ New donor</a>
      </section>

      <section class="tiles">
        <div class="tile"><p class="label">Total donors</p><p class="num">1,284</p></div>
        <div class="tile"><p class="label">New this month</p><p class="num">19</p></div>
        <div class="tile"><p class="label">Lapsed (12+ months)</p><p class="num">146</p></div>
        <div class="tile warn">
          <p class="label">Possible duplicates</p>
          <div class="row"><p class="num">5</p><a href="dataImports.php">Review &rarr;</a></div>
        </div>
      </section>
<!-- Cards at the top of the page  NEEDS  real Connections  -->
      <section class="card table-card">
        <form class="toolbar" method="get">
          <div class="pills">
            <button type="submit" name="filter" value="all" class="pill on">All</button>
            <button type="submit" name="filter" value="individuals" class="pill">Individuals</button>
            <button type="submit" name="filter" value="organizations" class="pill">Organizations</button>
            <button type="submit" name="filter" value="lapsed" class="pill">Lapsed</button>
          </div>
          <!-- NEEDS Connection to db.donors i think is the right table  -->
          <div class="tools">
            <input type="search" name="q" value="" placeholder="Search name, email or phone...">
            <select name="sort" onchange="this.form.submit()">
              <option>Sort: Last gift</option>
              <option>Sort: Name</option>
              <option>Sort: Lifetime giving</option>
            </select>
          </div>
        </form>
<!-- NEEDS Connection to db.donors i think is the right table this is FAKE data  -->
        <div class="table-wrap">
          <table>
            <thead>
              <tr><th>Name</th><th>Type</th><th>Contact</th><th>Lifetime giving</th><th>Last gift</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
              <tr>
                <td><span class="initials teal">MC</span><strong>Main St. Church</strong></td>
                <td>Organization</td>
                <td>office@mainstchurch.org</td>
                <td>$4,250</td>
                <td>Oct 3, 2026</td>
                <td><span class="status active">Active</span></td>
                <td><a href="donorProfile.php" class="select">Select</a></td>
              </tr>
              <tr>
                <td><span class="initials navy">JD</span><strong>Jhon D. Rangel Duran</strong></td>
                <td>Individual</td>
                <td>(540) 555-0173</td>
                <td>$11,250.74</td>
                <td>Oct 3, 2026</td>
                <td><span class="status active">Active</span></td>
                <td><a href="donorProfile.php" class="select">Select</a></td>
              </tr>
              <tr>
                <td><span class="initials sage">OE</span><strong>Oak Ridge Elementary</strong></td>
                <td>Organization</td>
                <td>pta@oakridge.edu</td>
                <td>$800</td>
                <td>Oct 2, 2026</td>
                <td><span class="status active">Active</span></td>
                <td><a href="donorProfile.php" class="select">Select</a></td>
              </tr>
              <tr>
                <td><span class="initials yellow">RP</span><strong>Ravi Patel</strong></td>
                <td>Individual</td>
                <td>rpatel@email.com</td>
                <td>$640</td>
                <td>Oct 2, 2026</td>
                <td><span class="status active">Active</span></td>
                <td><a href="donorProfile.php" class="select">Select</a></td>
              </tr>
              <tr>
                <td><span class="initials teal">WR</span><strong>Warrenton Rotary</strong></td>
                <td>Organization</td>
                <td>(540) 555-0110</td>
                <td>$9,500</td>
                <td>Oct 1, 2026</td>
                <td><span class="status active">Active</span></td>
                <td><a href="donorProfile.php" class="select">Select</a></td>
              </tr>
              <tr>
                <td><span class="initials navy">LC</span><strong>Lin Chen</strong></td>
                <td>Individual</td>
                <td>lchen@email.com</td>
                <td>$75</td>
                <td>Sep 30, 2026</td>
                <td><span class="status new">New</span></td>
                <td><a href="donorProfile.php" class="select">Select</a></td>
              </tr>
              <tr>
                <td><span class="initials sage">FT</span><strong>Fauquier Thrift</strong></td>
                <td>Organization</td>
                <td>(540) 555-0164</td>
                <td>$2,500</td>
                <td>Sep 29, 2026</td>
                <td><span class="status active">Active</span></td>
                <td><a href="donorProfile.php" class="select">Select</a></td>
              </tr>
              <tr>
                <td><span class="initials yellow">DB</span><strong>Dana Brooks</strong></td>
                <td>Individual</td>
                <td>(540) 555-0129</td>
                <td>$1,050</td>
                <td>Jul 8, 2025</td>
                <td><span class="status lapsed">Lapsed</span></td>
                <td><a href="donorProfile.php" class="select">Select</a></td>
              </tr>
            </tbody>
          </table>
        </div>
<!-- needs to be coded does not actually work  -->
        <div class="pager">
          <p>Showing 1&ndash;8 of 1,284 donors</p>
          <div class="pages">
            <a href="#" class="pg wide">&lsaquo; Previous</a>
            <a href="#" class="pg on">1</a><a href="#" class="pg">2</a><a href="#" class="pg">3</a>
            <span>&hellip;</span>
            <a href="#" class="pg">161</a>
            <a href="#" class="pg wide">Next &rsaquo;</a>
          </div>
        </div>
      </section>

<?php fishnet_close(); ?>
