/**
 * admin-bulk.js
 * Adds "Add to carousel" + "Remove from carousel" buttons to Media Library grid Bulk Select toolbar.
 * Uses DOM selection (.attachments .attachment.selected) for reliability.
 */

console.log('ALEXK BULK ✅ admin-bulk.js loaded', window.location.href);
console.log('ALEXK BULK data:', window.ALEXK_BULK);

(function () {
  function exitBulkModeAndRefresh() {
  // 1) Clear selection highlight in the grid
  document
    .querySelectorAll('.attachments .attachment.selected')
    .forEach((el) => el.classList.remove('selected'));

  // 2) Turn off bulk mode by clicking the Bulk Select toggle (not Cancel)
  // In WP grid, the same button usually toggles bulk mode on/off.
  const bulkToggle = document.querySelector('.bulk-select-button');
  if (bulkToggle && bulkToggle.classList.contains('active')) {
    bulkToggle.click();
  }

  // 3) Force a refresh so the UI matches server truth (prevents “revert” weirdness)
  // Keeps you on upload.php and returns to normal mode immediately.
  window.location.reload();
}


  function getSelectedIdsFromDom() {
    const nodes = document.querySelectorAll('.attachments .attachment.selected');
    return [...nodes]
      .map((n) => parseInt(n.getAttribute('data-id'), 10))
      .filter(Number.isFinite);
  }

  async function postAjax(action, ids) {
    const body = new URLSearchParams();
    body.set('action', action);
    body.set('nonce', window.ALEXK_BULK?.nonce || '');
    body.set('ids', ids.join(','));

    const res = await fetch(window.ajaxurl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
    });

    return res.json();
  }
      // =================
    // PROGRESS BAR
    // ================
    
    function ensureProgressUi() {
  if (document.getElementById('alexk-bulk-progress')) return;

  const wrap = document.createElement('div');
  wrap.id = 'alexk-bulk-progress';
  wrap.style.position = 'fixed';
  wrap.style.top = 'auto';
  wrap.style.right = '16px';
  wrap.style.bottom = '28px';
  wrap.style.zIndex = '99999';
  wrap.style.background = '#fff';
  wrap.style.border = '1px solid #ccd0d4';
  wrap.style.borderRadius = '8px';
  wrap.style.padding = '10px 12px';
  wrap.style.boxShadow = '0 2px 10px rgba(0,0,0,0.12)';
  wrap.style.minWidth = '220px';
  wrap.style.fontSize = '13px';
  wrap.hidden = true;

  const title = document.createElement('div');
  title.id = 'alexk-bulk-progress-title';
  title.style.marginBottom = '8px';
  title.textContent = 'Working…';

  const barOuter = document.createElement('div');
  barOuter.style.height = '8px';
  barOuter.style.background = '#f0f0f1';
  barOuter.style.borderRadius = '999px';
  barOuter.style.overflow = 'hidden';

  const barInner = document.createElement('div');
  barInner.id = 'alexk-bulk-progress-bar';
  barInner.style.height = '100%';
  barInner.style.width = '0%';
  barInner.style.background = '#2271b1';

  barOuter.appendChild(barInner);

  const meta = document.createElement('div');
  meta.id = 'alexk-bulk-progress-meta';
  meta.style.marginTop = '8px';
  meta.style.opacity = '0.8';
  meta.textContent = '';

  wrap.appendChild(title);
  wrap.appendChild(barOuter);
  wrap.appendChild(meta);
  document.body.appendChild(wrap);
}

function showProgress(pending, done, mode) {
  ensureProgressUi();

  const wrap = document.getElementById('alexk-bulk-progress');
  const title = document.getElementById('alexk-bulk-progress-title');
  const bar = document.getElementById('alexk-bulk-progress-bar');
  const meta = document.getElementById('alexk-bulk-progress-meta');

  const verb = mode === 'remove' ? 'Deleting' : 'Generating';
  const safePending = Math.max(0, pending);
  const safeDone = Math.min(Math.max(0, done), safePending || done);

  const pct = safePending > 0 ? Math.round((safeDone / safePending) * 100) : 0;

  title.textContent = `${verb} images…`;
  meta.textContent = `${safeDone} / ${safePending}`;
  bar.style.width = `${pct}%`;
  wrap.hidden = false;

}

function hideProgressSoon() {
  const wrap = document.getElementById('alexk-bulk-progress');
  if (!wrap) return;

  setTimeout(() => {
    wrap.hidden = true;

  }, 1200);
}

let alexkProgressTimer = null;

async function pollJobStatus() {
  try {
    const body = new URLSearchParams();
    body.set('action', 'alexk_bulk_job_status');

    const res = await fetch(window.ajaxurl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
    });

    const json = await res.json();
    if (!json?.success) return;

    const pending = parseInt(json.data.pending, 10) || 0;
    const done = parseInt(json.data.done, 10) || 0;
    const mode = json.data.mode || '';

    if (pending > 0 && done < pending) {
      showProgress(pending, done, mode);
      return;
    }

    // Done or idle
    if (pending > 0 && done >= pending) {
      showProgress(pending, done, mode);
      hideProgressSoon();
    }
  } catch (e) {
    // silent
  }
}

