<div class="filters">
  <label>Search users <input id="user-search" type="search" placeholder="Username or display name"></label>
  <div><label for="user-status">Status</label><select id="user-status"><option value="">All</option><option value="1">Active</option><option value="0">Inactive</option></select></div>
</div>
<div class="table-scroll"><table id="users-table">
  <thead><tr><th>Username</th><th>Display name</th><th>Type</th><th>Status</th><th>Actions</th></tr></thead>
  <tbody>
    {foreach $data.users as $user}
      <tr data-active="{if $user.active}1{else}0{/if}">
        <td>{$user.USERNAME}</td>
        <td>{$user.displayName}</td>
        <td>{if $user.isAdmin}Admin{else}Non-admin{/if}</td>
        <td>{if $user.active}Active{else}Inactive{/if}</td>
        <td><a href="{$user.editUrl}">Edit / permissions</a></td>
      </tr>
    {/foreach}
  </tbody>
</table></div>
