// Add donation page: donor search, type switch, side panel.
(function () {
  const $ = (id) => document.getElementById(id);
  const money = (n) => '$' + Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });
  const initials = (name) => {
    const p = name.replace(/[^A-Za-z ]/g, '').trim().split(/\s+/);
    return (p[0][0] + p[p.length - 1][0]).toUpperCase();
  };

  const search = $('donorSearch');
  const results = $('donorResults');
  let kind = null;

  // ---- 1. Donor search ----
  search.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    results.innerHTML = '';
    if (!q) { results.hidden = true; return; }
    const matches = Object.entries(DONORS).filter(([, d]) => d.name.toLowerCase().includes(q));
    if (!matches.length) {
      results.innerHTML = '<li class="none">No donor found. Use +Add to create one.</li>';
    }
    matches.forEach(([id, d]) => {
      const li = document.createElement('li');
      li.innerHTML = `<strong>${d.name}</strong> <small>${d.type} · ${d.town}</small>`;
      li.addEventListener('click', () => chooseDonor(id));
      results.appendChild(li);
    });
    results.hidden = false;
  });

  function chooseDonor(id) {
    const d = DONORS[id];
    $('donorId').value = id;
    $('chosenInitials').textContent = initials(d.name);
    $('chosenName').textContent = d.name;
    $('chosenMeta').textContent = `${d.type} · ${d.town} · Donor since ${d.since}`;
    $('donorSearchBox').hidden = true;
    $('donorChosen').hidden = false;
    results.hidden = true;

    $('sumName').textContent = d.name;
    $('sumCash').textContent = money(d.cash);
    $('sumItems').textContent = d.items.toLocaleString() + ' items';
    $('sumLast').textContent = d.lastGift;
    $('sumVol').textContent = d.volunteers;
    $('sumProfile').href = 'viewProfile.php?donor=' + encodeURIComponent(id);
    $('donorSummary').hidden = false;

    $('stepType').disabled = false;
    refresh();
  }

  $('changeDonor').addEventListener('click', () => {
    $('donorId').value = '';
    $('donorChosen').hidden = true;
    $('donorSearchBox').hidden = false;
    $('donorSummary').hidden = true;
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
  refresh();
})();
