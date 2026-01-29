(function () {
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

  function uid() {
    // Good enough for stable keys; not cryptographic.
    return 't_' + Math.random().toString(36).slice(2, 10) + Date.now().toString(36);
  }

  function parseInitial() {
    const el = qs('#oras-tickets-initial');
    if (!el) return [];
    try { return JSON.parse(el.textContent || '[]') || []; } catch (e) { return []; }
  }

  function renderRow(t) {
    const row = document.createElement('div');
    row.className = 'oras-ticket-row';
    row.dataset.key = t.key;
	row.dataset.productId = String(t.product_id || 0);

    row.innerHTML = `
      <div class="oras-ticket-grid">
        <label>
          <span>Name</span>
          <input type="text" class="oras-ticket-name" value="${escapeHtml(t.name || '')}">
        </label>

        <label>
          <span>Price</span>
          <input type="text" class="oras-ticket-price" value="${escapeHtml(String(t.price ?? '0'))}">
        </label>

        <label>
          <span>Capacity</span>
          <input type="number" min="0" class="oras-ticket-capacity" value="${escapeHtml(String(t.capacity ?? 0))}">
        </label>

        <label>
          <span>Sale start (YYYY-MM-DD)</span>
          <input type="text" class="oras-ticket-sale-start" placeholder="2026-05-01" value="${escapeHtml(t.sale_start || '')}">
        </label>

        <label>
          <span>Sale end (YYYY-MM-DD)</span>
          <input type="text" class="oras-ticket-sale-end" placeholder="2026-05-31" value="${escapeHtml(t.sale_end || '')}">
        </label>
      </div>

      <label class="oras-ticket-desc">
        <span>Description (optional)</span>
        <textarea class="oras-ticket-description" rows="2">${escapeHtml(t.description || '')}</textarea>
      </label>

      <div class="oras-ticket-actions">
        <code class="oras-ticket-key">Key: ${escapeHtml(t.key)}</code>
        <button type="button" class="button oras-ticket-remove">Remove</button>
      </div>

      <hr />
    `;
    return row;
  }

  function escapeHtml(str) {
    return String(str)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function collectTickets(container) {
    return qsa('.oras-ticket-row', container).map(row => {
      return {
        key: row.dataset.key,
        name: qs('.oras-ticket-name', row).value.trim(),
        price: qs('.oras-ticket-price', row).value.trim(),
        capacity: parseInt(qs('.oras-ticket-capacity', row).value || '0', 10) || 0,
        sale_start: qs('.oras-ticket-sale-start', row).value.trim(),
        sale_end: qs('.oras-ticket-sale-end', row).value.trim(),
        description: qs('.oras-ticket-description', row).value || '',
        product_id: parseInt(row.dataset.productId || '0', 10) || 0

      };
    });
  }

  function boot() {
    const mount = qs('#oras-tickets-app');
    const hidden = qs('#oras_tickets_payload');
    if (!mount || !hidden) return;

    const wrapper = document.createElement('div');
    wrapper.className = 'oras-tickets-wrapper';

    const topBar = document.createElement('div');
    topBar.className = 'oras-tickets-topbar';
    topBar.innerHTML = `
      <button type="button" class="button button-primary" id="oras-add-ticket">Add ticket</button>
      <span class="oras-tickets-help">Tickets are saved with the event. Woo product sync is next milestone.</span>
    `;

    const list = document.createElement('div');
    list.id = 'oras-tickets-list';

    wrapper.appendChild(topBar);
    wrapper.appendChild(list);
    mount.appendChild(wrapper);

    const initial = parseInitial();
    if (initial.length) {
      initial.forEach(t => {
        if (!t.key) t.key = uid();
        list.appendChild(renderRow(t));
      });
    }

    mount.addEventListener('click', function (e) {
      const btnAdd = e.target.closest('#oras-add-ticket');
      if (btnAdd) {
        const t = { key: uid(), name: '', price: '0', capacity: 0, sale_start: '', sale_end: '', description: '' };
        list.appendChild(renderRow(t));
        sync();
        return;
      }

      const btnRemove = e.target.closest('.oras-ticket-remove');
      if (btnRemove) {
        const row = e.target.closest('.oras-ticket-row');
        if (row) row.remove();
        sync();
      }
    });

    mount.addEventListener('input', function () {
      sync();
    });

    // Ensure payload is set before submit.
    const form = mount.closest('form');
    if (form) {
      form.addEventListener('submit', function () {
        sync();
      });
    }

    function sync() {
      const tickets = collectTickets(mount);
      hidden.value = JSON.stringify(tickets);
    }

    sync();
  }

  document.addEventListener('DOMContentLoaded', boot);
})();
