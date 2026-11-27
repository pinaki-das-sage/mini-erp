'use strict';

(() => {
  const form = document.querySelector('[data-reservation-filters]');
  const template = document.querySelector('#reservation-filter-template');
  if (!form || !template) return;
  const root = form.closest('.inventory-reservations');
  let current = null;

  const close = (restoreFocus = true) => {
    if (!current) return;
    const { popup, trigger } = current;
    current = null;
    popup.remove();
    trigger.setAttribute('aria-expanded', 'false');
    trigger.removeAttribute('aria-controls');
    if (restoreFocus) trigger.focus();
  };

  const position = () => {
    if (!current) return;
    const rect = current.trigger.getBoundingClientRect();
    const popup = current.popup;
    const width = popup.offsetWidth;
    const height = popup.offsetHeight;
    const left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8));
    let top = rect.bottom + 6;
    if (top + height > window.innerHeight - 8) top = Math.max(8, rect.top - height - 6);
    popup.style.left = `${left}px`;
    popup.style.top = `${top}px`;
  };

  const open = (trigger) => {
    if (current?.trigger === trigger) {
      close();
      return;
    }
    close(false);
    const column = trigger.dataset.filterColumn;
    const field = Array.from(form.elements).find((element) => element.name === column);
    if (!field) return;
    const popup = template.content.firstElementChild.cloneNode(true);
    popup.id = 'reservation-column-filter';
    const title = trigger.dataset.filterLabel.trim();
    popup.querySelector('.reservation-filter-title').textContent = `Filter ${title}`;
    const text = popup.querySelector('.reservation-filter-text');
    const status = popup.querySelector('.reservation-filter-status');
    const isStatus = column === 'status';
    const isNumeric = trigger.dataset.filterType === 'numeric';
    popup.querySelector('.reservation-filter-text-label').hidden = isStatus;
    popup.querySelector('.reservation-filter-status-label').hidden = !isStatus;
    const control = isStatus ? status : text;
    control.value = field.value;
    if (!isStatus) {
      text.maxLength = Number(trigger.dataset.filterLength) || 100;
      text.inputMode = isNumeric ? 'numeric' : 'text';
      text.placeholder = isNumeric ? 'Enter a whole number' : 'Enter text to filter';
      popup.querySelector('.reservation-filter-input-label').textContent = title;
      if (isNumeric) text.pattern = '[0-9]+';
      text.addEventListener('input', () => text.setCustomValidity(''));
    }

    const apply = (clear = false) => {
      const value = clear ? '' : control.value.trim();
      if (!clear && isNumeric && value !== '') {
        const minimum = Number(trigger.dataset.filterMin);
        text.setCustomValidity(/^[0-9]+$/.test(value) && Number(value) >= minimum
          ? '' : `Enter a whole number of at least ${minimum}.`);
      }
      if (!clear && !control.reportValidity()) return;
      field.value = value;
      close(false);
      // No page field is submitted: changing a filter starts from the first page.
      form.requestSubmit();
    };
    popup.querySelector('[data-filter-ok]').addEventListener('click', () => apply());
    popup.querySelector('[data-filter-clear]').addEventListener('click', () => apply(true));
    popup.querySelector('[data-filter-cancel]').addEventListener('click', () => close());
    popup.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        close();
      } else if (event.key === 'Enter' && event.target === text) {
        event.preventDefault();
        apply();
      }
    });
    root.append(popup);
    current = { popup, trigger };
    trigger.setAttribute('aria-expanded', 'true');
    trigger.setAttribute('aria-controls', popup.id);
    position();
    control.focus();
    if (!isStatus) text.select();
  };

  form.querySelectorAll('[data-filter-column]').forEach((trigger) => {
    trigger.disabled = false;
    trigger.addEventListener('click', () => open(trigger));
  });
  document.addEventListener('pointerdown', (event) => {
    if (current && !current.popup.contains(event.target) && !current.trigger.contains(event.target)) close(false);
  });
  document.addEventListener('focusin', (event) => {
    if (current && !current.popup.contains(event.target) && !current.trigger.contains(event.target)) close(false);
  });
  window.addEventListener('resize', position);
  window.addEventListener('scroll', (event) => {
    if (current && !current.popup.contains(event.target)) close(false);
  }, true);
})();
