// Add / edit user: when the role changes, show that role's default permissions. On an existing account
// the server resets the rows to the new role's presets, so the boxes are disabled until the original
// role is chosen again; on a new account they stay editable. Nothing here is trusted by the server.
(function () {
  const role = document.getElementById('role');
  const table = document.getElementById('permTable');
  const note = document.getElementById('permNote');
  const data = document.getElementById('permPresets');
  if (!role || !table || !data) { return; }
  const presets = JSON.parse(data.textContent);
  const original = role.value;
  const isNew = table.dataset.isNew === '1';
  const boxes = Array.from(table.querySelectorAll('input[type=checkbox]'));
  const key = /perm\[(\w+)\]\[(\w+)\]/;

  role.addEventListener('change', () => {
    const preset = presets[role.value];
    if (!preset) { return; }
    const changed = role.value !== original;
    boxes.forEach((box) => {
      const m = key.exec(box.name);
      if (!m) { return; }
      if (changed || isNew) { box.checked = preset[m[1]][m[2]] === 1; }
      if (!isNew) { box.disabled = changed || (m[1] === 'users' && box.dataset.locked === '1'); }
    });
    if (note) { note.textContent = changed && !isNew ? note.dataset.reset : note.dataset.same; }
  });
})();
