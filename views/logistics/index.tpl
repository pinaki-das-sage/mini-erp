<section class="logistics-workspace" id="logistics-workspace">
  <p class="logistics-intro">Manage your fleet, coordinate deliveries, and follow every trip line in one place.</p>
  <input type="hidden" id="logistics-csrf" value="{$csrf}">
  <p id="logistics-capabilities-error" class="error" role="alert" hidden></p>

  <div class="logistics-tabs" role="tablist" aria-label="Logistics workspace">
    <button type="button" class="logistics-tab" id="trips-tab" role="tab" aria-controls="trips-panel"
      aria-selected="true">Trips</button>
    <button type="button" class="logistics-tab" id="drivers-tab" role="tab" aria-controls="drivers-panel"
      aria-selected="false" tabindex="-1">Drivers</button>
    <button type="button" class="logistics-tab" id="vehicles-tab" role="tab" aria-controls="vehicles-panel"
      aria-selected="false" tabindex="-1">Vehicles</button>
    <button type="button" class="logistics-tab" id="trip-lines-tab" role="tab" aria-controls="trip-lines-panel"
      aria-selected="false" tabindex="-1">Trip Lines</button>
  </div>

  <section class="logistics-panel" id="trips-panel" role="tabpanel" aria-labelledby="trips-tab">
    <div class="logistics-section-heading">
      <div>
        <h2>Trips</h2>
        <p>Plan deliveries and track progress from draft to delivered. Each trip carries one complete sales order.</p>
      </div>
      <div class="logistics-toolbar">
        <button type="button" id="create-trip-button" hidden>Create Trip</button>
        <button type="button" class="button-secondary" id="trips-refresh-button">Refresh</button>
      </div>
    </div>
    <p class="logistics-note">Draft assignment keeps the Sales Order Submitted. Dispatch moves it to In Progress, and complete delivery closes it.</p>
    <p id="trips-status" class="logistics-status" role="status" aria-live="polite"></p>
    <p id="trips-error" class="error" role="alert" hidden></p>
    <div class="table-scroll">
      <table>
        <thead>
          <tr>
            <th scope="col">Trip code</th>
            <th scope="col">Sales order ID</th>
            <th scope="col">Vehicle ID</th>
            <th scope="col">Driver ID</th>
            <th scope="col">Planned departure</th>
            <th scope="col">Status</th>
            <th scope="col">Planned load (KG)</th>
            <th scope="col">Capacity (KG)</th>
            <th scope="col">Remaining (KG)</th>
            <th scope="col">Actions</th>
          </tr>
        </thead>
        <tbody id="trips-table-body"></tbody>
      </table>
    </div>
    <div class="logistics-pagination">
      <button type="button" class="button-secondary" id="trips-previous" disabled>Previous</button>
      <span id="trips-page-label">Page 1</span>
      <button type="button" class="button-secondary" id="trips-next" disabled>Next</button>
    </div>
  </section>

  <section class="logistics-panel" id="drivers-panel" role="tabpanel" aria-labelledby="drivers-tab" hidden>
    <div class="logistics-section-heading">
      <div>
        <h2>Drivers</h2>
        <p>Maintain driver identity, licence details, linked user, and active state.</p>
      </div>
      <div class="logistics-toolbar">
        <button type="button" id="create-driver-button" hidden>Create Driver</button>
        <button type="button" class="button-secondary" id="drivers-refresh-button">Refresh</button>
      </div>
    </div>
    <div class="logistics-fleet-layout"><div class="logistics-fleet-list">
    <p id="drivers-status" class="logistics-status" role="status" aria-live="polite"></p>
    <p id="drivers-error" class="error" role="alert" hidden></p>
    <div class="table-scroll">
      <table>
        <thead>
          <tr>
            <th scope="col">Driver code</th>
            <th scope="col">Driver name</th>
            <th scope="col">Linked user</th>
            <th scope="col">Phone</th>
            <th scope="col">Licence / expiry</th>
            <th scope="col">Active</th>
            <th scope="col">Actions</th>
          </tr>
        </thead>
        <tbody id="drivers-table-body"></tbody>
      </table>
    </div>
    <div class="logistics-pagination">
      <button type="button" class="button-secondary" id="drivers-previous" disabled>Previous</button>
      <span id="drivers-page-label">Page 1</span>
      <button type="button" class="button-secondary" id="drivers-next" disabled>Next</button>
    </div>
    </div>
