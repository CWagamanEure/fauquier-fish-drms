// Live filtering for server-side list pages: a form marked data-live reloads shortly after typing in its search
// box, and at once when a dropdown or date changes. The search box keeps focus after the reload.
(function () {
  'use strict';
  var KEY = 'fishnet.liveSearchFocus';

  Array.prototype.forEach.call(document.querySelectorAll('form[data-live]'), function (form) {
    var timer = null;
    var send = function (field) {
      clearTimeout(timer);
      if (field) { try { window.sessionStorage.setItem(KEY, field.name); } catch (e) { /* storage blocked */ } }
      // The hidden default button (donor.php) carries the active filter; submit through it so the filter survives.
      var def = form.querySelector('button[type="submit"][hidden]');
      if (form.requestSubmit) { if (def) { form.requestSubmit(def); } else { form.requestSubmit(); } }
      else { form.submit(); }
    };
    Array.prototype.forEach.call(form.querySelectorAll('input[type="search"]'), function (box) {
      box.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { send(box); }, 350);
      });
    });
    Array.prototype.forEach.call(form.querySelectorAll('select, input[type="date"]'), function (el) {
      el.addEventListener('change', function () { send(null); });
    });
  });

  var name = null;
  try { name = window.sessionStorage.getItem(KEY); window.sessionStorage.removeItem(KEY); } catch (e) { /* ignore */ }
  if (name) {
    var box = document.querySelector('form[data-live] input[name="' + name + '"]');
    if (box) {
      var v = box.value;
      box.focus();
      try { box.setSelectionRange(v.length, v.length); } catch (e) { /* ignore */ }
    }
  }
})();