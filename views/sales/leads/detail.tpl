<p><a href="{$urls.leads}">Back to leads</a></p>
<dl class="panel">
  <dt>Contact name</dt><dd>{$data.lead.contact_name}</dd>
  <dt>Business name</dt><dd>{$data.lead.business_name}</dd>
  <dt>Email</dt><dd>{$data.lead.email}</dd>
  <dt>Phone</dt><dd>{$data.lead.phone}</dd>
  <dt>Source</dt><dd>{$data.lead.source}</dd>
  <dt>Status</dt><dd>{$data.lead.status}</dd>
  <dt>Notes</dt><dd>{$data.lead.notes}</dd>
  <dt>Archived</dt><dd>{if $data.lead.archived}Yes{else}No{/if}</dd>
  <dt>Version</dt><dd>{$data.lead.current_version}</dd>
  <dt>Created (UTC) / user ID</dt><dd>{$data.lead.created_at} / {$data.lead.created_by}</dd>
  <dt>Updated (UTC) / user ID</dt><dd>{$data.lead.updated_at} / {$data.lead.updated_by}</dd>
</dl>
{if !$data.lead.archived && $data.leadPermissions.update}<p><a href="{$data.editUrl}">Edit lead</a></p>{/if}
{if (!$data.lead.archived && $data.leadPermissions.archive) || ($data.lead.archived && $data.leadPermissions.restore)}
  <noscript><p class="error">JavaScript is required to archive or restore leads.</p></noscript>
  <form class="lead-form panel" method="post" action="{$urls.leadApi}"
    data-operation="{if $data.lead.archived}restore{else}archive{/if}" data-id="{$data.lead.id}" data-version="{$data.lead.current_version}" data-can-read="1">
    <input type="hidden" name="csrf_token" value="{$csrf}">
    <input type="hidden" name="action_reference" value="{$data.actionReference}">
    <p>{if $data.lead.archived}Restore this lead to allow editing again.{else}Archive this lead to hide it from active lists. Its history will be preserved.{/if}</p>
    <button type="submit">{if $data.lead.archived}Restore lead{else}Archive lead{/if}</button>
    <p class="form-status" role="status"></p>
  </form>
{/if}
