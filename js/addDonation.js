// Add donation page: donor search, type switch, side panel.
(function () {
  const $ = (id) => document.getElementById(id);
  const money = (n) => '$' + Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });
  const initials = (name) => {
    const p = String(name).trim().split(/\s+/).filter(Boolean);
    if (!p.length) return '?';
    return (Array.from(p[0])[0] + (p.length > 1 ? Array.from(p[p.length - 1])[0] : '')).toUpperCase();
  };
  // 'YYYY-MM-DD' from the database -> 'Oct 9, 2026', read as a calendar date (no time-zone shift)
  const longDate = (iso) => new Date(iso + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  const el = (tag, text) => { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; return n; };

  const search = $('donorSearch');
  const results = $('donorResults');
  let kind = null;

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

  if (search) {
    search.addEventListener('input', () => {
      clearTimeout(timer);
      const q = search.value.trim();
      if (!q) { if (pending) pending.abort(); results.hidden = true; results.replaceChildren(); return; }
      if (q.length < 2) { showMessage('Type at least 2 letters.'); return; }
      timer = setTimeout(() => lookup(q), 250);
    });
  }

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

  // ---- 2. Donation type ----
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

  // Check-only fields
  $('payType').addEventListener('change', () => {
    const isCheck = $('payType').value === 'Check';
    ['checkNumField', 'signedField', 'depositField'].forEach((f) => ($(f).style.visibility = isCheck ? '' : 'hidden'));
  });

  // ---- Campaign progress card ----
  document.querySelectorAll('.campaign-select').forEach((s) => s.addEventListener('change', updateCampaign));
  $('quantity').addEventListener('input', updateCampaign);

  function updateCampaign() {
    const select = kind === 'cash' ? $('cashCampaign') : $('itemCampaign');
    const name = select.value;
    const [goal, sofar] = CAMPAIGNS[name] || [0, 0];
    if (!kind || !goal) { $('campaignCard').hidden = true; return; }
    const added = kind === 'noncash' ? Number($('quantity').value) || 0 : 0;
    const total = sofar + added;
    $('ctName').textContent = name;
    $('ctFill').style.width = Math.min(100, (total / goal) * 100) + '%';
    $('ctText').textContent = `${total.toLocaleString()} of ${goal.toLocaleString()} items${added ? ' after this gift' : ''}`;
    $('campaignCard').hidden = false;
  }

  // ---- Keep everything in sync ----
  function refresh() {
    const ready = $('donorId').value && kind;
    $('saveBtn').disabled = !ready;
    $('saveAnother').disabled = !ready;
    document.querySelectorAll('.choice').forEach((c) => c.classList.toggle('on', c.querySelector('input').checked));
    showDetails();
    updateCampaign();
  }
  if (CHOSEN_DONOR) chooseDonor(CHOSEN_DONOR);
  refresh();
})();