<dialog id="driver-dialog" class="logistics-dialog logistics-fleet-editor" aria-labelledby="driver-dialog-title">
  <form id="driver-form">
    <h2 id="driver-dialog-title">Create Driver</h2>
    <p id="driver-form-error" class="error" role="alert" hidden></p>
    <label for="driver-code">Driver code *
      <input id="driver-code" name="driver_code" type="text" required maxlength="30">
    </label>
    <label for="driver-user-search">Find eligible user
      <span class="logistics-inline-search">
        <input id="driver-user-search" type="search" maxlength="100" autocomplete="off">
        <button type="button" class="button-secondary" id="search-driver-users">Search</button>
      </span>
    </label>
    <label for="driver-user-id">Linked user (optional)
      <select id="driver-user-id" name="user_id"></select>
    </label>
    <p id="driver-user-status" class="logistics-help" role="status" aria-live="polite"></p>
    <label for="driver-first-name">First name *
      <input id="driver-first-name" name="first_name" type="text" required maxlength="100">
    </label>
    <label for="driver-last-name">Last name *
      <input id="driver-last-name" name="last_name" type="text" required maxlength="100">
    </label>
    <label for="driver-phone">Phone (optional)
      <input id="driver-phone" name="phone" type="tel" maxlength="30" pattern="[0-9+() -]*">
    </label>
    <label for="driver-licence-number">Licence number *
      <input id="driver-licence-number" name="licence_number" type="text" required maxlength="50">
    </label>
    <label for="driver-licence-expiry">Licence expiry *
      <input id="driver-licence-expiry" name="licence_expiry" type="date" required>
    </label>
    <label for="driver-isactive">Active status
      <select id="driver-isactive" name="isactive" required>
        <option value="T">Active</option>
        <option value="F">Inactive</option>
      </select>
    </label>
    <div class="logistics-dialog-actions">
      <button type="submit" id="driver-submit-button">Create Driver</button>
      <button type="button" class="button-secondary" data-close-dialog>Close</button>
    </div>
  </form>
</dialog>
    </div>
  </section>

  <section class="logistics-panel" id="vehicles-panel" role="tabpanel" aria-labelledby="vehicles-tab" hidden>
    <div class="logistics-section-heading">
      <div>
        <h2>Vehicles</h2>
        <p>Maintain vehicles and review current trip availability. Capacity is shown in kilograms to three decimal
          places.</p>
      </div>
      <div class="logistics-toolbar">
        <button type="button" id="create-vehicle-button" hidden>Create Vehicle</button>
        <button type="button" class="button-secondary" id="vehicles-refresh-button">Refresh</button>
      </div>
    </div>
    <div class="logistics-fleet-layout"><div class="logistics-fleet-list">
    <p id="vehicles-status" class="logistics-status" role="status" aria-live="polite"></p>
    <p id="vehicles-error" class="error" role="alert" hidden></p>
    <div class="table-scroll">
      <table>
        <thead>
          <tr>
            <th scope="col">Vehicle code</th>
            <th scope="col">Registration</th>
            <th scope="col">Description</th>
            <th scope="col">Capacity (KG)</th>
            <th scope="col">Active</th>
            <th scope="col">Availability</th>
            <th scope="col">Actions</th>
          </tr>
        </thead>
        <tbody id="vehicles-table-body"></tbody>
      </table>
    </div>
    <div class="logistics-pagination">
      <button type="button" class="button-secondary" id="vehicles-previous" disabled>Previous</button>
      <span id="vehicles-page-label">Page 1</span>
      <button type="button" class="button-secondary" id="vehicles-next" disabled>Next</button>
    </div>
    </div>
<dialog id="vehicle-dialog" class="logistics-dialog logistics-fleet-editor" aria-labelledby="vehicle-dialog-title">
  <form id="vehicle-form">
    <h2 id="vehicle-dialog-title">Create Vehicle</h2>
    <p id="vehicle-form-error" class="error" role="alert" hidden></p>
    <label for="vehicle-code">Vehicle code *
      <input id="vehicle-code" name="vehicle_code" type="number" required min="1" step="1">
    </label>
    <label for="vehicle-registration">Registration number *
      <input id="vehicle-registration" name="registration_number" type="text" required maxlength="40">
    </label>
    <label for="vehicle-description">Description (optional)
      <textarea id="vehicle-description" name="description" maxlength="255" rows="3" aria-describedby="vehicle-description-count"></textarea><small id="vehicle-description-count" class="logistics-help">255 characters remaining</small>
    </label>
    <label for="vehicle-capacity">Capacity KG *
      <input id="vehicle-capacity" name="capacity_kg" type="number" required min="0.001" max="999999999.999"
        step="0.001">
    </label>
    <label for="vehicle-isactive">Active status
      <select id="vehicle-isactive" name="isactive" required>
        <option value="T">Active</option>
        <option value="F">Inactive</option>
      </select>
    </label>
    <p class="logistics-help vehicle-assignment-help">Capacity changes and deactivation require a free vehicle. Vehicle code and registration are protected after first trip use.</p>
    <div class="logistics-dialog-actions">
      <button type="submit" id="vehicle-submit-button">Create Vehicle</button>
      <button type="button" class="button-secondary" data-close-dialog>Close</button>
    </div>
  </form>
