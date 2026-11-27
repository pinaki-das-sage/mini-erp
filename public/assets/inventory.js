'use strict';

const inventoryUrl = (path) => `${document.body.dataset.basePath}${path}`;

const productForm = document.querySelector('#product-form');
if (productForm) {
  productForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const status = productForm.querySelector('.form-status');
    const button = productForm.querySelector('button[type="submit"]');
    button.disabled = true;
    status.textContent = '';
    try {
      const response = await fetch(inventoryUrl('/api/inventory/products'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': productForm.elements.csrf_token.value },
        body: JSON.stringify({
          name: productForm.elements.name.value.trim(),
          sku: productForm.elements.sku.value.trim(),
          unitLabel: productForm.elements.unitLabel.value.trim(),
          unitWeightKg: productForm.elements.unitWeightKg.value.trim(),
        }),
      });
      const result = await response.json();
      if (!response.ok || !result.success) {
        status.textContent = result.error.message;
        return;
      }
      window.location.assign(inventoryUrl(`/modules/inventory/products?q=${encodeURIComponent(result.result.sku)}`));
    } catch {
      status.textContent = 'No confirmed response. Check the product list before trying again.';
    } finally {
      button.disabled = false;
    }
  });
}

const editForm = document.querySelector('#product-edit-form');
if (editForm) {
  editForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const status = editForm.querySelector('.form-status');
    const button = editForm.querySelector('button[type="submit"]');
    button.disabled = true;
    status.textContent = '';
    try {
      const response = await fetch(inventoryUrl(`/api/inventory/products/update/${editForm.dataset.productId}`), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': editForm.elements.csrf_token.value },
        body: JSON.stringify({
          name: editForm.elements.name.value.trim(),
          unitLabel: editForm.elements.unitLabel.value.trim(),
          unitWeightKg: editForm.elements.unitWeightKg.value.trim(),
          active: editForm.elements.active.checked,
        }),
      });
      const result = await response.json();
      if (!response.ok || !result.success) {
        status.textContent = result.error.message;
        return;
      }
      window.location.assign(inventoryUrl(`/modules/inventory/products/${editForm.dataset.productId}`));
    } catch {
      status.textContent = 'No confirmed response. Open the product to check before trying again.';
    } finally {
      button.disabled = false;
    }
  });
}

const deleteForm = document.querySelector('#product-delete-form');
if (deleteForm) {
  deleteForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!window.confirm(`Delete product ${deleteForm.dataset.sku}? This cannot be undone.`)) {
      return;
    }
    const status = deleteForm.querySelector('.form-status');
    const button = deleteForm.querySelector('button[type="submit"]');
    button.disabled = true;
    status.textContent = '';
    try {
      const response = await fetch(inventoryUrl(`/api/inventory/products/delete/${deleteForm.dataset.productId}`), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': deleteForm.elements.csrf_token.value },
        body: '{}',
      });
      const result = await response.json();
      if (!response.ok || !result.success) {
        status.textContent = result.error.message;
        return;
      }
      window.location.assign(inventoryUrl('/modules/inventory/products'));
    } catch {
      status.textContent = 'No confirmed response. Check the product list before trying again.';
    } finally {
      button.disabled = false;
    }
  });
}