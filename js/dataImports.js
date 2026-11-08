// Data & Imports: show the chosen file's name in the drop zone and highlight it while a file is dragged over.
(function () {
  var zone = document.querySelector('.drop-zone');
  if (!zone) { return; }
  var input = zone.querySelector('input[type=file]');
  var text = zone.querySelector('.drop-text');
  var name = zone.querySelector('.drop-file');

  input.addEventListener('change', function () {
    var file = input.files && input.files[0];
    name.textContent = file ? file.name : '';
    name.hidden = !file;
    text.hidden = !!file;
  });
  ['dragenter', 'dragover'].forEach(function (e) {
    zone.addEventListener(e, function () { zone.classList.add('over'); });
  });
  ['dragleave', 'drop'].forEach(function (e) {
    zone.addEventListener(e, function () { zone.classList.remove('over'); });
  });
})();
