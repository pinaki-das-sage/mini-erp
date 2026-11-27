(() => {
    'use strict';
    document.querySelectorAll('[data-sales-dates]').forEach(value => {
        const control = value.closest('form');

        const fields = ['from', 'to'].map(side => ({
            text: control.querySelector(`[data-date-text="${side}"]`),
            calendar: control.querySelector(`[data-date-calendar="${side}"]`),
            button: control.querySelector(`[data-date-open="${side}"]`),
        }));
        const toDisplay = iso => iso.split('-').reverse().join('-');
        const dates = value.value.trim().match(/^(\d{4}-\d{2}-\d{2})(?:\s*(?:to|[-\u2013\u2014])\s*(\d{4}-\d{2}-\d{2}))?$/);
        fields.forEach((field, index) => {
            if (dates) {
                field.calendar.value = dates[index + 1] || dates[1];
                field.text.value = toDisplay(field.calendar.value);
            }
            field.button.addEventListener('click', () => {
                const match = field.text.value.trim().match(/^(\d{2})-(\d{2})-(\d{4})$/);
                field.calendar.value = match ? `${match[3]}-${match[2]}-${match[1]}` : '';
                if (typeof field.calendar.showPicker === 'function') field.calendar.showPicker();
                else field.text.focus();
            });
            field.calendar.addEventListener('change', () => {
                field.text.value = toDisplay(field.calendar.value);
                field.text.setCustomValidity('');
            });
            field.text.addEventListener('input', () => field.text.setCustomValidity(''));
        });
        control.closest('form').addEventListener('submit', event => {
            const parsed = fields.map(field => {
                field.text.setCustomValidity('');
                const raw = field.text.value.trim();
                if (!raw) return '';
                const match = raw.match(/^(\d{2})-(\d{2})-(\d{4})$/);
                const iso = match ? `${match[3]}-${match[2]}-${match[1]}` : '';
                field.calendar.value = iso;
                if (!iso || field.calendar.value !== iso || !field.calendar.validity.valid) {
                    field.text.setCustomValidity('Enter a valid date as dd-mm-yyyy.');
                }
                return iso;
            });
            if (parsed[0] && parsed[1] && parsed[1] < parsed[0]) {
                fields[1].text.setCustomValidity('To date must be on or after From date.');
            }
            if (fields.some(field => !field.text.validity.valid)) {
                event.preventDefault();
                fields.find(field => !field.text.validity.valid).text.reportValidity();
                return;
            }
            const from = parsed[0] || parsed[1];
            const to = parsed[1] || parsed[0];
            value.value = from ? `${from} to ${to}` : '';
        });
    });
})();
