<?php
declare(strict_types=1);

// Forward reversal of shared/005_purchasing.php. Keep the original migration
// unchanged in shared so its recorded version and checksum remain valid.
// Drops purchasing data; apply before running the purchasing component.
// Child tables must be dropped before their parents. Oracle also drops the
// indexes owned by these tables and implicitly commits each DDL statement.
return [
    'DROP TABLE ME_PUR_DOC_ENTRY',
    'DROP TABLE ME_PUR_DOC',
    'DROP TABLE ME_PUR_VENDOR',
];
