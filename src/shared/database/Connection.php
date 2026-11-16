<?php
declare(strict_types=1);

namespace MiniErp\shared\database;

interface Connection
{
    /** @param array<string, int|string|null> $parameters
     *  @return list<array<string, mixed>>
     */
    public function query(string $sql, array $parameters = []): array;

    /** @param array<string, int|string|null> $parameters */
    public function execute(string $sql, array $parameters = []): int;

    public function executeSchema(string $sql): void;
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;
}
