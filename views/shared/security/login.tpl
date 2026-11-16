<p>Enter your company identifier and account credentials.</p>
<form method="post" action="{$urls.login}">
  <input type="hidden" name="csrf_token" value="{$csrf}">
  <label>Company <input name="company" autocomplete="organization" required maxlength="50" placeholder="Company identifier"></label>
  <label>Username <input name="username" autocomplete="username" required maxlength="100"></label>
  <label>Password <input type="password" name="password" autocomplete="current-password" required></label>
  <button type="submit">Sign in</button>
</form>
