<p>One application. Four independently owned modules.</p>
<p>The shared shell is running. ERP workflows are intentionally left to the graduate teams.</p>
<section class="cards">
  {foreach $modules as $module}
    <article><h2><a href="{$module.url}">{$module.name}</a></h2><p>{$module.description}</p></article>
  {/foreach}
</section>
