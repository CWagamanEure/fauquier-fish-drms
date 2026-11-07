// Add donation page: save the unfinished form in the session when the page is left, so it can be restored
// (see donation_draft_save() in database/dbDonations.php). Not used when editing a stored gift.
(function () {
  const form = document.getElementById('donationForm');
  if (!form || !form.dataset.draft || !navigator.sendBeacon) return;
  let skip = false;   // set when the page is left by saving or cancelling, which handle the draft themselves
  form.addEventListener('submit', () => { skip = true; });
  document.querySelectorAll('a[data-no-draft]').forEach((a) => a.addEventListener('click', () => { skip = true; }));
  function send() {
    if (skip) return;
    const data = new FormData(form);
    const kind = form.querySelector('input[name="kind"]:checked');
    if (kind) data.set('kind', kind.value);   // the type fieldset is disabled until a donor is chosen
    data.set('next', 'draft');
    navigator.sendBeacon('addDonation.php', data);
  }
  window.addEventListener('pagehide', send);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') send(); });
})();