<nav class="sidebar" aria-label="Main navigation">
  <p class="nav-label">Workspace</p>
  <a href="{$urls.home}">Home</a>
  {foreach $modules as $module}
    <a href="{$module.url}">{$module.name}</a>
  {/foreach}
  <a href="{$urls.example}">Neutral example</a>
  <a href="{$urls.password}">Change password</a>
  {if $actor && $actor.isAdmin}
    <p class="nav-label">Settings</p>
    <a href="{$urls.company}">Company</a>
    <a href="{$urls.users}">Users &amp; permissions</a>
  {/if}
</nav>
