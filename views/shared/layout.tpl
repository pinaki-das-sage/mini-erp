<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$title}</title>
  <link rel="stylesheet" href="{$urls.css}">
  <script src="{$urls.script}" defer></script>
</head>
<body class="{if $loginPage}login-page{else}app-page{/if}" data-base-path="{$basePath}">
{include file='shared/partials/header.tpl'}
<div class="app-layout">
  {if !$loginPage}{include file='shared/partials/sidebar.tpl'}{/if}
<main>
  <h1>{$title}</h1>
  {if isset($data.error)}<p class="error" role="alert">{$data.error}</p>{/if}
  {include file=$contentTemplate}
</main>
</div>
{include file='shared/partials/footer.tpl'}
</body>
</html>
