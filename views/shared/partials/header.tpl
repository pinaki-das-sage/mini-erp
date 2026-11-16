<header>
  <a class="brand" href="{$urls.home}">Mini ERP</a>
  {if $actor}
    <span class="user-label">{$actor.username} / {$actor.companyName} / {if $actor.isAdmin}Admin{else}User{/if}</span>
    <form action="{$urls.logout}" method="post">
      <input type="hidden" name="csrf_token" value="{$csrf}">
      <button type="submit">Sign out</button>
    </form>
  {/if}
</header>
