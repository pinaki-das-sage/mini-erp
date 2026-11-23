const fs=require('fs'),assert=require('assert/strict');
const p='docs/sales-linked-flow-db-walkthrough.md';let s=fs.readFileSync(p,'utf8');
fs.writeFileSync('tmp/db-review-2026-11-20/walkthrough-before.md',s);
for(const [a,b]of Object.entries({CUSTOMER_ID:'CUSTOMER_RECORD#',DOCUMENT_ID:'DOCUMENT_RECORD#',SOURCE_LINE_ID:'SOURCE_LINE_RECORD#',SOURCE_DOCUMENT_ID:'SOURCE_DOCUMENT_RECORD#',TARGET_DOCUMENT_ID:'TARGET_DOCUMENT_RECORD#',INVOICE_ID:'INVOICE_RECORD#',COMPANY_ID:'CNY#'}))s=s.replace(new RegExp('\\b'+a+'\\b','g'),b);
s=s.replace(/ME_OE_([A-Z_]+)\.ID\b/g,'ME_OE_$1.RECORD#').replace(/`ID(?=[` =])/g,'`RECORD#');
const lines=s.split('\n'),out=[];let kind='';
for(let i=0;i<lines.length;i++){
 let l=lines[i];
 if(l.startsWith('| ID | DOCUMENT_RECORD# |')){kind='line';l=l.replace('| ID |','| RECORD# | CNY# |');}
 else if(l.startsWith('| SOURCE_DOCUMENT_RECORD# | TARGET_DOCUMENT_RECORD# |')){kind='source';l=l.replace('| SOURCE_DOCUMENT_RECORD# |','| RECORD# | CNY# | SOURCE_DOCUMENT_RECORD# |');}
 else if(l.startsWith('|')){
  const cells=l.split('|').slice(1,-1).map(x=>x.trim());
  if(kind==='line')cells.splice(1,0,/^-+$/.test(cells[0])?'---':'1');
  else if(kind==='source'){
   if(/^-+$/.test(cells[0]))cells.unshift('---','---');
   else{const key=cells[0]+':'+cells[1],r={'5:10':'5001','10:20':'5002','20:30':'5003'}[key];assert(r,key);cells.unshift(r,'1');}
  }
  if(kind)l='| '+cells.join(' | ')+' |';
 }else kind='';
 out.push(l);
 if(/^\| `RECORD#` \| `(?:5|10|20|30|1000|1001)` \|$/.test(l))out.push('| `CNY#` | `1`, from trusted company context |');
}
s=out.join('\n');
// Shared Foundation columns keep their real names.
s=s.replace('| CNY# | ACTION_REFERENCE | ACTOR_ID | CREATED_AT |','| COMPANY_ID | ACTION_REFERENCE | ACTOR_ID | CREATED_AT |');
s=s.replace('| `PAYMENT_TERMS` | `NET_30` |\n| `NOTES` | `NULL` |','| `PAYMENT_TERMS` | `NET_30` |\n| `AMOUNT_CUSTOMER_OWES_US` | `0.00`, stored and server maintained |\n| `NOTES` | `NULL` |');
s=s.replace('No balance column is written. With no Submitted Invoice yet, the calculated Customer balance is `0.00`.','The stored Customer `AMOUNT_CUSTOMER_OWES_US` starts at `0.00`. Invoice/payment services maintain it; callers cannot edit it.');
s=s.replace('Type-inapplicable header fields are `NULL`; Company is obtained by joining the Customer.','Type-inapplicable header fields are `NULL`. Every Sales row stores `CNY# = 1`; parent/source references include it.');
s=s.replace('| Company | Header `CUSTOMER_RECORD# = 1` joins Customer `RECORD# = 1` | `CNY# = 1` |','| Company | Read Shipper header `CNY#` and require matching company on every joined row | `CNY# = 1` |');
s=s.replace('These totals/stamps are not columns in the proposed tables. Customer balance includes the Invoice because its document state is now Submitted; no separate editable balance is incremented.','Invoice totals and payment status remain calculated outputs. On Invoice submission, update Customer `(CNY# = 1, RECORD# = 1)` so stored `AMOUNT_CUSTOMER_OWES_US` becomes `263.50`, in the same transaction as the status, audit and retry result. An identical retry must not add the total again.');
s=s.replace('Lock Invoice `30`, validate its state/current due and resolve an identical committed retry before applying another payment. Each accepted recording saves payment, shared action result and audit together.','Protect Customer `(1, 1)` and Invoice `(1, 30)` using the agreed lock order, validate current due and resolve identical committed retries before applying another payment. Each recording saves Payment, stored Customer balance, shared action result and audit together.');
s=s.replace('The company comes from Payment → Invoice → Customer. Join company plus `ACTION_REFERENCE` to the existing shared action; return its actor/time as `created_by` / `created_at`.','Read company directly from Payment `CNY#`. Join it to shared `ME_ACTIONS.COMPANY_ID` plus the same `ACTION_REFERENCE`; the new composite foreign key enforces this link. Return the shared actor/time as `created_by` / `created_at`.');
s=s.replace('The Invoice remains `SUBMITTED` throughout. No payment-status field is written to its row.','The Invoice remains `SUBMITTED` throughout. Stored Customer `AMOUNT_CUSTOMER_OWES_US` changes to `163.50` after the first payment and `0.00` after the second. No payment-status field is written to the Invoice row.');
s=s.replace('| Company on documents/lines/payments | Join through the owning Customer |','| Company on every Sales table | Its own `CNY#`; parent references use the same company |');
s=s.replace('| Customer balance | Sum dues of that Customer\'s Submitted Invoices |','| Customer balance | Stored `ME_OE_CUSTOMERS.AMOUNT_CUSTOMER_OWES_US`, maintained and reconciled against Submitted Invoice dues |');
s=s.replace('The Invoice\'s version protects concurrent payment recording.','Customer and Invoice locking protect balance updates.');
s=s.replace('This walkthrough follows one Customer through Quote, Order, Inventory reservation, Shipper, Logistics, Invoice and payment. Each step shows the Sales database fields and the identifiers passed to other teams.','This walkthrough follows one Customer through Quote, Order, Inventory reservation, Shipper, Logistics, Invoice and payment. It uses the 20 November 2026 review naming: `RECORD#`, `CNY#` and company-scoped composite references. Public JSON field names such as `id` and `source_line_id` stay unchanged and map to these database columns.');
fs.writeFileSync(p,s);console.log('Aligned walkthrough record/company references, source-link rows and stored-balance examples.');
