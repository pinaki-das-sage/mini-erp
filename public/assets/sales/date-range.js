(() => {
    'use strict';
    document.querySelectorAll('[data-date-range]').forEach(control => {
        const text = control.querySelector('[data-range-text]');
        const picker = control.querySelector('[data-range-picker]');
        const start = control.querySelector('[data-range-start]');
        const end = control.querySelector('[data-range-end]');
        const error = control.querySelector('[data-range-error]');
        picker.querySelector('summary').addEventListener('click', () => {
            if (picker.open) return;
            const dates = text.value.trim().match(/^(\d{4}-\d{2}-\d{2})(?:\s*(?:to|[-–—])\s*(\d{4}-\d{2}-\d{2}))?$/i);
            start.value = dates?.[1] || '';
            end.value = dates?.[2] || dates?.[1] || '';
            end.min = start.value;
            error.hidden = true;
        });
        start.addEventListener('change', () => { end.min = start.value; });
        control.querySelector('[data-range-apply]').addEventListener('click', () => {
            if (!start.value || !end.value || !start.validity.valid || !end.validity.valid || end.value < start.value) {
                error.hidden = false;
                return;
            }
            text.value = `${start.value} to ${end.value}`;
            text.dispatchEvent(new Event('input', {bubbles: true}));
            picker.open = false;
            text.focus();
        });
        control.addEventListener('keydown', event => { if (event.key === 'Escape') picker.open = false; });
    });
})();
