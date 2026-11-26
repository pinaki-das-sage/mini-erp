(() => {
    'use strict';
    const form = document.querySelector('#document-form');
    if (!form) return;
    const body = form.querySelector('[data-document-lines]');
    const rows = () => [...(body?.querySelectorAll('[data-document-line]') || [])];
    const money = cents => `${cents / 100n}.${String(cents % 100n).padStart(2, '0')}`;
    function recalculate() {
        let total = 0n;
        let valid = true;
        rows().forEach((row, index) => {
            row.querySelector('[data-line-number]').textContent = String(index + 1);
            row.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace(/lines\[\d+\]/, `lines[${index}]`); });
            row.querySelector('[data-move="up"]').disabled = index === 0;
            row.querySelector('[data-move="down"]').disabled = index === rows().length - 1;
            const quantity = row.querySelector('[data-quantity]');
            const price = row.querySelector('[data-price]').value;
            const validQuantity = /^[0-9]{1,9}$/.test(quantity.value) && BigInt(quantity.value) > 0n;
            quantity.setCustomValidity(validQuantity ? '' : 'Enter a positive whole-number quantity.');
            if (!validQuantity || !/^[0-9]{1,10}(\.[0-9]{1,2})?$/.test(price)) {
                row.querySelector('[data-line-amount]').textContent = '—';
                valid = false;
                return;
            }
            const [whole, fraction = ''] = price.split('.');
            const amount = BigInt(quantity.value) * (BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0')));
            row.querySelector('[data-line-amount]').textContent = money(amount);
            total += amount;
        });
        const output = form.querySelector('[data-document-total]');
        if (output) output.textContent = valid ? money(total) : '—';
        const error = form.querySelector('[data-items-error]');
        if (error) error.hidden = rows().length > 0;
    }
    form.querySelector('[data-add-line]')?.addEventListener('click', () => {
        const row = document.querySelector('#document-line-template').content.cloneNode(true);
        body.append(row);
        recalculate();
        rows().at(-1).querySelector('[name$="[product_id]"]').focus();
    });
    body?.addEventListener('click', event => {
        const button = event.target.closest('button');
        if (!button) return;
        const row = button.closest('[data-document-line]');
        if (button.hasAttribute('data-remove-line')) row.remove();
        if (button.dataset.move === 'up' && row.previousElementSibling) body.insertBefore(row, row.previousElementSibling);
        if (button.dataset.move === 'down' && row.nextElementSibling) body.insertBefore(row.nextElementSibling, row);
        recalculate();
    });
    form.addEventListener('input', recalculate);
    const date = form.querySelector('[data-document-date]');
    const dates = () => form.querySelectorAll('[data-following-date]').forEach(input => { input.min = date?.value || ''; });
    date?.addEventListener('change', dates);
    form.addEventListener('submit', event => {
        if (body && rows().length === 0) {
            event.preventDefault();
            form.querySelector('[data-add-line]').focus();
            return;
        }
        form.querySelectorAll('button[type="submit"]').forEach(button => { button.disabled = true; });
    });
    window.addEventListener('pageshow', () => form.querySelectorAll('button[type="submit"]').forEach(button => { button.disabled = false; }));
    recalculate();
    dates();
})();
