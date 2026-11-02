// Campaign pages (front end). Adds: client-side filter pills and search on the lists, the live preview
// on the add/edit form, a date check before submit, and remembering the open tab on campaign.php.
(function () {
  'use strict';

  var TYPE_LABELS = { cash: 'Cash', items: 'Items', both: 'Cash + items', volunteers: 'Volunteers' };

  // ---------- filter pills + search (campaigns.php list, campaign.php gifts) ----------
  // Rows carry data-status; pills carry data-filter ("all" shows everything). Backend can swap this for
  // server-side filtering later without changing the markup.
  Array.prototype.forEach.call(document.querySelectorAll('[data-filter-list]'), function (box) {
    var pills = box.querySelectorAll('[data-filter]');
    var search = box.querySelector('[data-search]');
    var rows = box.querySelectorAll('tbody tr[data-status]');
    var empty = box.querySelector('tr.no-match');
    var shown = box.querySelector('[data-shown]');
    var current = (box.querySelector('[data-filter].on') || {}).getAttribute ? box.querySelector('[data-filter].on').getAttribute('data-filter') : 'all';

    var apply = function () {
      var q = search ? search.value.trim().toLowerCase() : '';
      var n = 0;
      Array.prototype.forEach.call(rows, function (r) {
        var ok = (current === 'all' || r.getAttribute('data-status') === current)
              && (!q || (r.getAttribute('data-name') || r.textContent.toLowerCase()).indexOf(q) !== -1);
        r.hidden = !ok;
        if (ok) { n++; }
      });
      if (empty) { empty.hidden = n !== 0; }
      if (shown) { shown.textContent = n; }
    };
    Array.prototype.forEach.call(pills, function (p) {
      p.addEventListener('click', function () {
        Array.prototype.forEach.call(pills, function (o) { o.classList.toggle('on', o === p); });
        current = p.getAttribute('data-filter');
        apply();
      });
    });
    if (search) { search.addEventListener('input', apply); }
    apply();
  });

  // ---------- addCampaign.php ----------
  var form = document.getElementById('campaignForm');
  if (form) {
    var panel = document.querySelector('.preview-panel');
    var $ = function (id) { return document.getElementById(id); };

    var fmtDate = function (iso) {
      if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) { return ''; }
      var d = new Date(iso + 'T00:00:00');
      return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    };
    var num = function (v) {
      var n = parseFloat(String(v).replace(/[$,\s]/g, ''));
      return isFinite(n) && n > 0 ? n : null;
    };
    var goalText = function (prefix, goal, suffix) {
      return prefix + '0 of ' + (goal === null ? '—' : prefix + goal.toLocaleString('en-US')) + (suffix ? ' ' + suffix : '');
    };

    var update = function () {
      var checked = form.querySelector('input[name="type"]:checked');
      var type = checked ? checked.value : 'cash';
      $('pvName').textContent = $('name').value.trim() || 'Campaign name';
      $('pvType').textContent = TYPE_LABELS[type] || 'Cash';
      $('pvType').className = 'ctype ' + type;
      var s = fmtDate($('start_date').value), e = fmtDate($('end_date').value);
      $('pvDates').textContent = s || e ? s + ' – ' + e : '';
      $('pvCash').textContent = goalText('$', num($('cash_goal').value));
      $('pvItems').textContent = goalText('', num($('item_goal').value), $('item_unit').value);
      $('pvVol').textContent = goalText('', num($('volunteer_goal').value), 'shifts');
      if (panel) { panel.className = 'card panel preview-panel is-' + type; }
    };

    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();

    // Friendly check before the server one: end date can't be before start date.
    form.addEventListener('submit', function (ev) {
      var s = $('start_date').value, e = $('end_date').value;
      if (s && e && e < s) {
        ev.preventDefault();
        $('end_date').setCustomValidity('End date must be on or after the start date.');
        $('end_date').reportValidity();
      }
    });
    $('end_date').addEventListener('input', function () { this.setCustomValidity(''); });
  }

  // ---------- campaign.php ----------
  // The tabs are CSS radio buttons; remember the open one so paging gifts or reloading keeps it.
  var tabs = document.querySelectorAll('.campaign-tabs > input[name="tab"]');
  if (tabs.length) {
    var KEY = 'fishnet.campaignTab';
    var saved = null;
    try { saved = window.sessionStorage.getItem(KEY); } catch (e) { /* storage blocked: default tab */ }
    if (saved && document.getElementById(saved)) { document.getElementById(saved).checked = true; }
    Array.prototype.forEach.call(tabs, function (t) {
      t.addEventListener('change', function () {
        try { window.sessionStorage.setItem(KEY, t.id); } catch (e) { /* ignore */ }
      });
    });
  }
})();
