<p>New non-admin users start without business permissions. Temporary passwords require a change at first login.</p>
<form class="admin-form panel" method="post" action="{$urls.userApi}" data-operation="create">
  <input type="hidden" name="csrf_token" value="{$csrf}">
  <label>Username <input name="username" required pattern="[a-z0-9][a-z0-9._-]{ldelim}2,99{rdelim}" maxlength="100"></label>
  <label>Display name <input name="display_name" required maxlength="150"></label>
  <label>Temporary password <input type="password" name="password" autocomplete="new-password" required minlength="12" maxlength="72"></label>
  <label class="checkbox"><input type="checkbox" name="is_admin"> Administrator (all registered permissions and Settings)</label>
  <button type="submit">Create user</button>
  <p class="form-status" role="status"></p>
</form>