</dialog>
    </div>
  </section>

  <section class="logistics-panel" id="trip-lines-panel" role="tabpanel" aria-labelledby="trip-lines-tab" hidden>
    <div class="logistics-section-heading">
      <div>
        <h2>Trip Lines</h2>
        <p>Review the items carried on each trip, with linked sales and product details.</p>
      </div>
      <div class="logistics-toolbar"><button type="button" class="button-secondary"
          id="trip-lines-refresh-button">Refresh</button></div>
    </div>
    <form id="trip-lines-filter" class="logistics-filter"><label for="trip-lines-trip-id">Trip ID<input
          id="trip-lines-trip-id" type="number" min="1" step="1" placeholder="All trips"></label><button
        type="submit">Apply filter</button><button type="button" class="button-secondary"
        id="trip-lines-clear">Clear</button></form>
    <p id="trip-lines-status" class="logistics-status" role="status" aria-live="polite"></p>
    <p id="trip-lines-error" class="error" role="alert" hidden></p>
    <div class="table-scroll">
      <table aria-label="Trip lines">
        <thead>
          <tr>
            <th scope="col">Line ID</th>
            <th scope="col">Trip</th>
            <th scope="col">Product</th>
            <th scope="col">Sales line</th>
            <th scope="col">Quantity</th>
            <th scope="col">Load (KG)</th>
            <th scope="col">Trip status</th>
            <th scope="col">Details</th>
          </tr>
        </thead>
        <tbody id="trip-lines-table-body"></tbody>
      </table>
    </div>
    <div class="logistics-pagination"><button type="button" class="button-secondary" id="trip-lines-previous"
        disabled>Previous</button><span id="trip-lines-page-label">Page 1</span><button type="button"
        class="button-secondary" id="trip-lines-next" disabled>Next</button></div>
  </section>
</section>

<!-- Trip planner follows the reference layout: fields, sales lines, then summaries. -->
<dialog id="trip-dialog" class="logistics-dialog logistics-planner" aria-labelledby="trip-dialog-title">
  <form id="trip-form">
    <div class="planner-heading"><h2 id="trip-dialog-title">Draft Trip Planner</h2><span class="logistics-badge logistics-badge-draft">Draft</span></div>
    <p id="trip-form-error" class="error" role="alert" hidden></p>
    <section class="planner-card planner-fields" aria-label="Trip assignment">
      <label for="trip-code">Trip reference *<input id="trip-code" name="trip_code" type="number" required min="1" step="1"></label>
      <label for="trip-vehicle-id">Vehicle code *<select id="trip-vehicle-id" name="vehicle_id" required></select></label>
      <label for="trip-driver-id">Driver code *<select id="trip-driver-id" name="driver_id" required disabled></select></label>
      <label for="trip-departure">Planned departure *<input id="trip-departure" name="planned_departure_at" type="datetime-local" required step="60"></label>
      <p id="trip-driver-status" class="logistics-help" role="status" aria-live="polite">Available drivers will load when you open the planner.</p>
    </section>
    <section class="planner-card" id="planner-sales-section" aria-labelledby="planner-sales-title">
      <div class="planner-card-heading"><h3 id="planner-sales-title">Eligible Sales Order lines</h3><span class="planner-state-filter">Submitted and unassigned</span></div>
      <div class="planner-order-fields">
        <label for="trip-order-search">Find Sales Order<span class="logistics-inline-search"><input id="trip-order-search" type="search" maxlength="100" autocomplete="off"><button type="button" class="button-secondary" id="search-trip-orders">Search</button></span></label>
        <label for="trip-order-number">Sales Order *<select id="trip-order-number" name="order_number" required></select></label>
      </div>
      <p id="trip-order-status" class="logistics-help" role="status" aria-live="polite"></p>
      <div class="table-scroll"><table aria-label="Selected sales order lines"><thead><tr><th scope="col">Sales Order</th><th scope="col">Sales line / product</th><th scope="col">State</th><th scope="col">Line qty</th><th scope="col">Assignment</th></tr></thead><tbody id="planner-order-lines"><tr><td colspan="5" class="logistics-empty">Select an eligible Sales Order to see its lines.</td></tr></tbody></table></div>
      <p class="planner-rule">One Sales Order per Trip. Saving assigns all lines; the order stays Submitted until dispatch.</p>
    </section>
    <div class="planner-summaries">
      <section class="planner-card" aria-labelledby="planner-load-title"><h3 id="planner-load-title">Planned load / vehicle capacity</h3><p class="planner-load-value" id="planner-load-value">- / - KG</p><progress id="planner-capacity-progress" max="100" value="0" aria-label="Vehicle capacity used" hidden></progress><p class="logistics-help" id="planner-load-help">Choose a vehicle. Planned load is confirmed when the trip is saved.</p></section>
      <section class="planner-card" aria-labelledby="planner-inventory-title"><h3 id="planner-inventory-title">Inventory for selected order</h3><dl class="planner-inventory"><div><dt>Warehouse</dt><dd id="planner-warehouse">-</dd></div><div><dt>Order quantity</dt><dd id="planner-order-quantity">-</dd></div></dl><p class="logistics-help">Current stock balances are available in Trip Lines after saving.</p></section>
    </div>
    <div class="planner-footer"><p>* Required input. Product and quantity details come from the selected Sales Order.</p><div class="logistics-dialog-actions"><button type="button" class="button-secondary" data-close-dialog>Cancel</button><button type="submit" id="trip-submit-button">Save Draft</button></div></div>
  </form>
