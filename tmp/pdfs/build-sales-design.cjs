const fs = require('fs');
const path = require('path');
const { chromium } = require('../../node_modules/playwright');

const root = path.resolve(__dirname, '../..');
const out = path.join(root, 'output/pdf/sales-and-order-entry-design.pdf');
fs.mkdirSync(path.dirname(out), { recursive: true });
const esc = x => String(x).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const ul = rows => '<ul>' + rows.map(x => `<li>${x}</li>`).join('') + '</ul>';
const ol = rows => '<ol>' + rows.map(x => `<li>${x}</li>`).join('') + '</ol>';
const table = (heads, rows, widths) => `<table>${widths ? '<colgroup>'+widths.map(w=>`<col style="width:${w}%">`).join('')+'</colgroup>' : ''}<thead><tr>${heads.map(x=>`<th>${x}</th>`).join('')}</tr></thead><tbody>${rows.map(r=>'<tr>'+r.map(x=>`<td>${x}</td>`).join('')+'</tr>').join('')}</tbody></table>`;
const pages = [];

pages.push(`
<h1>Sales and Order Entry Design</h1>
<p class="subtitle">Customer management, sales documents and received amounts</p>
<p class="meta">Draft for review &nbsp; | &nbsp; Team 3 &nbsp; | &nbsp; 18 November 2026</p>
<nav aria-label="Contents"><ul>
<li><a href="#introduction">Introduction</a></li><li><a href="#requirements">Requirements</a></li>
<li><a href="#scope">Scope</a></li><li><a href="#business">Business Requirements</a></li>
<li><a href="#action">Action Flow</a> <span class="small">- Flow Diagram and End to End Flow</span></li>
<li><a href="#technical">Design Decisions</a></li><li><a href="#validations">Edge Cases and Validations</a></li>
<li><a href="#impact">Impact on Other Modules</a></li></ul></nav>
<h2 id="introduction">Introduction</h2>
<p>Sales and Order Entry manages Customer information, quotations, sales orders, dispatch confirmation, invoicing and received amounts. Authorized users can prepare sales documents, carry business details forward from a source document and track what each Customer still owes.</p>
<p><strong>Background:</strong> Inventory owns Item and Warehouse data. Sales reads those references and coordinates Shipper submission authorization with Logistics. Orders, Shippers and Invoices may also be created independently.</p>
<p><strong>Proposed high level solution:</strong> Use the existing PHP/OOP, Oracle and Smarty application. Define and review REST request/response contracts, the business design and database migrations before implementation. Every sales document uses Draft and Submitted states; payments update the outstanding amount and display a payment stamp.</p>
<p><strong>Assumptions:</strong> Reuse shared login, company isolation, permissions, transactions, revisions, safe retry and audit controls. Exact peer interfaces and dispatch authorization are reviewed with the owning teams.</p>
<h2 id="requirements">Requirements</h2>
${ul([
'Maintain Customers, including active status, contact/address details and default Payment Terms.',
'Create, read, edit, submit, conditionally return and delete eligible sales documents.',
'Support editable standalone lines and full, read-only copying from an optional source.',
'Calculate amounts, record received money and show current outstanding balances.',
'Protect every action against unauthorized access, partial saves, stale edits and duplicate effects.'
])}
`);

