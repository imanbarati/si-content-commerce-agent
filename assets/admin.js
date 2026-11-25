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

  document.getElementById('pca-clean-post')?.addEventListener('click', async () => {
    const id = (document.getElementById('pca-clean-post-id')?.value || '').trim();
    const out = document.getElementById('pca-sync-status');
    if (!id || !out) return;
    out.textContent = 'Cleaning…';
    try {
      const response = await fetch('/wp-json/posmana-agent/v1/privacy/clean', {
        method: 'POST',
        headers: { 'X-WP-Nonce': window.pcaNonce || '', 'Content-Type': 'application/json' },
        body: JSON.stringify({ post_id: Number(id) }),
      });
      const data = await response.json();
      out.textContent = response.ok ? 'Cleaned post ' + data.post_id : 'Failed: HTTP ' + response.status;
    } catch (error) {
      out.textContent = 'Failed: ' + error.message;
    }
  });

  document.getElementById('pca-keywords')?.addEventListener('click', async () => {
    const seed = (document.getElementById('pca-keyword-seed')?.value || '').trim()
    const out = document.getElementById('pca-sync-status')
    if (!seed || !out) return
    out.textContent = 'Building variants…'
    try {
      const response = await fetch('/wp-json/posmana-agent/v1/growth/keywords', {
        method: 'POST',
        headers: { 'X-WP-Nonce': window.pcaNonce || '', 'Content-Type': 'application/json' },
        body: JSON.stringify({ seed }),
      })
      const data = await response.json()
      out.textContent = (data.variants || []).join(' · ')
    } catch (error) {
      out.textContent = 'Failed: ' + error.message
    }
  })
