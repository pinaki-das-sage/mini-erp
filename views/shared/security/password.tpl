<p>Temporary passwords must be changed before accessing other pages. After changing your password, sign in again.</p>
<form method="post" action="{$urls.password}">
  <input type="hidden" name="csrf_token" value="{$csrf}">
  <label>Current password <input type="password" name="current_password" autocomplete="current-password" required></label>
  <label>New password <input type="password" name="new_password" autocomplete="new-password" required minlength="12" maxlength="72"></label>
  <button type="submit">Change password</button>
</form>
