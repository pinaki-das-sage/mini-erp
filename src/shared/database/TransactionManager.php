<?php
declare(strict_types=1);

namespace MiniErp\shared\database;

final class TransactionManager
{
    private bool $active = false;

    public function __construct(private readonly Connection $connection) {}

    public function run(callable $operation): mixed
    {
        if ($this->active) {
            throw new \LogicException('Join the existing transaction context instead of nesting transactions.');
        }
        $this->connection->begin();
        $this->active = true;
        $context = new TransactionContext($this->connection);
        try {
            $result = $operation($context);
            $this->connection->commit();
            return $result;
        } catch (\Throwable $error) {
            $this->connection->rollback();
            throw $error;
        } finally {
            $context->expire();
            $this->active = false;
        }
    }
}
