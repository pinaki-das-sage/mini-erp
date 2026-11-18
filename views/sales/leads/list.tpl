{if $data.leadPermissions.create}<p><a href="{$urls.createLead}">Create lead</a></p>{/if}
<form method="get" action="{$urls.leads}" class="panel">
  <label>Search leads <input name="search" value="{$data.filters.search}" maxlength="150"></label>
  <label>Status
    <select name="status">
      <option value="">All statuses</option>
      {foreach $data.statuses as $status}<option value="{$status}"{if $data.filters.status === $status} selected{/if}>{$status}</option>{/foreach}
    </select>
  </label>
  <label>Source
    <select name="source">
      <option value="">All sources</option>
      {foreach $data.sources as $source}<option value="{$source}"{if $data.filters.source === $source} selected{/if}>{$source}</option>{/foreach}
    </select>
  </label>
  <label>Archived
    <select name="archived">
      <option value="false"{if $data.filters.archived === 'false'} selected{/if}>Active only</option>
      <option value="true"{if $data.filters.archived === 'true'} selected{/if}>Archived only</option>
      <option value="all"{if $data.filters.archived === 'all'} selected{/if}>All leads</option>
    </select>
  </label>
  <label>Page size <input name="page_size" type="number" min="1" max="100" value="{$data.page_size}"></label>
  <button type="submit">Filter leads</button>
</form>
<table id="leads-table">
  <thead><tr><th>Reference</th><th>Contact</th><th>Business</th><th>Email</th><th>Phone</th><th>Status</th><th>Source</th><th>Archived</th></tr></thead>
  <tbody>
    {foreach $data.items as $lead}
      <tr>
        <td><a href="{$lead.url}">{$lead.lead_reference}</a></td><td>{$lead.contact_name}</td>
        <td>{$lead.business_name}</td><td>{$lead.email}</td><td>{$lead.phone}</td>
        <td>{$lead.status}</td><td>{$lead.source}</td><td>{if $lead.archived}Yes{else}No{/if}</td>
      </tr>
    {foreachelse}
      <tr><td colspan="8">No leads match these filters.</td></tr>
    {/foreach}
  </tbody>
</table>
<nav aria-label="Lead pages">
  {if $data.previousUrl}<a href="{$data.previousUrl}">Previous page</a>{/if}
  <span>Page {$data.page}</span>
  {if $data.nextUrl}<a href="{$data.nextUrl}">Next page</a>{/if}
</nav>
