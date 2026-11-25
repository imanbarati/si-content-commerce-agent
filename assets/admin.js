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

  const researchButton = document.getElementById('pca-research');
  researchButton?.addEventListener('click', async () => {
    const sku = (document.getElementById('pca-research-sku')?.value || '').trim();
    const out = document.getElementById('pca-sync-status');
    if (!sku || !out) return;
    researchButton.disabled = true;
    out.textContent = 'Researching…';
    try {
      const response = await fetch('/wp-json/posmana-agent/v1/research/' + encodeURIComponent(sku), {
        method: 'POST',
        headers: { 'X-WP-Nonce': window.pcaNonce || '', 'Content-Type': 'application/json' },
        body: '{}',
      });
      const data = await response.json();
      const findings = data.findings || [];
      out.textContent = response.ok
        ? findings.length + ' verified finding(s). First: ' + (findings[0]?.source || 'none')
        : 'Failed: HTTP ' + response.status;
    } catch (error) {
      out.textContent = 'Failed: ' + error.message;
    } finally {
      researchButton.disabled = false;
    }
  });
