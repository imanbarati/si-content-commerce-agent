(function () {
  'use strict';
  const status = document.getElementById('pca-sync-status');
  document.querySelectorAll('.pca-sync').forEach((button) => {
    button.addEventListener('click', async () => {
      if (!status) return;
      button.disabled = true;
      status.textContent = 'Working…';
      try {
        const response = await fetch('/wp-json/posmana-agent/v1/sync/' + button.dataset.action, {
          method: 'POST',
          headers: { 'X-WP-Nonce': window.pcaNonce || '', 'Content-Type': 'application/json' },
          body: '{}',
        });
        const data = await response.json();
        status.textContent = response.ok ? 'Done: ' + JSON.stringify(data) : 'Failed: HTTP ' + response.status;
      } catch (error) {
        status.textContent = 'Failed: ' + error.message;
      } finally {
        button.disabled = false;
      }
    });
  });
})();
