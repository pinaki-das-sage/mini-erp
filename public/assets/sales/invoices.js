'use strict';

const invoiceForm = document.querySelector('#invoice-form');
if (invoiceForm) {
  const invoiceDate = invoiceForm.elements.invoice_date;
  const dueDate = invoiceForm.elements.due_date;
  if (invoiceDate && dueDate) {
    const updateMinimum = () => { dueDate.min = invoiceDate.value; };
    invoiceDate.addEventListener('change', updateMinimum);
    updateMinimum();
  }
  invoiceForm.addEventListener('submit', () => {
    for (const button of invoiceForm.querySelectorAll('button[type="submit"]')) button.disabled = true;
  });
  window.addEventListener('pageshow', () => {
    for (const button of invoiceForm.querySelectorAll('button[type="submit"]')) button.disabled = false;
  });
}
