<?php
// The donor form shared by addDonor.php and updateDonor.php. Expects $form (the field array),
// $errors (list of messages), $readOnly (bool), $formAction (string), $buttons (HTML for the footer).
// Stored select values outside the option lists are kept as an extra "Other" choice so a legacy value
// round-trips unchanged.
$stateOptions = DONOR_STATES;
if ($form['state'] !== '' && !in_array($form['state'], $stateOptions, true)) { $stateOptions[] = $form['state']; }
$preferredOptions = array_values(DONOR_PREFERRED_LABELS);
if ($form['preferred'] !== '' && !in_array($form['preferred'], $preferredOptions, true)) { $preferredOptions[] = $form['preferred']; }
?>
        <form class="card form-card" method="post" action="<?= h($formAction) ?>" novalidate>
          <?php echo auth_csrf_field(); ?>
          <?php if (!$readOnly): ?>
          <!-- Hidden default button: pressing Enter in a field saves normally -->
          <button type="submit" name="action" value="save" hidden></button>
          <?php endif; ?>

          <?php if ($readOnly): ?>
          <div class="notice" role="status">Your account can view donors but not change them.</div>
          <?php endif; ?>
          <?php if ($errors): ?>
          <div class="form-errors" role="alert">
            <strong>Please fix the following:</strong>
            <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
          </div>
          <?php endif; ?>

          <fieldset <?= $readOnly ? 'disabled' : '' ?> style="border:0;padding:0;margin:0;min-width:0">
          <div class="form-field">
            <span class="lbl">Donor type</span>
            <div class="seg">
              <input type="radio" name="type" id="typeInd" value="Individual" <?= $form['type'] === 'Individual' ? 'checked' : '' ?>>
              <label for="typeInd">Individual</label>
              <input type="radio" name="type" id="typeOrg" value="Organization" <?= $form['type'] === 'Organization' ? 'checked' : '' ?>>
              <label for="typeOrg">Organization</label>
            </div>
          </div>

          <div>
            <div class="group-title">Name</div>
            <div class="grid g-name only-ind">
              <div class="form-field"><label for="first">First name *</label><input type="text" id="first" name="first" value="<?= h($form['first']) ?>" placeholder="First name"></div>
              <div class="form-field"><label for="middle">Middle</label><input type="text" id="middle" name="middle" value="<?= h($form['middle']) ?>" placeholder="Middle"></div>
              <div class="form-field"><label for="last">Last name *</label><input type="text" id="last" name="last" value="<?= h($form['last']) ?>" placeholder="Last name"></div>
            </div>
            <div class="only-org">
              <div class="form-field"><label for="org_name">Organization name *</label><input type="text" id="org_name" name="org_name" value="<?= h($form['org_name']) ?>" placeholder="Organization name"></div>
            </div>
          </div>

          <div>
            <div class="group-title">Contact</div>
            <div class="grid g-contact">
              <div class="form-field"><label for="email">Email</label><input type="email" id="email" name="email" value="<?= h($form['email']) ?>" placeholder="name@email.com"></div>
              <div class="form-field"><label for="phone">Phone</label><input type="tel" id="phone" name="phone" value="<?= h($form['phone']) ?>" placeholder="(540) 555-0000"></div>
              <div class="form-field">
                <label for="preferred">Preferred contact</label>
                <select id="preferred" name="preferred">
                  <option value="">Choose&hellip;</option>
                  <?php foreach ($preferredOptions as $o): ?>
                    <option value="<?= h($o) ?>" <?= $form['preferred'] === $o ? 'selected' : '' ?>><?= in_array($o, DONOR_PREFERRED_LABELS, true) ? h($o) : 'Other: ' . h($o) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>

          <div>
            <div class="group-title">Address</div>
            <div class="grid g-addr">
              <div class="form-field"><label for="street">Street</label><input type="text" id="street" name="street" value="<?= h($form['street']) ?>" placeholder="Street address"></div>
              <div class="form-field"><label for="city">City</label><input type="text" id="city" name="city" value="<?= h($form['city']) ?>" placeholder="City"></div>
              <div class="form-field">
                <label for="state">State</label>
                <select id="state" name="state">
                  <option value="">&mdash;</option>
                  <?php foreach ($stateOptions as $s): ?>
                    <option value="<?= h($s) ?>" <?= $form['state'] === $s ? 'selected' : '' ?>><?= in_array($s, DONOR_STATES, true) ? h($s) : 'Other: ' . h($s) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-field"><label for="zip">ZIP</label><input type="text" id="zip" name="zip" value="<?= h($form['zip']) ?>" placeholder="ZIP"></div>
            </div>
          </div>

          <div>
            <div class="group-title">Preferences &amp; tags</div>
            <div class="checks">
              <label><input type="checkbox" name="volunteer"      <?= $form['volunteer']      ? 'checked' : '' ?>> Also a volunteer</label>
              <label><input type="checkbox" name="email_updates"  <?= $form['email_updates']  ? 'checked' : '' ?>> Send email updates</label>
              <label><input type="checkbox" name="mail_receipts"  <?= $form['mail_receipts']  ? 'checked' : '' ?>> Mail tax receipts</label>
              <label><input type="checkbox" name="do_not_contact" <?= $form['do_not_contact'] ? 'checked' : '' ?>> Do not contact</label>
            </div>
          </div>
          </fieldset>

          <p class="req">* Required</p>
          <div class="spacer"></div>

          <?php if (!$readOnly): ?>
          <div class="form-foot right">
            <div class="foot-btns"><?= $buttons ?></div>
          </div>
          <?php endif; ?>
        </form>
