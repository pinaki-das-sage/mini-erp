<?php
declare(strict_types=1);

namespace MiniErp\shared\database;

final class TransactionContext
{
    private bool $active = true;

    public function __construct(private readonly Connection $connection) {}

    /** @param array<string, int|string|null> $parameters
     *  @return list<array<string, mixed>>
     */
    public function query(string $sql, array $parameters = []): array
    {
        $this->assertActive();
        return $this->connection->query($sql, $parameters);
    }

    /** @param array<string, int|string|null> $parameters */
    public function execute(string $sql, array $parameters = []): int
    {
        $this->assertActive();
        return $this->connection->execute($sql, $parameters);
    }

    public function expire(): void
    {
        $this->active = false;
    }

    private function assertActive(): void
    {
        if (!$this->active) {
            throw new \LogicException('Transaction context has expired.');
        }
    }
}