pages.push(`
<h2 id="scope">Scope</h2>
${table(['Component','Comments'],[
['Technology','PHP/OOP, JavaScript, Oracle and Smarty templates generating HTML.'],
['Services and APIs','Customer, Quote, Order, Shipper, Invoice and payment capabilities. Review Swagger first; verify APIs before corresponding UI screens.'],
['Database setup','Reviewed migrations create Sales persistence, constraints, indexes, numbering and permissions. Apply only after guide approval.'],
['Master data','Sales owns Customers. Inventory owns Item and Warehouse maintenance and supplies read-only active choices.'],
['Documents and lines','Draft/Submitted workflow, stable line identities, exact amounts, optional sources and document history.'],
['Received amounts','Simple amount entry, outstanding-amount validation, payment stamps and read-only recording history. No gateway or money transfer.'],
['Shared foundation','Reuse authentication, company isolation, authorization, transactions, revisions, retry protection and audit.'],
['Lists','Customer search/status filters and combined All Documents filters. Printing and a separate reporting subsystem are excluded.']
],[25,75])}
<p><strong>Excluded:</strong> approvals, taxes/retainage, discounts, Inventory stock changes, stock allocation, split downstream documents, returns, opening balances, credits and refunds.</p>
<h2 id="business">Business Requirements</h2>
<h3>Customers and document states</h3>
${ul([
'Customer ID is company-unique, immutable after creation and limited to letters, numbers and hyphens. Deactivate Customers instead of deleting them.',
'Hide inactive Customers from new selections. Preserve historical references and allow payments against their existing Submitted Invoices.',
'Every new document is saved as a numbered Draft before submission. Validate complete business contents on Draft save and submission, except Carrier is required specifically at Shipper submission.',
'Submitted business fields are read-only. Return to Draft only when no downstream record references the document; a downstream Draft also blocks return.',
'An Invoice with any recorded payment cannot return to Draft. Delete only eligible unreferenced Drafts, using the All Documents list action rather than a form Delete button.',
'Generated document numbers are read-only, at most 20 characters and never reused. State changes happen through authorized actions, not editable status fields.'
])}
`);

pages.push(`
<h2>Business Requirements</h2>
<h3>Optional sources and item lines</h3>
${ul([
'Quotes have no source. Orders may use a Submitted Quote; Shippers a Submitted Order; Invoices a Submitted Shipper. Blank Source permits a standalone document.',
'A source must be readable, in the same company, Submitted and unused by another downstream document. One source supplies one full downstream document.',
'Saving a linked Draft reserves its source. Copy Customer, Warehouse and all lines; keep inherited values read-only. Deleting that Draft releases its source claim.',
'Changing or clearing Source before submission requires confirmation, complete revalidation and atomic release/claim of source links. Returning to Draft retains an existing upstream link.',
'Write lines within the parent document as the complete intended collection. New lines receive IDs; existing line IDs must belong to that document. Omission removes a line only on an editable standalone Draft.',
'Allow the same Item on distinct lines. Require at least one valid line. Derive every line\'s Warehouse from the header. Submitted or retained source-linked lines cannot be modified.'
])}
<h3>Amounts and document exceptions</h3>
${table(['Area','Required behavior'],[
['Calculations','Quantity &gt; 0, up to 9 whole digits and 3 decimals. Unit Price &ge; 0, up to 10 whole digits and 2 decimals. Round each Quantity &times; Price half up to 2 decimals, then sum rounded lines.'],
['Capacity','Line Amount, Document Total and payment amounts support at most 16 whole digits and 2 decimals. Reject excess precision/capacity; never truncate.'],
['Shipper','Require Carrier and Logistics dispatch authorization on submission. Submitted confirms dispatch; linked Order remains Submitted. OE makes no stock posting.'],
['Invoice','Require positive total on submission. A Draft has no populated payment status or balance contribution. Submission sets Unpaid and contributes outstanding amount once.'],
['Invoice return','When eligible, remove its unpaid contribution and clear payment status together. Resubmission contributes once again, without double-counting.']
],[23,77])}
<h3>Received amount and payment stamp</h3>
<p>The user records money already received outside the application. Accept an amount greater than zero and no more than current Amount Due; save the recording and update Total Paid, Amount Due and Customer balance together.</p>
<p>Show <strong>Unpaid</strong> before payment, <strong>Partially Paid</strong> while an amount remains, and <strong>Fully Paid</strong> at zero due. Fully Paid corresponds to Paid in the requirements. The Invoice remains Submitted. Preserve read-only history and prevent duplicate/concurrent overpayment.</p>
`);

