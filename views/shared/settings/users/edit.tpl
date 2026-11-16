<p>Username: <strong>{$data.user.USERNAME}</strong>. Saving changes invalidates this user's existing sessions.</p>
<form class="admin-form panel" method="post" action="{$urls.userApi}" data-operation="update" data-id="{$data.user.ID}" data-version="{$data.user.version}">
  <h2>Account</h2>
  <input type="hidden" name="csrf_token" value="{$csrf}">
  <label>Display name <input name="display_name" value="{$data.user.displayName}" required maxlength="150"></label>
  <label class="checkbox"><input type="checkbox" name="is_admin" {if $data.user.isAdmin}checked{/if}> Administrator</label>
  <label class="checkbox"><input type="checkbox" name="active" {if $data.user.active}checked{/if}> Active</label>
  <button type="submit">Save account</button>
  <p class="form-status" role="status"></p>
</form>
{include file='shared/settings/users/permissions.tpl'}
<form class="admin-form panel" method="post" action="{$urls.userApi}" data-operation="reset_password" data-id="{$data.user.ID}" data-version="{$data.user.version}">
  <h2>Reset password</h2>
  <input type="hidden" name="csrf_token" value="{$csrf}">
  <label>New temporary password <input type="password" name="password" autocomplete="new-password" required minlength="12" maxlength="72"></label>
  <button type="submit">Reset password</button>
  <p class="form-status" role="status"></p>
</form>
