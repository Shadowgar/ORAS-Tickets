(() => {
  const container = document.getElementById('oras-event-speakers-metabox');
  if (!container) {
    return;
  }

  const rowsWrapper = container.querySelector('.oras-event-speakers-rows');
  const addButton = container.querySelector('#oras-add-speaker-row');
  const template = container.querySelector('#oras-speaker-row-template');

  if (!rowsWrapper || !addButton || !template) {
    return;
  }

  const updateRowVisibility = (row) => {
    const compensationSelect = row.querySelector('.oras-event-speaker-compensation');
    if (!compensationSelect) {
      return;
    }

    const compensation = compensationSelect.value;
    const feeField = row.querySelector('[data-compensation="fee"]');
    const membershipField = row.querySelector('[data-compensation="membership"]');

    if (feeField) {
      feeField.style.display = compensation === 'fee' ? '' : 'none';
    }

    if (membershipField) {
      membershipField.style.display = compensation === 'membership' ? '' : 'none';
    }
  };

  const wireRow = (row) => {
    const removeButton = row.querySelector('.oras-remove-speaker-row');
    if (removeButton) {
      removeButton.addEventListener('click', () => {
        row.remove();
      });
    }

    const compensationSelect = row.querySelector('.oras-event-speaker-compensation');
    if (compensationSelect) {
      compensationSelect.addEventListener('change', () => updateRowVisibility(row));
    }

    updateRowVisibility(row);
  };

  const existingRows = rowsWrapper.querySelectorAll('.oras-event-speaker-row');
  existingRows.forEach((row) => wireRow(row));

  // Start with bodies collapsed for compact view
  existingRows.forEach((row) => {
    const body = row.querySelector('.oras-card__body');
    if (body) {
      body.hidden = true;
    }
  });

  // Card toggle handler (expand/collapse details)
  rowsWrapper.addEventListener('click', (e) => {
    const btn = e.target.closest('.oras-card-toggle');
    if (!btn) return;
    const idx = btn.dataset.index;
    const row = rowsWrapper.querySelector('.oras-event-speaker-row[data-index="' + idx + '"]');
    if (!row) return;
    const body = row.querySelector('.oras-card__body');
    if (!body) return;
    const expanded = body.hasAttribute('data-expanded');
    if (expanded) {
      body.removeAttribute('data-expanded');
      body.hidden = true;
      btn.setAttribute('aria-expanded', 'false');
    } else {
      body.setAttribute('data-expanded', '1');
      body.hidden = false;
      btn.setAttribute('aria-expanded', 'true');
    }
  });

  addButton.addEventListener('click', () => {
    const nextIndex = Number(rowsWrapper.dataset.nextIndex || 0);
    const html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex));

    const wrapper = document.createElement('div');
    wrapper.innerHTML = html.trim();

    const newRow = wrapper.firstElementChild;
    if (!newRow) {
      return;
    }

    rowsWrapper.appendChild(newRow);
    rowsWrapper.dataset.nextIndex = String(nextIndex + 1);
    wireRow(newRow);
  });
})();
