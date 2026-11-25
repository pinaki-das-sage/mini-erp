<?php
declare(strict_types=1);

// Requires ME_LOG_TRIPS and ME_LOG_TRIP_LINES in the same schema, each with
// a primary key on (COMPANY_ID, ID). Deploy Logistics table migrations first.
// Both Sales reference columns remain nullable for unassigned/non-Order lines.
// These constraints validate existing references as well as future writes.
// Services still validate assignment ownership, matching Trip IDs and lifecycle.
// No cascading deletes: clear current Sales references before Logistics deletes
// a referenced assignment. Logistics' own FKs block deletion of referenced Sales rows.
// Oracle DDL commits implicitly; inspect schema/ledger before retrying a failed run.
return [
    'ALTER TABLE ME_OE_DOCUMENT_LINES
        ADD CONSTRAINT ME_OE_LINE_TRIP_FK
        FOREIGN KEY (CNY#, TRIP_ID)
        REFERENCES ME_LOG_TRIPS (COMPANY_ID, ID)',
    'ALTER TABLE ME_OE_DOCUMENT_LINES
        ADD CONSTRAINT ME_OE_LINE_TRIP_LINE_FK
        FOREIGN KEY (CNY#, LOGISTICS_TRIP_LINE_ID)
        REFERENCES ME_LOG_TRIP_LINES (COMPANY_ID, ID)',
];