const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 410" role="img" aria-label="Sales flow from Customer through Quote, Order, Shipper, Invoice and recording received amounts">
<defs><marker id="arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto"><path d="M0 0 L8 4 L0 8" fill="#405b72"/></marker></defs>
<style>text{font-family:Arial,sans-serif;fill:#162e42;font-size:18px} .main{fill:#edf4fb;stroke:#9fb5c9;stroke-width:1.5} .dep{fill:#f4f4f4;stroke:#bbb;stroke-width:1.5} .edge{stroke:#405b72;stroke-width:2;fill:none;marker-end:url(#arrow)} .dash{stroke-dasharray:6 5} .label{font-size:14px;fill:#4b5563}</style>
<rect class="main" x="10" y="135" width="130" height="60" rx="3"/><text x="75" y="171" text-anchor="middle">Customer</text>
<rect class="main" x="170" y="135" width="130" height="60" rx="3"/><text x="235" y="171" text-anchor="middle">Sales Quote</text>
<rect class="main" x="330" y="135" width="130" height="60" rx="3"/><text x="395" y="171" text-anchor="middle">Sales Order</text>
<rect class="main" x="490" y="135" width="150" height="60" rx="3"/><text x="565" y="160" text-anchor="middle">Shipper</text><text x="565" y="181" text-anchor="middle">Dispatch</text>
<rect class="main" x="670" y="135" width="130" height="60" rx="3"/><text x="735" y="171" text-anchor="middle">Invoice</text>
<path class="edge" d="M140 165 H170"/><path class="edge" d="M300 165 H330"/><path class="edge" d="M460 165 H490"/><path class="edge" d="M640 165 H670"/>
<rect class="dep" x="215" y="15" width="270" height="60" rx="3"/><text x="350" y="40" text-anchor="middle">Inventory</text><text x="350" y="61" text-anchor="middle" class="label">Read Item and Warehouse references</text>
<path class="edge dash" d="M350 75 V104 H235 V135"/><path class="edge dash" d="M350 104 H395 V135"/><path class="edge dash" d="M395 104 H565 V135"/><path class="edge dash" d="M565 104 H735 V135"/>
<rect class="dep" x="450" y="285" width="225" height="60" rx="3"/><text x="562" y="310" text-anchor="middle">Logistics</text><text x="562" y="331" text-anchor="middle" class="label">Dispatch authorization</text><path class="edge dash" d="M565 285 V195"/>
<rect x="700" y="285" width="190" height="60" rx="3" fill="#edf7f0" stroke="#9fbfaf" stroke-width="1.5"/><text x="795" y="310" text-anchor="middle">Record Payment</text><text x="795" y="331" text-anchor="middle" class="label">Update balance and status</text><path class="edge" d="M735 195 V240 H795 V285"/>
<text class="label" x="10" y="390">Orders, Shippers and Invoices can also start independently. Dashed arrows show module dependencies.</text>
</svg>`;

pages.push(`
<h2 id="action">Action Flow</h2>
<h3>Flow Diagram</h3>
<figure>${svg}<figcaption>The main sales journey. Linked documents use an eligible Submitted predecessor; source links are optional.</figcaption></figure>
<h3>End to End Flow</h3>
${ol([
'Create or select an active Customer. Confirm billing details and default Payment Terms.',
'Choose Quote, Order, Shipper or Invoice. Select active Inventory references for a standalone document, or choose an eligible source and copy its full business details.',
'Save a valid Draft with at least one line. Generate its document number and stable line IDs; reserve a selected source in the same transaction.',
'Edit permitted Draft fields and complete lines. Recalculate amounts. Confirm and atomically validate any source change.',
'Save as Submitted. Require document permissions, Carrier and dispatch authorization for a Shipper, and a positive total for an Invoice.',
'For an Invoice, show Unpaid and its Amount Due. Its outstanding amount contributes to the Customer balance.',
'Enter a received amount. Validate against the current outstanding amount and record it once. Update the balance and Partially Paid or Fully Paid stamp.',
'Repeat received-amount entry until due is zero. Retain read-only payment history and block further payments once fully paid.',
'Permit return to Draft or deletion only under the eligibility rules. Any failed action leaves persisted business data unchanged.'
])}
<p><strong>Example:</strong> Submit Invoice 1,000.00 &rarr; receive 300.00 &rarr; outstanding 700.00 and Partially Paid &rarr; reject 800.00 &rarr; receive 700.00 &rarr; outstanding 0.00 and Fully Paid.</p>
`);

pages.push(`
<h2 id="technical">Technical Notes</h2>
${ul([
'Use shared business services for REST and Smarty UI. Register Sales APIs through the module entry point; use the existing bearer API and browser session/CSRF conventions.',
'Reuse the response envelope, available-action and revision conventions. Return saved numbers, ordered lines, amounts, status and current revision. Exact request/response schemas belong in Swagger.',
'Persist Customers, typed documents, lines, source usage and payment recordings with company-scoped identities and references. Physical tables and SQL belong in separately reviewed migrations.',
'Use exact decimal arithmetic, half-up rounding and character-based field limits. Never trust caller totals, balance, status, actor or company identity.',
'Recheck revisions and business facts under deterministic locks. Protect source claims/return races and concurrent Invoice payments in their owning transaction.',
'Authorize before retry resolution. An identical committed request returns its saved result; the same identifier with a changed payload conflicts. Preserve original identifiers after uncertain timeouts.',
'All affected writes, audit and retry results commit together. Peer calls join the same transaction where required and never commit independently. Oracle DDL uses a separate reviewed recovery procedure.'
])}
<h2 id="validations">Validations</h2>
<h3>Customers and general input</h3>
${table(['Condition','Expected behavior or message'],[
['Required/overlength text','Trim text. Enter {Field label}. / {Field label} must be {limit} characters or fewer.'],
['Customer identity','ID: 20 characters, company-unique, immutable, letters/numbers/hyphens. Customer ID already exists. Enter a unique ID.'],
['Customer fields','Name/Contact: 100; Email: 254; Phone: 25; Address lines: 150 each; City/State/Country: 80; Postal Code: 20; Notes: 500 characters.'],
['Required Customer fields','ID, Name, Status, Address Line 1, City, Country and Payment Terms. Country is free text; preserve leading zeros in Postal Code.'],
['Invalid choices/contact details','Active/Inactive; Due on receipt/Net 15/Net 30. Default Active/Net 30. Validate supplied email/phone; reject invalid choices.'],
['Caller edits Customer balance','Amount Customer Owes Us is calculated and cannot be edited.'],
['Missing/inactive company reference','Reject unavailable or cross-company references; require active selections. Preserve historical data and payments for inactive Customers.']
],[29,71])}
`);

pages.push(`
<h2>Validations</h2>
<h3>Documents and lines</h3>
${table(['Condition','Expected behavior or message'],[
['Header values','Customer reference: 20; Warehouse reference: 20; generated number: 20; Notes: 500 characters. Required Customer, Warehouse and document date.'],
['Missing lines','Add at least one item line. Complete-collection writes must include all intended lines; removing the last line fails.'],
['Line identity','Reject unknown/foreign IDs and repeated saved IDs. Line {n}: Select a valid line belonging to this document. / Line {n}: Duplicate line identifier.'],
['Item/Description','Require active Item available to company; Item Code maximum 30 characters. Description required, maximum 200 characters.'],
['Quantity','Line {n}: Enter Quantity greater than 0, with up to 9 whole digits and 3 decimal places.'],
['Unit Price','Line {n}: Enter Unit Price of 0 or more, with up to 10 whole digits and 2 decimal places. Zero is allowed.'],
['Warehouse mismatch','Line {n}: Warehouse must match the document Warehouse. Derive it from the header.'],
['Calculated amounts supplied/overflow','Reject edits to Line Amount and Document Total. Reject amounts exceeding 16 whole digits and 2 decimals.'],
['Linked lines changed','Source-linked lines cannot be added, changed or removed. Reject inherited field mismatches.'],
['Submitted edit/direct status write','Only Draft documents can be edited. / Use the available action to change Document Status.'],
['Unavailable source','Selected source is no longer available. Refresh and select another source, or leave Source blank.'],
['Return/delete restrictions','Block return when any downstream record exists. Block Invoice return after any payment. Delete only unreferenced Drafts.'],
['Stale version or invalid later line','Reject without overwriting or partially saving. This document has changed. Refresh and try again.']
],[27,73])}
<h3>Calculation example</h3>
<p>2 &times; 125.50 = 251.00; 1.250 &times; 10.00 = 12.50; Total = <strong>263.50</strong>. Change the first Quantity to 3: Total = <strong>389.00</strong>. Remove the second line: Total = <strong>376.50</strong>. Each successful update preserves line identity and returns saved calculated amounts.</p>
`);

pages.push(`
<h2>Validations</h2>
<h3>Document dates and type-specific fields</h3>
${table(['Area','Restriction'],[
['Dates','Use valid YYYY-MM-DD and default document dates to the current business date. Reject From Date after To Date.'],
['Quote/Order','Valid Until and Requested Delivery Date cannot precede the relevant document date. Linked Order Date must be on/after Quote Date.'],
['Address and terms','Shipping Address: required for Quote/Order/Shipper, 500 characters; default from Customer or copy from source. Payment Terms: Due on receipt/Net 15/Net 30 as applicable. Customer PO Number: optional Order text, 30 characters.'],
['Shipper','Shipment Date cannot be future or precede linked Order Date. Carrier: 100 characters, required on submit. Tracking Number: optional, 50 characters.'],
['Invoice','Invoice Date cannot precede linked Shipment Date. Due Date defaults from terms and cannot precede Invoice Date. Require Invoice Total greater than 0 before submission.']
],[25,75])}
<h3>Received amount and payment history</h3>
${table(['Condition','Expected behavior or message'],[
['Draft Invoice','Submit the Invoice before recording a payment.'],
['Missing/invalid amount','Enter Payment Amount. / Enter a valid Payment Amount. Reject zero, negative, more than 2 decimals or excess numeric capacity.'],
['Amount exceeds current outstanding','Payment Amount cannot exceed Amount Due ({current amount due}). Validate current due on the server.'],
['Already Fully Paid','This Invoice is fully paid. No further payment can be recorded.'],
['Concurrent payment','Re-read due under lock; never overpay. Amount Due has changed to {current amount due}. Review the amount and try again.'],
['Duplicate or unauthorized request','Identical retry returns original result. Changed payload or missing permission fails without another recording.'],
['Payment history fields','Requirements specify Payment Date on/after Invoice Date and not future; Payment Reference required, maximum 50 characters; generated Payment Number maximum 20 characters. History supports Create/Read only.']
],[27,73])}
<p><strong>PM confirmation:</strong> keep the received-amount interaction simple. The requirements also specify Payment Date and Reference; confirm their UI treatment before freezing Swagger.</p>
`);

pages.push(`
<h2 id="impact">Impact on Other Modules</h2>
${table(['Module','Impact','Notes'],[
['Inventory','Medium','Provides active Item/Warehouse references and company visibility. Sales reads and validates these values; it does not change stock or implement Inventory maintenance.'],
['Logistics','High','Supplies the agreed dispatch authorization for Shipper submission. Its trip planning/delivery design needs reconciliation with the OE document model before integration.'],
['Purchasing','Low','No direct Sales posting or table dependency in this scope. Any stock supply remains the responsibility of Purchasing and Inventory.'],
['Shared foundation','Medium','Provides login, permissions, tenant boundaries, revisions, transactions, retry and audit conventions. Review any common changes with the foundation owner.'],
],[24,13,63])}
<h3>Integration alignment required</h3>
<p>The Logistics reference assumes allocated order lines, line splits across trips and Order completion after dispatch/delivery. OE instead requires full source copying, Draft/Submitted states and no stock allocation. PM and guides must reconcile these contracts before integration. This design retains Submitted Orders and authorized Shipper submission.</p>
<h3>Review and delivery gates</h3>
${ol([
'PM approves Swagger behavior and received-amount UI details.',
'Guides approve design, database/migrations and peer contracts.',
'After both approvals, QA creates the test plan; developers apply reviewed migrations and implement APIs.',
'Verify standalone/linked flows, permissions, source races, rounding, payments, retries and rollback before UI acceptance.'
])}
<p><strong>Review decisions:</strong> inactive references after source submission, Payment Terms through Shipper, numbering namespace and Logistics authorization mapping.</p>
<h3>Reference documents</h3>
<p class="small">Order Entry API priorities and stories v6.1, pages 1-13: requirements. Design Docx - Logistics, pages 1-7: structure and presentation. Existing Sales design and confirmed team decisions: design context.</p>
`);

// Keep the review artifact focused on decisions; detailed field limits remain in the requirements and repository reference.
pages[4] = `
<h2 id="technical">Design Decisions</h2>
<h3>Business services and data ownership</h3>
<p>REST and Smarty screens use the same Sales services so permissions, calculations and state transitions cannot drift between channels. Sales owns Customers, documents, lines and received-amount recordings. Inventory remains the authority for Item/Warehouse data; OE stores references and business snapshots rather than maintaining a second stock balance.</p>
<p><strong>Proposed document model:</strong> share headers and lines because all four document types share fields and Draft/Submitted behavior. Keep type-specific rules, source relationships and payments explicit to preserve lineage without duplicating common logic. Guides approve the physical schema separately.</p>
<h3>Complete line collection and source snapshots</h3>
<p>Save the complete intended line collection with its parent document. Stable IDs distinguish edits from additions; omission removes an eligible standalone Draft line. This makes header, lines and recalculated amounts one atomic action rather than several independently saved line operations.</p>
<p>Copy and lock inherited values to preserve the agreed transaction. Reserve a source at downstream Draft save, rather than preview, to prevent double use. Replace source links atomically; failure preserves the original Draft and claim.</p>
<h3>Amounts and received money</h3>
<p>Use exact decimal arithmetic and round each line half up before summing. This ensures the displayed line amounts add up to the document total. Derive Customer balance from Submitted Invoice dues and payment history, avoiding a second writable balance that could diverge.</p>
<p>Keep document status separate from the payment stamp because receipt does not change Invoice submission. Record received money without a gateway. Immutable recordings and original request identifiers support history and safe retry behind the simple amount-entry screen.</p>
<h3>Consistency and failure handling</h3>
<p>Revision checks reject stale edits. Transaction locks serialize source claims and Invoice payments because a revision check alone cannot protect two simultaneous decisions. Re-read current facts after locking; commit business changes, audit and retry results together. Check authorization before replay and resolve an identical committed retry before checking today's revision.</p>
<h3>Assumptions and tradeoffs</h3>
${ul([
'Shared identity, company isolation, transaction and audit foundations are available; OE-specific extensions still need review.',
'Full source copying avoids split-quantity tracking. A saved downstream Draft blocks reuse and return of its source until released.',
'Read-only payment history preserves accountability; correcting an erroneous recording needs a separately agreed process because update/delete is excluded.',
'Use the configured business date and preserve snapshots when master details change. Real peer integration requires agreed contracts, not test doubles.'
])}
`;

pages[5] = `
<h2 id="validations">Edge Cases and Validations</h2>
<p>Validate on the server on save/submission; Carrier is required at Shipper submission. Reject wrong permissions and cross-company access before business effects or replay. Field limits/messages follow the OE requirements.</p>
${table(['Scenario','Design response and reason'],[
['Invalid later line or last line removed','Reject the entire write. Preserve saved lines, totals, state and source claims; a document cannot be partly saved or left empty.'],
['Foreign/repeated line ID or changed inherited values','Reject the request. Stable identity must belong to the parent; source-linked values must remain consistent with their source. Repeated Items on distinct lines are allowed.'],
['Two users edit a Draft','Require the current revision. The stale user refreshes instead of silently overwriting the other edit.'],
['Two targets claim one source','Lock and recheck eligibility with exclusive source usage. One succeeds; the other creates no document or partial claim.'],
['Source claim races return to Draft','Serialize on the source and recheck status/usage. A source cannot become editable while a new downstream link succeeds.'],
['Invalid amount or rounding overflow','Reject excess precision, negative prices, nonpositive quantities and amounts beyond capacity; never truncate. Line rounding precedes summation.'],
['Invoice return after payment','Reject return even for a partial payment. Before payment, an eligible return removes its outstanding contribution once; resubmission restores it once.'],
['Payments compete for remaining due','Lock the Invoice and re-read current Amount Due. Reject any payment exceeding that amount; never allow negative due.'],
['Timeout or repeated request','Retry unchanged input with the original identifier to retrieve the saved result. Changed input under that identifier conflicts instead of creating another effect.'],
['Inactive Customer or unavailable peer','Hide inactive Customers from new selections but allow existing Invoice payments. If required peer validation is unavailable, reject safely rather than assume success.'],
],[29,71])}
<h3>Worked examples for review</h3>
<p><strong>Lines:</strong> 2 &times; 125.50 plus 1.250 &times; 10.00 gives 263.50. Changing the first Quantity to 3 gives 389.00; removing the second line gives 376.50. Removing the last line fails.</p>
<p><strong>Payments:</strong> Invoice 1,000.00; receive 300.00; due 700.00 and Partially Paid. Reject 800.00, then accept 700.00 and show Fully Paid. Retrying either committed request creates no additional recording.</p>
<p><strong>PM decision:</strong> amount entry and stamps stay simple. The requirements also specify Payment Date and Reference; confirm their treatment before freezing Swagger.</p>
`;
pages.splice(6, 1);

const last = pages.length - 1;
pages[last] = pages[last].replace('<h3>Reference documents</h3>',
  '<h3>Keeping design and implementation aligned</h3><p>Review behavior changes before coding them, then update this design, Swagger, migrations and affected tests in the same change. Record the decision, reason, reviewer and affected flow so later readers can understand why the system changed. Before sign-off, walk through the worked examples against actual API behavior.</p><h3>Reference documents</h3>');

const css = `
@page{size:A4;margin:16mm 18mm 16mm}
*{box-sizing:border-box}body{margin:0;font-family:Arial,Helvetica,sans-serif;color:#222;font-size:10.6pt;line-height:1.43}
.sheet{width:174mm;height:265mm;position:relative;break-after:page;padding-bottom:10mm;overflow:visible}.sheet:last-child{break-after:auto}
h1,h2,h3{color:#000;break-after:avoid}h1{font-size:24pt;font-weight:500;line-height:1.2;margin:0 0 8pt}h2{font-size:17pt;line-height:1.2;margin:0 0 11pt}h2:not(:first-child){margin-top:17pt}h3{font-size:12pt;margin:13pt 0 6pt}
p{margin:0 0 9pt}ul,ol{margin:5pt 0 11pt;padding-left:20pt}li{margin-bottom:6pt;break-inside:avoid}a{color:#0052cc;text-decoration:none}.small{font-size:9.4pt}.meta{font-size:9pt;color:#666;margin-bottom:13pt}.subtitle{font-size:12pt;color:#444;margin-bottom:6pt}
nav{margin:12pt 0 18pt}nav ul{columns:2;column-gap:20pt;font-size:10.4pt}nav li{margin:0 0 5pt}
table{width:100%;border-collapse:collapse;table-layout:fixed;margin:8pt 0 13pt;font-size:10pt;line-height:1.37}th,td{border:1px solid #d9d9d9;padding:6pt 7pt;vertical-align:middle;overflow-wrap:break-word}th{background:#f0f0f0;color:#111;text-align:left;font-weight:600}tr{break-inside:avoid}thead{display:table-header-group}
figure{margin:7pt 0 14pt;break-inside:avoid}figure svg{width:100%;height:auto;display:block}figcaption{font-size:9pt;color:#555;margin-top:5pt}
.footer{position:absolute;bottom:0;width:100%;display:flex;justify-content:space-between;font-size:8.3pt;color:#777;border-top:1px solid #e5e5e5;padding-top:5pt}
`;
const html = `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Sales and Order Entry Design</title><style>${css}</style></head><body>${pages.map((p,i)=>`<section class="sheet" id="page-${i+1}"><main>${p}</main><div class="footer"><span>Sales and Order Entry Design</span><span>${i+1} / ${pages.length}</span></div></section>`).join('')}</body></html>`;
const htmlPath = path.join(__dirname,'sales-and-order-entry-design.html');
fs.writeFileSync(htmlPath,html);

(async()=>{
  const browser = await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
  try {
    const page = await browser.newPage();
    await page.setContent(html,{waitUntil:'load'});
    await page.emulateMedia({media:'print'});
    const measures = await page.evaluate(()=>Array.from(document.querySelectorAll('.sheet')).map((s,i)=>({page:i+1,content:s.querySelector('main').getBoundingClientRect().height,available:s.querySelector('.footer').getBoundingClientRect().top-s.getBoundingClientRect().top-15,scroll:s.scrollHeight,box:s.clientHeight})));
    console.log(JSON.stringify(measures,null,2));
    const bad=measures.filter(p=>p.content>p.available);
    if(bad.length)throw new Error('Content overlaps footer on page(s): '+bad.map(p=>p.page));
    await page.pdf({path:out,printBackground:true,preferCSSPageSize:true,displayHeaderFooter:false});
    console.log('Created '+out);
  }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exit(1)});
