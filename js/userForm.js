// Add / edit user: when the role changes, show that role's default permissions. On an existing account
// the server resets the rows to a new role's presets, so the boxes are disabled until the stored role is
// chosen again; that restores the boxes as loaded when they were drawn for the stored role, otherwise
// (an error page re-rendered for another role) it shows the stored role's presets, editable, and points
// preset_role at the stored role so the server keeps the admin's boxes. On a new account the boxes stay
// editable and preset_role follows the select. Nothing here is trusted by the server.
(function () {
  const role = document.getElementById('role');
  const table = document.getElementById('permTable');
  const presetRole = document.querySelector('input[name=preset_role]');
  const data = document.getElementById('permPresets');
  if (!role || !table || !data) { return; }
  const presets = JSON.parse(data.textContent);
  const isNew = table.dataset.isNew === '1';
  const stored = isNew ? null : table.dataset.storedRole;
  const drawnFor = presetRole ? presetRole.value : role.value;   // the role the boxes were rendered for
  const boxes = Array.from(table.querySelectorAll('input[type=checkbox]'));
  const loaded = new Map(boxes.map((box) => [box.name, box.checked]));
  const key = /perm\[(\w+)\]\[(\w+)\]/;

  role.addEventListener('change', () => {
    const preset = presets[role.value];
    if (!preset) { return; }
    const changed = !isNew && role.value !== stored;
    const restore = !isNew && !changed && drawnFor === stored;
    boxes.forEach((box) => {
      const m = key.exec(box.name);
      if (!m) { return; }
      box.checked = restore ? loaded.get(box.name) : preset[m[1]][m[2]] === 1;
      if (!isNew) { box.disabled = changed || (m[1] === 'users' && box.dataset.locked === '1'); }
    });
    if (presetRole && (isNew || !changed)) { presetRole.value = role.value; }
  });
})();
