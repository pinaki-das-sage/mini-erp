{if $badgeStatus === null || $badgeStatus === '' || $badgeStatus === '-'}
    &mdash;
{else}
    <span class="sales-status sales-status--{if $badgeStatus === 'Active' || ($badgeKind === 'customer' && $badgeStatus === 'T') || $badgeStatus === 'Paid' || $badgeStatus === 'Closed' || ($badgeStatus === 'Submitted' && $badgeKind !== 'order')}success{elseif $badgeStatus === 'In Progress' || $badgeStatus === 'Partially Paid'}warning{elseif $badgeStatus === 'Unpaid'}danger{elseif $badgeStatus === 'Submitted' && $badgeKind === 'order'}info{else}neutral{/if}">{if $badgeKind === 'customer' && $badgeStatus === 'T'}Active{elseif $badgeKind === 'customer' && $badgeStatus === 'F'}Inactive{else}{$badgeStatus}{/if}</span>
{/if}
