<?php
declare(strict_types=1);

namespace MiniErp\shared\database;

use MiniErp\shared\AppError;
use MiniErp\shared\Config;

final class OciConnection implements Connection
{
    private bool $active = false;

    /** @param resource $handle */
    private function __construct(private mixed $handle) {}

    public static function connect(Config $config): self
    {
        $settings = $config->oracle();
        if (!extension_loaded('oci8')) {
            throw new AppError('CONFIGURATION_ERROR', 'Install OCI8 and a compatible Oracle client on the application server.', 503);
        }
        $handle = @oci_new_connect($settings['username'], $settings['password'], $settings['connection'], 'AL32UTF8');
        if ($handle === false) {
            throw new DatabaseError((int) (oci_error()['code'] ?? 0));
        }
        return new self($handle);
    }

    public function query(string $sql, array $parameters = []): array
    {
        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) {
            throw new \InvalidArgumentException('query accepts read statements only.');
        }
        $statement = $this->statement($sql, $parameters);
        try {
            $rows = [];
            while (($row = oci_fetch_array($statement, OCI_ASSOC | OCI_RETURN_NULLS | OCI_RETURN_LOBS)) !== false) {
                $rows[] = $row;
            }
            $error = oci_error($statement);
            if ($error !== false) {
                throw new DatabaseError((int) $error['code']);
            }
            return $rows;
        } finally {
            oci_free_statement($statement);
        }
    }

    public function execute(string $sql, array $parameters = []): int
    {
        if (!$this->active || !preg_match('/^\s*(INSERT|UPDATE|DELETE|MERGE)\b/i', $sql)) {
            throw new \LogicException('Business writes require an active transaction and a DML statement.');
        }
        $statement = $this->statement($sql, $parameters);
        try {
            return oci_num_rows($statement);
        } finally {
            oci_free_statement($statement);
        }
    }

    public function executeSchema(string $sql): void
    {
        if ($this->active || !preg_match('/^\s*(CREATE|ALTER|DROP|DECLARE)\b/i', $sql)) {
            throw new \LogicException('DDL must run outside business transactions.');
        }
        $statement = $this->statement($sql, []);
        oci_free_statement($statement);
    }

    public function begin(): void
    {
        if ($this->active) {
            throw new \LogicException('Nested transactions are not supported.');
        }
        $this->active = true;
    }

    public function commit(): void
    {
        if (!$this->active) {
            throw new \LogicException('No transaction to commit.');
        }
        if (!@oci_commit($this->handle)) {
            throw new DatabaseError((int) (oci_error($this->handle)['code'] ?? 0));
        }
        $this->active = false;
    }

    public function rollback(): void
    {
        if (!$this->active) {
            throw new \LogicException('No transaction to roll back.');
        }
        if (!@oci_rollback($this->handle)) {
            throw new DatabaseError((int) (oci_error($this->handle)['code'] ?? 0));
        }
        $this->active = false;
    }

    /** @param array<string, int|string|null> $parameters
     *  @return resource
     */
    private function statement(string $sql, array $parameters): mixed
    {
        $statement = @oci_parse($this->handle, $sql);
        if ($statement === false) {
            throw new DatabaseError((int) (oci_error($this->handle)['code'] ?? 0));
        }
        $lobs = [];
        try {
            foreach ($parameters as $name => &$value) {
                if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $name)
                    || (!is_int($value) && !is_string($value) && $value !== null)) {
                    throw new \InvalidArgumentException('Invalid bound parameter.');
                }
                $type = is_int($value) ? SQLT_INT : SQLT_CHR;
                if (is_string($value) && strlen($value) > 4000) {
                    $lob = oci_new_descriptor($this->handle, OCI_D_LOB);
                    if ($lob === false || !@$lob->writeTemporary($value, OCI_TEMP_CLOB)) {
                        throw new DatabaseError((int) (oci_error($this->handle)['code'] ?? 0));
                    }
                    $lobs[] = $lob;
                    $value = $lob;
                    $type = SQLT_CLOB;
                }
                if (!@oci_bind_by_name($statement, ':' . $name, $value, -1, $type)) {
                    throw new DatabaseError((int) (oci_error($statement)['code'] ?? 0));
                }
            }
            unset($value);
            if (!@oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new DatabaseError((int) (oci_error($statement)['code'] ?? 0));
            }
            return $statement;
        } catch (\Throwable $error) {
            oci_free_statement($statement);
            throw $error;
        } finally {
            foreach ($lobs as $lob) {
                $lob->free();
            }
        }
    }
}
