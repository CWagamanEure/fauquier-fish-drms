<?php
// Item values: set a dollar value for each non-cash donation category and apply it to every item in that
// category, plus add a new category.
// FRONT END ONLY: nothing is read or saved yet. Backend TODO:
//   - fill $categories from the category list (today the ENUM in non_cash_donations / DONATION_CATEGORY_LABELS;
//     adding categories needs a categories table) with each one's current value, unit and item count
//   - "Apply" posts category + value + unit (+ scope) and sets non_cash_donations.estimated_value for those rows
//   - "Add category" posts name + value + unit and creates the category
require_once 'include/fishnet-layout.php';

// key => label. Placeholder list matching today's categories; counts and values come from the backend.
$categories = [
  'canned_food'      => 'Canned food',
  'dry_goods'        => 'Dry goods',
  'fresh_produce'    => 'Fresh produce',
  'clothing'         => 'Clothing',
  'household_items'  => 'Household items',
  'hygiene_products' => 'Hygiene products',
  'other'            => 'Other',
];

fishnet_open('Item values', 'imports', ['donor.css', 'forms.css', 'itemValues.css']);
?>

      <section class="page-head">
        <div>
          <p class="crumbs"><a href="dataImports.php">Data &amp; Imports</a> &rsaquo; <strong>Item values</strong></p>
          <h1>Item values</h1>
          <p class="lede">Set a value for a category and apply it to every non-cash item in it</p>
        </div>
      </section>

      <div class="iv-cols">
        <!-- ===== Category values ===== -->
        <section class="card table-card">
          <h2 class="iv-title">Categories</h2>
          <p class="iv-note">The value is multiplied by each item&rsquo;s quantity or weight to fill in its estimated value.</p>
          <div class="table-wrap">
            <table class="iv-table">
              <thead><tr><th>Category</th><th>Items</th><th>Value</th><th>Per</th><th>Apply to</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($categories as $key => $label): ?>
                <tr>
                  <td><strong><?= h($label) ?></strong><span class="sub">Current: &mdash;</span></td>
                  <td>&mdash;</td>
                  <td><label class="money"><span>$</span><input type="number" form="iv-<?= h($key) ?>" name="value" min="0" step="0.01" placeholder="0.00" required aria-label="<?= h($label) ?> value"></label></td>
                  <td><select form="iv-<?= h($key) ?>" name="unit" aria-label="<?= h($label) ?> per"><option value="item">item</option><option value="lb">lb</option></select></td>
                  <td><select form="iv-<?= h($key) ?>" name="scope" aria-label="<?= h($label) ?> apply to"><option value="all">All items</option><option value="blank">Only blank ones</option></select></td>
                  <td>
                    <form id="iv-<?= h($key) ?>" method="post" action="#">
                      <?= auth_csrf_field() ?>
                      <input type="hidden" name="category" value="<?= h($key) ?>">
                      <button type="submit" class="btn btn-outline iv-btn">Apply</button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>

        <!-- ===== Add category ===== -->
        <aside class="card panel iv-add">
          <h3>Add a category</h3>
          <form method="post" action="#">
            <?= auth_csrf_field() ?>
            <div class="field"><label for="newName">Category name *</label>
              <input type="text" id="newName" name="name" maxlength="60" required placeholder="e.g. Baby supplies"></div>
            <div class="field"><label for="newValue">Value (optional)</label>
              <div class="iv-row">
                <label class="money"><span>$</span><input type="number" id="newValue" name="value" min="0" step="0.01" placeholder="0.00"></label>
                <select name="unit" aria-label="Per"><option value="item">per item</option><option value="lb">per lb</option></select>
              </div>
            </div>
            <button type="submit" class="btn btn-yellow iv-add-btn">+ Add category</button>
          </form>
          <p class="iv-fine">New categories appear in the category list when logging a non-cash donation.</p>
        </aside>
      </div>

<?php fishnet_close(); ?>
