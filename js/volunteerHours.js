// Log / edit volunteer hours page: hours from the start and end time, the "same shift for more people"
// chips (donorLookup.php), and the delete confirmation. Nothing is computed on load, so a stored or
// re-rendered hours value is never overwritten.
(function () {
  const $ = (id) => document.getElementById(id);
  const el = (tag, text) => { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; return n; };

  // ---- Hours from times: minutes / 60 rounded to 0.01, the same rule as hours_form_validate() ----
  const start = $('start');
  const end = $('end');
  const hours = $('hours');
  let lastAuto = null;   // the value this script last wrote; anything else in the box was typed
  const minutes = (t) => /^\d{2}:\d{2}/.test(t) ? Number(t.slice(0, 2)) * 60 + Number(t.slice(3, 5)) : null;
  function fillHours() {
    if (hours.value.trim() !== '' && hours.value !== lastAuto) return;
    const a = minutes(start.value);
    const b = minutes(end.value);
    if (a === null || b === null || b <= a) return;
    lastAuto = String(Math.round((b - a) * 100 / 60) / 100);
    hours.value = lastAuto;
  }
  if (start && end && hours && !start.disabled) {
    start.addEventListener('input', fillHours);
    end.addEventListener('input', fillHours);
  }

  // ---- Same shift for more people (add page only) ----
  const box = $('peopleBox');
  const search = $('volSearch');
  const results = $('volResults');
  if (box && search && results) {
    const mainId = box.dataset.main;
    const chosen = () => Array.from(box.querySelectorAll('input[name="extra_donor[]"]')).map((i) => i.value);
    let timer = null;
    let pending = null;

    function showMessage(text) {
      const li = el('li', text);
      li.className = 'none';
      results.replaceChildren(li);
      results.hidden = false;
    }

    function addChip(d) {
      const id = String(d.id);
      if (id === mainId || chosen().includes(id)) { results.hidden = true; search.value = ''; return; }
      const chip = el('span');
      chip.className = 'person-chip';
      const remove = el('button', '×');
      remove.type = 'button';
      remove.setAttribute('aria-label', 'Remove ' + d.name);
      const input = el('input');
      input.type = 'hidden';
      input.name = 'extra_donor[]';
      input.value = id;
      chip.append(d.name + ' ', remove, input);
      box.insertBefore(chip, search);
      search.value = '';
      results.hidden = true;
      search.focus();
    }

    function showResults(donors) {
      const taken = chosen().concat(mainId);
      const list = donors.filter((d) => !taken.includes(String(d.id)));
      if (!list.length) { showMessage('No other active donor found.'); return; }
      results.replaceChildren(...list.map((d) => {
        const btn = el('button');
        btn.type = 'button';
        btn.append(el('strong', d.name), ' ', el('small', [d.type, d.town].filter(Boolean).join(' · ')));
        btn.addEventListener('click', () => addChip(d));
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
    // Chips rendered by the server after an error, and new ones, share this remove handler.
    box.addEventListener('click', (e) => {
      const btn = e.target.closest('.person-chip button');
      if (btn) btn.closest('.person-chip').remove();
    });
  }

  // ---- Delete confirmation ----
  const del = $('deleteForm');
  if (del) {
    del.addEventListener('submit', (e) => {
      if (!window.confirm('Delete this volunteer hours entry? This cannot be undone.')) e.preventDefault();
    });
  }
})();