function startPollingProgress() {
  if (alexkProgressTimer) clearInterval(alexkProgressTimer);
  alexkProgressTimer = setInterval(pollJobStatus, 900);
  pollJobStatus();
}

startPollingProgress();


// ==================
// Button injection
// ------------------


  function injectButtons() {
    const toolbar = document.querySelector('.media-toolbar.wp-filter');
    if (!toolbar) return false;

    // Prevent double-inject (either button present means we already injected)
    if (document.getElementById('alexk-add-to-carousel')) return true;

    // --- Add button ---
    const addBtn = document.createElement('button');
    addBtn.id = 'alexk-add-to-carousel';
    addBtn.type = 'button';
    addBtn.className = 'button button-primary';
    addBtn.textContent = 'Add to carousel';

    addBtn.addEventListener('click', async () => {
      const ids = getSelectedIdsFromDom();

      if (!ids.length) {
        alert('No items selected');
        return;
      }

      if (!confirm(`Add ${ids.length} item(s) to the carousel?`)) return;

      try {
        const json = await postAjax('alexk_bulk_add_to_carousel', ids);
        console.log('ALEXK BULK add response:', json);

        if (!json?.success) {
          alert(json?.data?.message || 'Bulk add failed (see console)');
          return;
        }

        alert(`Added ${json.data.updated} item(s) to the carousel.`);
        exitBulkModeAndRefresh();
        startPollingProgress();


      } catch (err) {
        console.error('ALEXK BULK add network error:', err);
        alert('Bulk add failed (network error).');
      }
    });





    // --- Remove button ---
    const removeBtn = document.createElement('button');
    removeBtn.id = 'alexk-remove-from-carousel';
    removeBtn.type = 'button';
    removeBtn.className = 'button';
    removeBtn.textContent = 'Remove from carousel';

    removeBtn.addEventListener('click', async () => {
      const ids = getSelectedIdsFromDom();

      if (!ids.length) {
        alert('No items selected');
        return;
      }

      if (!confirm(`Remove ${ids.length} item(s) from the carousel?`)) return;

      try {
        const json = await postAjax('alexk_bulk_remove_from_carousel', ids);
        console.log('ALEXK BULK remove response:', json);

        if (!json?.success) {
          alert(json?.data?.message || 'Remove failed (see console)');
          return;
        }

        alert(`Removed ${json.data.updated} item(s) from the carousel.`);
        exitBulkModeAndRefresh();
        startPollingProgress();


      } catch (err) {
        console.error('ALEXK BULK remove network error:', err);
        alert('Remove failed (network error).');
      }
    });

    // Add both buttons to toolbar
    toolbar.prepend(removeBtn);
    toolbar.prepend(addBtn);

    console.log('ALEXK BULK ✅ buttons injected into .media-toolbar.wp-filter');
    return true;
  }

  // Try now
  if (injectButtons()) return;

  // Otherwise wait for WP to render/replace the toolbar
  const mo = new MutationObserver(() => {
    if (injectButtons()) mo.disconnect();
  });
  mo.observe(document.documentElement, { childList: true, subtree: true });
})();
