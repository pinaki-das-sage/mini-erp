<section class="logistics-workspace" id="logistics-workspace">
  <p class="logistics-intro">Coordinate trips, maintain driver profiles, and review the available fleet. Sales Order lines, capacity validation, Sales state changes, and Inventory effects are validated by the server.</p>
  <input type="hidden" id="logistics-csrf" value="{$csrf}">
  <p id="logistics-capabilities-error" class="error" role="alert" hidden></p>

  <div class="logistics-tabs" role="tablist" aria-label="Logistics workspace">
    <button type="button" class="logistics-tab" id="trips-tab" role="tab" aria-controls="trips-panel" aria-selected="true">Trip Operations</button>
    <button type="button" class="logistics-tab" id="drivers-tab" role="tab" aria-controls="drivers-panel" aria-selected="false" tabindex="-1">Drivers</button>
    <button type="button" class="logistics-tab" id="vehicles-tab" role="tab" aria-controls="vehicles-panel" aria-selected="false" tabindex="-1">Vehicles</button>
  </div>

  <section class="logistics-panel" id="trips-panel" role="tabpanel" aria-labelledby="trips-tab">
    <div class="logistics-section-heading">
      <div>
        <h2>Trip Operations</h2>
        <p>One whole submitted Sales Order per Trip; its complete Sales Order lines are used. Trips move from Draft to Dispatched, then Delivered, or may be Cancelled while Draft.</p>
      </div>
      <div class="logistics-toolbar">
        <button type="button" id="create-trip-button" hidden>Create Trip</button>
        <button type="button" class="button-secondary" id="trips-refresh-button">Refresh</button>
      </div>
    </div>
    <p class="logistics-note">Assigning a Trip moves its Submitted Sales Order to In Progress. Cancelling a Draft Trip returns the Order to Submitted; dispatch requires it to remain In Progress.</p>
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
    <p id="drivers-status" class="logistics-status" role="status" aria-live="polite"></p>
    <p id="drivers-error" class="error" role="alert" hidden></p>
    <div class="table-scroll">
      <table>
        <thead>
          <tr>
            <th scope="col">Driver code</th>
            <th scope="col">First name</th>
            <th scope="col">Last name</th>
            <th scope="col">Linked user</th>
            <th scope="col">Phone</th>
            <th scope="col">Licence number</th>
            <th scope="col">Licence expiry</th>
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
  </section>

  <section class="logistics-panel" id="vehicles-panel" role="tabpanel" aria-labelledby="vehicles-tab" hidden>
    <div class="logistics-section-heading">
      <div>
        <h2>Vehicles</h2>
        <p>Maintain vehicles and review current trip availability. Capacity is shown in kilograms to three decimal places.</p>
      </div>
      <div class="logistics-toolbar">
        <button type="button" id="create-vehicle-button" hidden>Create Vehicle</button>
        <button type="button" class="button-secondary" id="vehicles-refresh-button">Refresh</button>
      </div>
    </div>
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
  </section>
</section>

<dialog id="trip-dialog" class="logistics-dialog" aria-labelledby="trip-dialog-title">
  <form id="trip-form">
    <h2 id="trip-dialog-title">Create Trip</h2>
    <p id="trip-form-error" class="error" role="alert" hidden></p>
    <label for="trip-order-search">Search Submitted Sales Orders
      <span class="logistics-inline-search">
        <input id="trip-order-search" type="search" maxlength="100" autocomplete="off">
        <button type="button" class="button-secondary" id="search-trip-orders">Search</button>
      </span>
    </label>
    <label for="trip-order-number">Sales Order
      <select id="trip-order-number" name="order_number" required></select>
    </label>
    <p id="trip-order-status" class="logistics-help" role="status" aria-live="polite"></p>
    <label for="trip-code">Trip code (positive integer)
      <input id="trip-code" name="trip_code" type="number" required min="1" step="1">
    </label>
    <label for="trip-vehicle-id">Vehicle
      <select id="trip-vehicle-id" name="vehicle_id" required></select>
    </label>
    <label for="trip-driver-id">Driver
      <select id="trip-driver-id" name="driver_id" required></select>
    </label>
    <label for="trip-departure">Planned departure (your local time)
      <input id="trip-departure" name="planned_departure_at" type="datetime-local" required step="60">
    </label>
    <div class="logistics-dialog-actions">
      <button type="submit" id="trip-submit-button">Create Trip</button>
      <button type="button" class="button-secondary" data-close-dialog>Close</button>
    </div>
  </form>
</dialog>

<dialog id="vehicle-dialog" class="logistics-dialog" aria-labelledby="vehicle-dialog-title">
  <form id="vehicle-form">
    <h2 id="vehicle-dialog-title">Create Vehicle</h2>
    <p id="vehicle-form-error" class="error" role="alert" hidden></p>
    <label for="vehicle-code">Vehicle code
      <input id="vehicle-code" name="vehicle_code" type="number" required min="1" step="1">
    </label>
    <label for="vehicle-registration">Registration number
      <input id="vehicle-registration" name="registration_number" type="text" required maxlength="40">
    </label>
    <label for="vehicle-description">Description (optional)
      <input id="vehicle-description" name="description" type="text" maxlength="255">
    </label>
    <label for="vehicle-capacity">Capacity KG
      <input id="vehicle-capacity" name="capacity_kg" type="number" required min="0.001" max="999999999.999" step="0.001">
    </label>
    <label for="vehicle-isactive">Active status
      <select id="vehicle-isactive" name="isactive" required>
        <option value="T">Active</option>
        <option value="F">Inactive</option>
      </select>
    </label>
    <div class="logistics-dialog-actions">
      <button type="submit" id="vehicle-submit-button">Create Vehicle</button>
      <button type="button" class="button-secondary" data-close-dialog>Close</button>
    </div>
  </form>
</dialog>

<dialog id="driver-dialog" class="logistics-dialog" aria-labelledby="driver-dialog-title">
  <form id="driver-form">
    <h2 id="driver-dialog-title">Create Driver</h2>
    <p id="driver-form-error" class="error" role="alert" hidden></p>
    <label for="driver-code">Driver code
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
    <label for="driver-first-name">First name
      <input id="driver-first-name" name="first_name" type="text" required maxlength="100">
    </label>
    <label for="driver-last-name">Last name
      <input id="driver-last-name" name="last_name" type="text" required maxlength="100">
    </label>
    <label for="driver-phone">Phone (optional)
      <input id="driver-phone" name="phone" type="tel" maxlength="30" pattern="[0-9+() -]*">
    </label>
    <label for="driver-licence-number">Licence number
      <input id="driver-licence-number" name="licence_number" type="text" required maxlength="50">
    </label>
    <label for="driver-licence-expiry">Licence expiry
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
