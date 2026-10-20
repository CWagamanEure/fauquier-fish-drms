// Change password page: Show/Hide button and "passwords match" check.
(function () {
  const pw1 = document.getElementById('pw1');
  const pw2 = document.getElementById('pw2');
  const msg = document.getElementById('pwMatch');

  document.querySelectorAll('.show-pw').forEach((btn) => {
    btn.addEventListener('click', () => {
      const input = document.getElementById(btn.dataset.for);
      const hidden = input.type === 'password';
      input.type = hidden ? 'text' : 'password';
      btn.textContent = hidden ? 'Hide' : 'Show';
    });
  });

  function check() {
    if (!pw2.value) { msg.innerHTML = '&nbsp;'; msg.className = 'match'; return; }
    const same = pw1.value === pw2.value;
    msg.textContent = same ? '✓ Passwords match' : 'Passwords do not match';
    msg.className = 'match ' + (same ? 'ok' : 'bad');
  }
  pw1.addEventListener('input', check);
  pw2.addEventListener('input', check);
})();
