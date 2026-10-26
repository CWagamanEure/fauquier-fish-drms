// Donor form: "+ Add contact person" adds another contact row; the x removes one (the last row is cleared instead).
(function () {
  'use strict';
  var list = document.getElementById('contactList');
  var add = document.getElementById('addContact');
  if (!list || !add) { return; }
  var next = list.querySelectorAll('.contact-row').length;

  add.addEventListener('click', function () {
    var row = list.querySelector('.contact-row').cloneNode(true);
    Array.prototype.forEach.call(row.querySelectorAll('input'), function (input) {
      if (input.type === 'radio') {
        input.value = String(next);
        input.checked = false;
      } else {
        input.value = '';
        input.name = input.name.replace(/contacts\[\d+\]/, 'contacts[' + next + ']');
        input.id = input.id.replace(/^contact\d+_/, 'contact' + next + '_');
      }
    });
    Array.prototype.forEach.call(row.querySelectorAll('label[for]'), function (label) {
      label.htmlFor = label.htmlFor.replace(/^contact\d+_/, 'contact' + next + '_');
    });
    next++;
    list.appendChild(row);
    row.querySelector('input').focus();
  });

  list.addEventListener('click', function (e) {
    if (!e.target.classList.contains('remove-contact')) { return; }
    var rows = list.querySelectorAll('.contact-row');
    var row = e.target.closest('.contact-row');
    if (rows.length === 1) {
      Array.prototype.forEach.call(row.querySelectorAll('input:not([type=radio])'), function (i) { i.value = ''; });
      return;
    }
    var wasMain = row.querySelector('input[type=radio]').checked;
    row.remove();
    if (wasMain) { list.querySelector('input[type=radio]').checked = true; }
  });
})();
