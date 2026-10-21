<?php
// Account + role + permissions fieldsets shared by addUser.php and editUser.php. Expects $form (first,
// last, email, role, and username for a new account), $matrix (area => action => 0|1), $errors,
// $readOnly (bool), $isNew (bool), $lockRole (bool: the signed-in user's own account), $notice (string),
// $storedRole (the account's current role; edit only).
?>
          <?php if ($notice !== ''): ?>
          <div class="notice" role="status"><?= h($notice) ?></div>
          <?php endif; ?>
          <?php if ($errors): ?>
          <div class="form-errors" role="alert">
            <strong>Please fix the following:</strong>
            <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
          </div>
          <?php endif; ?>

          <fieldset <?= $readOnly ? 'disabled' : '' ?> class="plain">
          <div class="group">
            <p class="group-title">Account</p>
            <div class="grid g-2">
              <div class="field"><label for="fn">First name *</label><input id="fn" name="first" value="<?= h($form['first']) ?>" maxlength="100" required></div>
              <div class="field"><label for="ln">Last name *</label><input id="ln" name="last" value="<?= h($form['last']) ?>" maxlength="100" required></div>
              <?php if ($isNew): ?>
              <div class="field"><label for="un">Username *</label><input id="un" name="username" value="<?= h($form['username']) ?>" pattern="[A-Za-z0-9._-]{3,64}" title="3 to 64 letters, digits, dots, underscores or hyphens" autocomplete="off" required></div>
              <?php else: ?>
              <div class="field"><label for="un">Username *</label><input id="un" value="<?= h($form['username']) ?>" readonly></div>
              <?php endif; ?>
              <div class="field"><label for="em">Work email *</label><input type="email" id="em" name="email" value="<?= h($form['email']) ?>" maxlength="255" required></div>
            </div>
          </div>

          <?php if ($isNew): ?>
          <div class="group">
            <p class="group-title">Initial password</p>
            <div class="grid g-2">
              <div class="field"><label for="pw1">Password *</label><input type="password" id="pw1" name="new_password" autocomplete="new-password" minlength="<?= FISH_USER_PASSWORD_MIN ?>" required></div>
              <div class="field"><label for="pw2">Confirm password *</label><input type="password" id="pw2" name="confirm_password" autocomplete="new-password" required></div>
            </div>
          </div>
          <?php endif; ?>

          <div class="group">
            <p class="group-title">Role</p>
            <div class="grid g-2">
              <div class="field"><label for="role">Role</label>
                <select id="role" name="role" <?= $lockRole ? 'disabled' : '' ?>>
                  <?php foreach (FISH_USER_ROLES as $value => $label): ?>
                  <option value="<?= $value ?>"<?= $form['role'] === $value ? ' selected' : '' ?><?= $value === 'admin' && !users_current_is_admin() && $form['role'] !== 'admin' ? ' disabled' : '' ?>><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <input type="hidden" name="preset_role" value="<?= h($form['role']) ?>">
          </div>

          <div class="group">
            <p class="group-title">Permissions</p>
            <div class="table-wrap"><table class="perms" id="permTable" data-is-new="<?= $isNew ? '1' : '0' ?>" data-stored-role="<?= h($isNew ? '' : $storedRole) ?>">
              <thead><tr><th>Area</th><th>View</th><th>Add / edit</th><th>Delete</th></tr></thead>
              <tbody>
                <?php foreach (FISH_USER_AREAS as $area): ?>
                <tr><td><?= h(user_area_label($area)) ?></td>
                  <?php foreach (FISH_PERMISSION_ACTIONS as $action):
                    $never = in_array($action, FISH_USER_NEVER[$area] ?? [], true);
                    // Locked cells are not posted. A changed role on an error page draws the new role's
                    // presets disabled: the server writes those and ignores any box. data-locked (kept
                    // disabled by the JavaScript whatever the select says) follows the stored account:
                    // own account, or an admin whose users row the server forces to the preset.
                    $locked = ($area === 'users' && ($lockRole || $form['role'] === 'admin'))
                      || (!$isNew && $form['role'] !== $storedRole);
                    $keepLocked = $area === 'users' && ($lockRole || (!$isNew && $storedRole === 'admin')); ?>
                  <td><?php if ($never): ?>&mdash;<?php else: ?>
                    <input type="checkbox" name="perm[<?= $area ?>][<?= $action ?>]" aria-label="<?= h(user_area_label($area) . ' ' . $action) ?>"
                           <?= !empty($matrix[$area][$action]) ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?><?= $keepLocked ? ' data-locked="1"' : '' ?>>
                  <?php endif; ?></td>
                  <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table></div>
          </div>
          </fieldset>
          <script type="application/json" id="permPresets"><?= users_presets_json() ?></script>
