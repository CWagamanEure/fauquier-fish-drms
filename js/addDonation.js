// Add / edit donation page: donor search, type switch, check and honoree fields, net amount, side panel
// and campaign card. State is read from the DOM on load, so a server-chosen type, a re-rendered form with
// errors and edit mode all start with the right sections shown.
(function () {
  const $ = (id) => document.getElementById(id);
  const money = (n) => '$' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const initials = (name) => {
    const p = String(name).trim().split(/\s+/).filter(Boolean);
    if (!p.length) return '?';
    return (Array.from(p[0])[0] + (p.length > 1 ? Array.from(p[p.length - 1])[0] : '')).toUpperCase();
  };
  // 'YYYY-MM-DD' from the database -> 'Oct 9, 2026', read as a calendar date (no time-zone shift)
  const longDate = (iso) => new Date(iso + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  const el = (tag, text) => { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; return n; };
  const num = (id) => { const v = Number($(id).value); return $(id).value.trim() !== '' && Number.isFinite(v) ? v : 0; };

  const search = $('donorSearch');
  const results = $('donorResults');
  const checkedKind = document.querySelector('input[name="kind"]:checked');
  let kind = checkedKind ? checkedKind.value : null;

  // ---- 1. Donor search (donorLookup.php, UF2-49) ----
  let timer = null;
  let pending = null;

  function showMessage(text) {
    results.replaceChildren(el('li', text));
    results.firstChild.className = 'none';
    results.hidden = false;
  }

  function showResults(donors) {
    if (!donors.length) {
      showMessage($('donorSearchBox').querySelector('a.link') ? 'No donor found. Use +Add to create one.' : 'No donor found.');
      return;
    }
    results.replaceChildren(...donors.map((d) => {
      const btn = el('button');
      btn.type = 'button';
      btn.append(el('strong', d.name), ' ', el('small', [d.type, d.town].filter(Boolean).join(' · ')));
      btn.addEventListener('click', () => chooseDonor(d));
      const li = el('li');
      li.append(btn);
      return li;
    }));
    results.hidden = false;
  }

  async function lookup(q) {
    if (pending) pending.abort();
    pending = new AbortController();
    try {
      const res = await fetch('donorLookup.php?q=' + encodeURIComponent(q),
        { signal: pending.signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const data = await res.json().catch(() => ({}));
      if (search.value.trim() !== q) return;          // the user kept typing
      if (res.status === 401) { showMessage('Your session has ended. Reload the page to log in again.'); return; }
      if (!res.ok || !data.success) { showMessage(data.error || 'Could not search donors. Please try again.'); return; }
      showResults(data.donors);
    } catch (e) {
      if (e.name !== 'AbortError') showMessage('Could not search donors. Please try again.');
    }
  }

  search.addEventListener('input', () => {
    clearTimeout(timer);
    const q = search.value.trim();
    if (!q) { if (pending) pending.abort(); results.hidden = true; results.replaceChildren(); return; }
    if (q.length < 2) { showMessage('Type at least 2 letters.'); return; }
    timer = setTimeout(() => lookup(q), 250);
  });

  // ---- UF2-53: the side panel follows the chosen donor ----
  function chooseDonor(d) {
    $('donorId').value = d.id;
    $('chosenInitials').textContent = initials(d.name);
    $('chosenName').textContent = d.name;
    $('chosenMeta').textContent = [d.type, d.town, d.since && 'Donor since ' + d.since].filter(Boolean).join(' · ');
    $('donorSearchBox').hidden = true;
    $('donorChosen').hidden = false;
    results.hidden = true;

    $('sumName').textContent = d.name;
    $('sumCash').textContent = money(d.cash);
    $('sumItems').textContent = d.items.toLocaleString() + ' items';
    $('sumLast').textContent = d.last_gift ? longDate(d.last_gift) : 'None yet';
    $('sumVol').textContent = d.hours.toLocaleString();
    $('sumProfile').href = 'donorProfile.php?id=' + encodeURIComponent(d.id);
    $('donorSummary').hidden = false;

    $('stepType').disabled = false;
    refresh();
  }

  $('changeDonor').addEventListener('click', () => {
    $('donorId').value = '';
    $('donorChosen').hidden = true;
    $('donorSearchBox').hidden = false;
    $('donorSummary').hidden = true;
    $('stepType').disabled = true;
    search.value = '';
    search.focus();
    refresh();
  });

  // ---- 2. Donation type (UF2-50) ----
  document.querySelectorAll('input[name="kind"]').forEach((r) =>
    r.addEventListener('change', () => { kind = r.value; refresh(); })
  );

  // Only the visible details section gets submitted
  function showDetails() {
    const cash = $('cashDetails'), items = $('itemDetails');
    cash.hidden = kind !== 'cash';
    items.hidden = kind !== 'noncash';
    cash.disabled = cash.hidden;
    items.disabled = items.hidden;
  }

  // ---- 3. Cash details (UF2-48) ----
  function showCheckFields() {
    const isCheck = $('payType').value === 'check';
    document.querySelectorAll('.check-only').forEach((f) => { f.hidden = !isCheck; });
  }
  function showHonoree() { $('honoreeField').hidden = !$('inHonor').checked; }
  function showNet() {
    const amount = $('amount').value.trim();
    $('net').value = amount === '' ? '' : money(num('amount') - num('fee'));
  }
  $('payType').addEventListener('change', showCheckFields);
  $('inHonor').addEventListener('change', showHonoree);
  ['amount', 'fee'].forEach((id) => $(id).addEventListener('input', () => { showNet(); updateCampaign(); }));

  // ---- Campaign card (UF2-52) ----
  document.querySelectorAll('.campaign-select').forEach((s) => s.addEventListener('change', updateCampaign));
  $('estValue').addEventListener('input', updateCampaign);

  function updateCampaign() {
    const select = kind === 'cash' ? $('cashCampaign') : $('itemCampaign');
    const c = kind ? CAMPAIGNS[select.value] : null;
    if (!c) { $('campaignCard').hidden = true; return; }
    // On edit the stored gift is already in its campaign's totals: take it out before adding the form value.
    const storedHere = STORED && String(STORED.campaign) === select.value;
    $('ctName').textContent = c.name;
    if (kind === 'cash') {
      const total = c.raised - (storedHere ? STORED.value : 0) + num('amount');
      $('ctBar').hidden = !(c.goal > 0);
      $('ctFill').style.width = c.goal > 0 ? Math.min(100, (total / c.goal) * 100) + '%' : '0';
      $('ctText').textContent = c.goal > 0 ? `${money(total)} of ${money(c.goal)} after this gift` : `${money(total)} raised so far`;
    } else {
      const gifts = c.gifts + (storedHere ? 0 : 1);
      const value = c.item_value - (storedHere ? STORED.value : 0) + num('estValue');
      $('ctBar').hidden = true;
      $('ctText').textContent = `${gifts.toLocaleString()} non-cash gift${gifts === 1 ? '' : 's'}, est. ${money(value)}, after this gift`;
    }
    $('campaignCard').hidden = false;
  }

  // ---- Keep everything in sync ----
  function refresh() {
    const ready = $('donorId').value && kind;
    $('saveBtn').disabled = !ready;
    if ($('saveAnother')) $('saveAnother').disabled = !ready;
    document.querySelectorAll('.choice').forEach((c) => c.classList.toggle('on', c.querySelector('input').checked));
    showDetails();
    updateCampaign();
  }
  showCheckFields();
  showHonoree();
  showNet();
  if (CHOSEN_DONOR) chooseDonor(CHOSEN_DONOR);
  refresh();
})();
