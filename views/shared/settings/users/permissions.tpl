{if $data.user.isAdmin}
  <section class="panel"><h2>Permissions</h2><p>All registered permissions. Administrators cannot bypass business restrictions.</p></section>
{else}
<form class="admin-form panel" method="post" action="{$urls.userApi}" data-operation="permissions" data-id="{$data.user.ID}" data-version="{$data.user.version}">
  <h2>Permissions</h2>
  <input type="hidden" name="csrf_token" value="{$csrf}">
  {foreach $permissionGroups as $group}
    <h3>{$group.name}</h3>
    {foreach $group.objects as $object}
      <fieldset><legend>{$object.name}</legend>
        {foreach $object.controls as $control}
          <label class="checkbox"><input type="checkbox" name="grants" value="{$control.value}" {if $control.checked}checked{/if}> {$control.label}</label>
        {/foreach}
      </fieldset>
    {/foreach}
  {/foreach}
  <button type="submit">Save permissions</button>
  <p class="form-status" role="status"></p>
</form>
{/if}
