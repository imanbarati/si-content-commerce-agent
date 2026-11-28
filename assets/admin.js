(function () {
  'use strict';

  // Toast notification system
  const toastContainer = document.createElement('div');
  toastContainer.id = 'pca-toast-container';
  toastContainer.style.cssText = `
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 99999;
    display: flex;
    flex-direction: column;
    gap: 8px;
    pointer-events: none;
  `;
  document.body.appendChild(toastContainer);

  function showToast(message, type = 'info', duration = 4000) {
    const toast = document.createElement('div');
    toast.style.cssText = `
      background: ${type === 'error' ? '#dc3232' : type === 'success' ? '#28a745' : '#0073aa'};
      color: white;
      padding: 12px 16px;
      border-radius: 4px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.15);
      max-width: 360px;
      font-size: 13px;
      line-height: 1.4;
      pointer-events: auto;
      opacity: 0;
      transform: translateY(10px);
      transition: opacity 0.2s, transform 0.2s;
    `;
    toast.textContent = message;
    toastContainer.appendChild(toast);
    requestAnimationFrame(() => {
      toast.style.opacity = '1';
      toast.style.transform = 'translateY(0)';
    });
    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(10px)';
      setTimeout(() => toast.remove(), 200);
    }, duration);
  }

  // Loading state helpers
  function setLoading(button, loading) {
    if (loading) {
      button.disabled = true;
      button.dataset.originalText = button.textContent;
      button.textContent = '⟳ Working…';
      button.style.opacity = '0.7';
    } else {
      button.disabled = false;
      button.textContent = button.dataset.originalText || button.textContent;
      button.style.opacity = '1';
    }
  }

  // API helper
  async function apiCall(path, options = {}) {
    const response = await fetch('/wp-json/' + path, {
      ...options,
      headers: {
        'X-WP-Nonce': window.pcaNonce || '',
        'Content-Type': 'application/json',
        ...(options.headers || {}),
      },
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new Error(data.message || `HTTP ${response.status}`);
    }
    return data;
  }

  // Sync buttons
  document.querySelectorAll('.pca-sync').forEach((button) => {
    button.addEventListener('click', async () => {
      const action = button.dataset.action;
      setLoading(button, true);
      try {
        const data = await apiCall('posmana-agent/v1/sync/' + action, { method: 'POST', body: '{}' });
        showToast(`Sync ${action}: ${data.created ?? data.applied ?? 'done'}`, 'success');
      } catch (error) {
        showToast(`Sync ${action} failed: ${error.message}`, 'error');
      } finally {
        setLoading(button, false);
      }
    });
  });

  // Research button
  document.getElementById('pca-research')?.addEventListener('click', async () => {
    const sku = (document.getElementById('pca-research-sku')?.value || '').trim();
    const button = document.getElementById('pca-research');
    if (!sku || !button) return;
    setLoading(button, true);
    try {
      const data = await apiCall('posmana-agent/v1/research/' + encodeURIComponent(sku), { method: 'POST', body: '{}' });
      const findings = data.findings || [];
      showToast(`Research ${sku}: ${findings.length} verified finding(s)`, findings.length ? 'success' : 'info');
      if (findings.length) {
        showToast(`Top: ${findings[0].source}`, 'info');
      }
    } catch (error) {
      showToast(`Research failed: ${error.message}`, 'error');
    } finally {
      setLoading(button, false);
    }
  });

  // Clean post button
  document.getElementById('pca-clean-post')?.addEventListener('click', async () => {
    const id = (document.getElementById('pca-clean-post-id')?.value || '').trim();
    const button = document.getElementById('pca-clean-post');
    if (!id || !button) return;
    setLoading(button, true);
    try {
      await apiCall('posmana-agent/v1/privacy/clean', { method: 'POST', body: JSON.stringify({ post_id: Number(id) }) });
      showToast(`Cleaned post ${id}`, 'success');
    } catch (error) {
      showToast(`Clean failed: ${error.message}`, 'error');
    } finally {
      setLoading(button, false);
    }
  });

  // Keywords button
  document.getElementById('pca-keywords')?.addEventListener('click', async () => {
    const seed = (document.getElementById('pca-keyword-seed')?.value || '').trim();
    const button = document.getElementById('pca-keywords');
    if (!seed || !button) return;
    setLoading(button, true);
    try {
      const data = await apiCall('posmana-agent/v1/growth/keywords', { method: 'POST', body: JSON.stringify({ seed }) });
      showToast(`Keywords: ${(data.variants || []).slice(0, 5).join(', ')}${data.variants.length > 5 ? '…' : ''}`, 'info');
    } catch (error) {
      showToast(`Keywords failed: ${error.message}`, 'error');
    } finally {
      setLoading(button, false);
    }
  });

  // Quick actions (checklist, audit)
  async function quickAction(path, options = {}) {
    const button = document.querySelector(`[data-quick="${path}"]`) || document.getElementById('pca-run-checklist') || document.getElementById('pca-run-audit');
    const status = document.getElementById('pca-quick-status');
    if (button) setLoading(button, true);
    if (status) status.textContent = 'Running…';
    try {
      const data = await apiCall(path, options);
      showToast('Done', 'success');
      if (status) status.textContent = 'Done — check below';
      // Render results in a collapsible panel
      renderResultPanel(path, data);
    } catch (error) {
      showToast(`Failed: ${error.message}`, 'error');
      if (status) status.textContent = 'Failed: ' + error.message;
    } finally {
      if (button) setLoading(button, false);
    }
  }

  function renderResultPanel(path, data) {
    let existing = document.getElementById('pca-result-panel');
    if (existing) existing.remove();

    const panel = document.createElement('div');
    panel.id = 'pca-result-panel';
    panel.style.cssText = `
      margin-top: 16px;
      padding: 16px;
      background: #f6f7f7;
      border: 1px solid #ddd;
      border-radius: 4px;
      max-height: 400px;
      overflow: auto;
    `;

    let html = '<h4 style="margin-top:0">Result: ' + path + '</h4>';
    if (data.checklist) {
      html += '<ul style="margin:0;padding-left:20px">';
      data.checklist.forEach(item => {
        html += `<li style="margin:8px 0"><b>${item.label}</b> — ${item.ok ? '<span style="color:#28a745">✓</span>' : '<span style="color:#dc3232">✗</span>'} ${item.hint}</li>`;
      });
      html += '</ul>';
    } else if (data.results) {
      html += '<pre style="white-space:pre-wrap;font-size:12px">' + JSON.stringify(data.results, null, 2) + '</pre>';
    } else {
      html += '<pre style="white-space:pre-wrap;font-size:12px">' + JSON.stringify(data, null, 2) + '</pre>';
    }

    panel.innerHTML = html;
    const actions = document.querySelector('.pca-wrap .submit');
    if (actions) actions.insertAdjacentElement('afterend', panel);
  }

  document.getElementById('pca-run-checklist')?.addEventListener('click', () => quickAction('posmana-agent/v1/growth/campaign'));
  document.getElementById('pca-run-audit')?.addEventListener('click', () => quickAction('posmana-agent/v1/legal/audit', { method: 'POST' }));

  // Settings form: show saved toast on submit
  const form = document.querySelector('.pca-form');
  if (form) {
    form.addEventListener('submit', () => {
      showToast('Settings saved', 'success');
    });
  }
})();
