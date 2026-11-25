'use strict';

const invoiceForm = document.querySelector('#invoice-form');

if (invoiceForm) {
  const lines = document.querySelector('#invoice-lines');
  const template = document.querySelector('#invoice-line-template');
  const addButton = document.querySelector('#add-invoice-line');
  const warehouse = document.querySelector('#invoice-warehouse');
  const customer = document.querySelector('#invoice-customer');
  const terms = document.querySelector('#invoice-payment-terms');
  const totalOutput = document.querySelector('#invoice-total');
  const status = document.querySelector('#invoice-form-status');
  const invoiceDate = invoiceForm.elements.invoice_date;
  const dueDate = invoiceForm.elements.due_date;

  // Exact cents: avoids floating-point rounding in monetary previews.
  const maximumCents = 999999999999999999n;

  const rows = () => [...lines.querySelectorAll('[data-invoice-line]')];

  const formatMoney = (cents) =>
    `${cents / 100n}.${String(cents % 100n).padStart(2, '0')}`;

  const renumberLines = () => {
    rows().forEach((row, index) => {
      for (const field of row.querySelectorAll('[name]')) {
        field.name = field.name.replace(
          /^lines\[[^\]]+\]/,
          `lines[${index}]`,
        );
      }
    });
  };

  const filterProducts = () => {
    for (const select of lines.querySelectorAll('[data-product-select]')) {
      for (const option of select.options) {
        if (!option.value) continue;

        const available =
          warehouse.value !== '' &&
          option.dataset.warehouse === warehouse.value;

        option.disabled = !available;
        option.hidden = !available;
      }

      if (select.selectedOptions[0]?.disabled) {
        select.value = '';
      }
    }
  };

  const calculateTotals = () => {
    let total = 0n;
    let valid = rows().length > 0;
    let tooLarge = false;

    for (const row of rows()) {
      const quantity = row.querySelector('[data-line-quantity]').value;
      const price = row.querySelector('[data-line-price]').value;
      const amountOutput = row.querySelector('[data-line-amount]');

      if (
        !/^[1-9][0-9]{0,8}$/.test(quantity) ||
        !/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/.test(price)
      ) {
        amountOutput.value = '-';
        valid = false;
        continue;
      }

      const [whole, fraction = ''] = price.split('.');
      const priceCents =
        BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));

      const amount = BigInt(quantity) * priceCents;

      if (amount > maximumCents) {
        amountOutput.value = '-';
        valid = false;
        tooLarge = true;
        continue;
      }

      amountOutput.value = formatMoney(amount);
      total += amount;
    }

    if (total > maximumCents) {
      valid = false;
      tooLarge = true;
    }

    totalOutput.value = valid ? formatMoney(total) : '-';

    status.textContent = tooLarge
      ? 'Invoice total exceeds the supported amount.'
      : valid
        ? ''
        : 'Enter a positive whole quantity and a valid price for every item.';

    return valid;
  };

  addButton?.addEventListener('click', () => {
    lines.append(template.content.cloneNode(true));
    renumberLines();
    filterProducts();
    calculateTotals();

    rows().at(-1).querySelector('[data-product-select]').focus();
  });

  lines.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;

    const button = event.target.closest('[data-remove-line]');
    if (!button) return;

    if (rows().length === 1) {
      status.textContent = 'An invoice must have at least one item.';
      return;
    }

    button.closest('[data-invoice-line]').remove();
    renumberLines();
    calculateTotals();
  });

  lines.addEventListener('input', calculateTotals);

  warehouse.addEventListener('change', () => {
    filterProducts();
    calculateTotals();
  });

  const isNew = !invoiceForm.querySelector('[name="expected_version"]');
  let useCustomerTerms = isNew && terms.value === '';

  customer.addEventListener('change', () => {
    if (useCustomerTerms && !terms.disabled) {
      terms.value =
        customer.selectedOptions[0]?.dataset.paymentTerms || '';
    }
  });

  terms.addEventListener('change', () => {
    useCustomerTerms = terms.value === '';
  });

  const updateDueDateMinimum = () => {
    dueDate.min = invoiceDate.value;
  };

  invoiceDate.addEventListener('change', updateDueDateMinimum);

  invoiceForm.addEventListener('submit', (event) => {
    if (!calculateTotals()) {
      event.preventDefault();
      status.tabIndex = -1;
      status.focus();
    }
  });

  renumberLines();
  filterProducts();
  calculateTotals();
  updateDueDateMinimum();
}