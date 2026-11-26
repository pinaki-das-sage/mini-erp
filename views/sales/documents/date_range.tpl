<div class="sales-date-range" data-date-range>
    <label for="sales-date-range">Date from/to
        <input id="sales-date-range" name="date_range" value="{$data.filters.date_range}"
               placeholder="2026-11-24 to 2026-11-25" maxlength="32" aria-describedby="date-range-help" data-range-text>
    </label>
    <details data-range-picker>
        <summary>Choose dates</summary>
        <div class="sales-date-popup">
            <label>From<input type="date" data-range-start></label>
            <label>To<input type="date" data-range-end></label>
            <p class="error" role="alert" data-range-error hidden>Choose a valid end date on or after the start date.</p>
            <button type="button" data-range-apply>Use date range</button>
        </div>
    </details>
    <small id="date-range-help">YYYY-MM-DD to YYYY-MM-DD, or use a dash between dates.</small>
</div>
