{if $data.leadPermissions.read}<p><a href="{$urls.leads}">Back to leads</a></p>{/if}
<p>Contact name and at least one of email or phone are required. Status can be changed directly; qualification does not create a customer.</p>
<noscript><p class="error">JavaScript is required to save leads. No changes will be saved without it.</p></noscript>
<form class="lead-form panel" method="post" action="{$urls.leadApi}" data-operation="{$data.operation}" data-can-read="{if $data.leadPermissions.read}1{else}0{/if}"
  {if $data.operation === 'update'}data-id="{$data.lead.id}" data-version="{$data.lead.current_version}"{/if}>
  <input type="hidden" name="csrf_token" value="{$csrf}">
  <input type="hidden" name="action_reference" value="{$data.actionReference}">
  <label>Contact name <input name="contact_name" required maxlength="150" value="{$data.lead.contact_name}"></label>
  <label>Business name <input name="business_name" maxlength="150" value="{$data.lead.business_name}"></label>
  <label>Email <input type="email" name="email" maxlength="254" value="{$data.lead.email}"></label>
  <label>Phone <input type="tel" name="phone" maxlength="50" value="{$data.lead.phone}"></label>
  <label>Source
    <select name="source">{foreach $data.sources as $source}<option value="{$source}"{if $data.lead.source === $source} selected{/if}>{$source}</option>{/foreach}</select>
  </label>
  <label>Status
    <select name="status">{foreach $data.statuses as $status}<option value="{$status}"{if $data.lead.status === $status} selected{/if}>{$status}</option>{/foreach}</select>
  </label>
  <label>Notes <textarea name="notes" maxlength="1000" rows="5">{$data.lead.notes}</textarea></label>
  <button type="submit">{if $data.operation === 'create'}Create lead{else}Save lead{/if}</button>
  <p class="form-status" role="status"></p>
</form>
