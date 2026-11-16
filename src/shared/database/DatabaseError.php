<?php
declare(strict_types=1);

namespace MiniErp\shared\database;

final class DatabaseError extends \RuntimeException
{
    public function __construct(public readonly int $oracleCode)
    {
        parent::__construct('Oracle operation failed.');
    }
}
