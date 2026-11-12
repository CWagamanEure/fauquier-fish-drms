// Add communication: the donor search that fills the hidden donor_id (donorLookup.php). The page renders the
// chosen-donor card itself (preselect or a re-rendered form), so this script only handles searching, choosing
// and Change. Results and the card are built with textContent only. Adapted from js/addDonation.js step 1.
(function () {
  const $ = (id) => document.getElementById(id);
  const search = $('donorSearch');
  if (!search) return;   // no donors view: the page shows a notice instead
  const results = $('donorResults');
  const el = (tag, text) => { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; return n; };
  const initials = (name) => {
    const p = String(name).trim().split(/\s+/).filter(Boolean);
    if (!p.length) return '?';
    return (Array.from(p[0])[0] + (p.length > 1 ? Array.from(p[p.length - 1])[0] : '')).toUpperCase();
  };
  let timer = null;
  let pending = null;

  function showMessage(text) {
    const li = el('li', text);
    li.className = 'none';
    results.replaceChildren(li);
    results.hidden = false;
  }

  function showResults(donors) {
    if (!donors.length) { showMessage('No donor found.'); return; }
    results.replaceChildren(...donors.map((d) => {
      const btn = el('button');
      btn.type = 'button';
      btn.append(el('strong', d.name), ' ', el('small', [d.type, d.town].filter(Boolean).join(' · ')));
      btn.addEventListener('click', () => choose(d));
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
      if (search.value.trim() !== q) return;   // the user kept typing
      if (res.status === 401) { showMessage('Your session has ended. Reload the page to log in again.'); return; }
      if (!res.ok || !data.success) { showMessage(data.error || 'Could not search donors. Please try again.'); return; }
      showResults(data.donors);
    } catch (e) {
      if (e.name !== 'AbortError') showMessage('Could not search donors. Please try again.');
    }
  }

  function choose(d) {
    $('donor_id').value = d.id;
    $('chosenInitials').textContent = initials(d.name);
    $('chosenName').textContent = d.name;
    $('chosenMeta').textContent = [d.type, d.town].filter(Boolean).join(' · ');
    $('donorSearchBox').hidden = true;
    $('donorChosen').hidden = false;
    results.hidden = true;
    $('changeDonor').focus();
  }

  // Enter searches right away instead of submitting the whole form.
  search.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    clearTimeout(timer);
    const q = search.value.trim();
    if (q.length >= 2) lookup(q);
  });

  search.addEventListener('input', () => {
    clearTimeout(timer);
    const q = search.value.trim();
    if (!q) { if (pending) pending.abort(); results.hidden = true; results.replaceChildren(); return; }
    if (q.length < 2) { showMessage('Type at least 2 letters.'); return; }
    timer = setTimeout(() => lookup(q), 250);
  });

  $('changeDonor').addEventListener('click', () => {
    $('donor_id').value = '';
    $('donorChosen').hidden = true;
    $('donorSearchBox').hidden = false;
    search.value = '';
    results.hidden = true;
    results.replaceChildren();
    search.focus();
  });
})();
