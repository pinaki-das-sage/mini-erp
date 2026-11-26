<link rel="stylesheet" href="{$data.styleUrl}">
<div class="sales-document">
    <p><a href="{$data.viewUrl}">Back to {$data.kind}</a></p>
    <div class="panel">
        <h2>{$data.label} {$data.number}</h2><p>{$data.message}</p>
        <dl class="document-summary">
            <div><dt>Customer</dt><dd>{$data.document.customer_code}</dd></div>
            <div><dt>Status</dt><dd>{$data.document.status}</dd></div>
            <div><dt>Total</dt><dd>{$data.document.document_total}</dd></div>
        </dl>
        <form method="post" action="{$data.actionUrl}">
            <input type="hidden" name="csrf_token" value="{$csrf}">
            <input type="hidden" name="action_reference" value="{$data.actionReference}">
            <input type="hidden" name="expected_version" value="{$data.expectedVersion}">
            <div class="document-toolbar"><button type="submit">{$data.label}</button><a href="{$data.viewUrl}">Cancel</a></div>
        </form>
        {if $data.error}<p><a href="{$data.viewUrl}">Reload the latest {$data.kind} before trying again.</a></p>{/if}
    </div>
</div>
