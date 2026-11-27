<input type="hidden" name="date_range" value="{$data.filters.date_range}" data-sales-dates data-date-value>
<label class="sales-filter-from">From date
    <span class="sales-list-date-input">
        <input type="text" placeholder="dd-mm-yyyy" maxlength="10" inputmode="numeric" data-date-text="from" aria-label="From date, dd-mm-yyyy">
        <input type="date" data-date-calendar="from" aria-label="Choose From date from calendar" tabindex="-1">
        <button type="button" data-date-open="from" aria-label="Open From calendar">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/></svg>
        </button>
    </span>
</label>
<label class="sales-filter-to">To date
    <span class="sales-list-date-input">
        <input type="text" placeholder="dd-mm-yyyy" maxlength="10" inputmode="numeric" data-date-text="to" aria-label="To date, dd-mm-yyyy">
        <input type="date" data-date-calendar="to" aria-label="Choose To date from calendar" tabindex="-1">
        <button type="button" data-date-open="to" aria-label="Open To calendar">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/></svg>
        </button>
    </span>
</label>