</dialog>





<dialog id="cancel-trip-dialog" class="logistics-dialog" aria-labelledby="cancel-trip-title">
  <form id="cancel-trip-form">
    <h2 id="cancel-trip-title">Cancel Trip</h2>
    <p id="cancel-trip-confirmation"></p>
    <p id="cancel-trip-error" class="error" role="alert" hidden></p>
    <label for="cancel-trip-reason">Reason (required, maximum 500 characters)
      <textarea id="cancel-trip-reason" name="reason" required maxlength="500"></textarea>
    </label>
    <div class="logistics-dialog-actions">
      <button type="submit" class="button-danger" id="cancel-trip-submit">Confirm cancellation</button>
      <button type="button" class="button-secondary" data-close-dialog>Keep Trip</button>
    </div>
  </form>
</dialog>

<dialog id="trip-line-dialog" class="logistics-dialog logistics-detail-dialog" aria-labelledby="trip-line-dialog-title">
  <div class="logistics-section-heading">
    <div>
      <p class="logistics-eyebrow">Trip line details</p>
      <h2 id="trip-line-dialog-title">Trip line</h2>
    </div><button type="button" class="button-secondary" data-close-dialog>Close</button>
  </div>
  <p id="trip-line-detail-status" role="status" aria-live="polite"></p>
  <p id="trip-line-detail-error" class="error" role="alert" hidden></p>
  <div id="trip-line-detail-content"></div>
</dialog>
<dialog id="logistics-record-dialog" class="logistics-dialog logistics-detail-dialog" aria-labelledby="logistics-record-title">
  <div class="logistics-section-heading"><div><p class="logistics-eyebrow">Fleet record</p><h2 id="logistics-record-title">Details</h2></div><button type="button" class="button-secondary" data-close-dialog>Close</button></div>
  <p id="logistics-record-status" role="status" aria-live="polite"></p><p id="logistics-record-error" class="error" role="alert" hidden></p><div id="logistics-record-content"></div>
</dialog>
<dialog id="trip-review-dialog" class="logistics-dialog logistics-trip-review" aria-labelledby="trip-review-title">
  <div class="logistics-section-heading"><div><p class="logistics-eyebrow">Trip operations</p><h2 id="trip-review-title">Trip details</h2></div><button type="button" class="button-secondary" data-close-dialog>Close</button></div>
  <p id="trip-review-status" role="status" aria-live="polite"></p><p id="trip-review-error" class="error" role="alert" hidden></p>
  <div id="trip-review-summary" class="logistics-review-summary"></div>
  <div class="logistics-review-capacity"><label for="trip-review-capacity">Vehicle capacity used</label><progress id="trip-review-capacity" max="100" value="0"></progress><p id="trip-review-capacity-label"></p></div>
  <section class="logistics-detail-section"><h3>Trip lines <span class="logistics-readonly">Read only</span></h3><p id="trip-review-lines-status" class="logistics-help" role="status"></p><div class="table-scroll"><table aria-label="Trip review lines"><thead><tr><th scope="col">Line ID</th><th scope="col">Sales line</th><th scope="col">Product</th><th scope="col">Quantity</th><th scope="col">Load (KG)</th></tr></thead><tbody id="trip-review-lines"></tbody></table></div><div class="logistics-pagination"><button type="button" class="button-secondary" id="trip-review-lines-previous">Previous</button><span id="trip-review-lines-page">Page 1</span><button type="button" class="button-secondary" id="trip-review-lines-next">Next</button></div></section>
  <details class="logistics-detail-section logistics-activity"><summary>Trip activity and cancellation reason</summary><div id="trip-review-activity"></div></details>
  <p id="trip-review-confirmation" class="logistics-note"></p>
  <div class="logistics-dialog-actions"><button type="button" class="button-secondary" id="trip-review-refresh">Refresh details</button><button type="button" id="trip-review-confirm" hidden>Confirm</button></div>
</dialog>
