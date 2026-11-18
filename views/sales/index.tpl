<p>This is the sales team's workspace.</p>
<p>Leads is the independent entity blueprint. Customer, order and fulfillment workflows remain team work.</p>
{if isset($data.leadPermissions) && $data.leadPermissions.read}
  <p><a href="{$urls.leads}">Leads</a> - sample entity blueprint.</p>
{/if}
{if isset($data.leadPermissions) && $data.leadPermissions.create}
  <p><a href="{$urls.createLead}">Create lead</a></p>
{/if}
