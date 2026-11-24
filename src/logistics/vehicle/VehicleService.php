<?php

declare(strict_types=1);

namespace MiniErp\logistics\vehicle;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

/**
 * Applies vehicle business rules and reads/writes Oracle through the shared connection.
 * Actor identifies the authenticated user/company; never take these from the JSON body.
 * AppError is an expected API error; DatabaseError contains a private Oracle error code.
 * Core validation implementation; shared action/audit integration is still required.
 *
 * Request fields:
 * - vehicle_code: positive numeric fleet code; different from the generated database ID.
 * - registration_number: company-unique registration, trimmed and stored in uppercase.
 * - description: optional text; null (or Oracle's empty string) clears the description.
 * - capacity: exact KG decimal string, e.g. "30.000"; a float is not a valid bind value.
 * - is_active: database flag "T" or "F"; GET's active=true/false maps to these values.
 * - action_reference: caller's command reference; format checked here, replay still pending.
 *
 * Database-owned fields:
 * - ID: Oracle-generated primary-key value returned to clients as id.
 * - COMPANY_ID: tenant boundary, always taken from Actor.
 * - CREATED_AT/UPDATED_AT: server timestamps, not client-submitted dates.
 * - CREATED_BY/UPDATED_BY: real authenticated user IDs, not hardcoded user numbers.
 */
final class VehicleService
{
    // Constructor property promotion stores the supplied connection as a private property.
    // Reusing it is necessary for SELECT/INSERT/UPDATE/DELETE to share one transaction.
    public function __construct(private Connection $connection) {}

    /** Return a company-scoped page of vehicles, optionally filtered by active/search. */
    public function getVehicles(Actor $actor, array $pagination): array
    {
        $this->authorize($actor, 'read');

        // ?? uses the fallback only when the key is missing or null; validation follows.
        $page = $pagination['page'] ?? null;
        // Number of records per page; capped at 100 to avoid unbounded responses.
        $size = $pagination['page_size'] ?? null;

        if (
            !is_int($page) || $page < 1 ||
            !is_int($size) || $size < 1 || $size > 100
        ) {
            throw new AppError(
                'VALIDATION_ERROR',
                'page must be positive; page_size must be between 1 and 100.',
                400
            );
        }

        // intdiv() performs integer division; this check prevents offset multiplication overflow.
        if ($page - 1 > intdiv(PHP_INT_MAX, $size)) {
            throw new AppError(
                'VALIDATION_ERROR',
                'Requested page is too large.',
                400
            );
        }

        // The route puts approved URL filters here; no filters means an empty array.
        $filters = $pagination['filters'] ?? [];

        if (
            !is_array($filters) ||
            array_diff(array_keys($filters), ['active', 'search']) !== []
        ) {
            throw new AppError(
                'VALIDATION_ERROR',
                'Unsupported vehicle filter.',
                400
            );
        }

        // URL query values must be strings; reject array-shaped query inputs.
        foreach ($filters as $value) {
            if (!is_string($value)) {
                throw new AppError(
                    'VALIDATION_ERROR',
                    'Vehicle filters must be strings.',
                    400
                );
            }
        }

        // Start with the tenant condition. Optional filters are appended and joined with AND.
        $where = ['V.COMPANY_ID = :company_id'];

        // Bind values separately from SQL so user input cannot become executable SQL.
        $parameters = [
            'company_id' => $actor->companyId,
            'offset' => ($page - 1) * $size,
            'page_size' => $size,
        ];

        // array_key_exists() checks whether the filter was supplied, not whether it is truthy.
        if (array_key_exists('active', $filters)) {
            if (!in_array($filters['active'], ['true', 'false'], true)) {
                throw new AppError(
                    'VALIDATION_ERROR',
                    'active must be true or false.',
                    400
                );
            }

            $where[] = 'V.ISACTIVE = :active';
            // The ternary operator converts HTTP boolean text into the stored T/F flag.
            $parameters['active'] = $filters['active'] === 'true' ? 'T' : 'F';
        }

        if (array_key_exists('search', $filters)) {
            // trim() removes surrounding spaces; a blank search does not narrow the results.
            $search = trim($filters['search']);

            if (strlen($search) > 100) {
                throw new AppError(
                    'VALIDATION_ERROR',
                    'search must not exceed 100 bytes.',
                    400
                );
            }

            if ($search !== '') {
                // mb_strtoupper() handles UTF-8 case conversion; str_replace() escapes literal LIKE characters.
                // Outer % characters mean contains. ESCAPE ! makes user %, _ and ! literal.
                $parameters['search'] = '%' . str_replace(
                    ['!', '%', '_'],
                    ['!!', '!%', '!_'],
                    mb_strtoupper($search, 'UTF-8')
                ) . '%';

                $where[] = "(
                UPPER(TO_CHAR(V.VEHICLE_CODE)) LIKE :search ESCAPE '!'
                OR UPPER(V.REGISTRATION_NUMBER) LIKE :search ESCAPE '!'
                OR UPPER(V.DESCRIPTION) LIKE :search ESCAPE '!'
            )";
            }
        }

        // query() executes a read. TO_CHAR makes numeric codes searchable; UPPER ignores case.
        // implode() joins conditions; stable ordering and OFFSET/FETCH select the requested page.
        $rows = $this->connection->query(
            'SELECT V.ID, V.VEHICLE_CODE, V.REGISTRATION_NUMBER,
                V.DESCRIPTION, V.CAPACITY, V.ISACTIVE
         FROM ME_LOG_VEHICLES V
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY V.VEHICLE_CODE, V.ID
         OFFSET :offset ROWS FETCH NEXT :page_size ROWS ONLY',
            $parameters
        );

        // array_map() applies present() to each row; present(...) creates a callable from the method.
        return [
            'items' => array_map($this->present(...), $rows),
            'page' => $page,
            'page_size' => $size,
        ];
    }

    /** Validate read access and return one object; find() supplies the same 404 for missing/foreign IDs. */
    public function getVehicleById(Actor $actor, int $id): array
    {
        $this->authorize($actor, 'read');
        $this->validateId($id);
        return $this->present($this->find($actor, $id));
    }

    /** Validate a new vehicle, insert it atomically and return the saved record. */
    public function createVehicle(Actor $actor, array $input): array
    {
        $this->authorize($actor, 'create');
        $this->allowedFields($input, ['action_reference', 'vehicle_code', 'registration_number', 'description', 'capacity', 'is_active']);
        $this->validateReference($input);

        if (!isset($input['vehicle_code']) || !is_int($input['vehicle_code']) || $input['vehicle_code'] < 1) {
            throw new AppError('VALIDATION_ERROR', 'vehicle_code must be a positive JSON integer.', 400);
        }
        $registration = $input['registration_number'] ?? null;
        if (!is_string($registration)) {
            throw new AppError('VALIDATION_ERROR', 'registration_number is required and must be a string.', 400);
        }
        // Standardize registration before uniqueness checks; strlen() below checks byte length.
        // preg_match() rejects control characters; it is a regular-expression check.
        $registration = strtoupper(trim($registration));
        if ($registration === '' || strlen($registration) > 40 || preg_match('/[\x00-\x1F\x7F]/', $registration)) {
            throw new AppError('VALIDATION_ERROR', 'registration_number must contain 1-40 bytes without control characters.', 400);
        }
        // Reuse the capacity rule so all writes accept the same precision and bind-safe type.
        $capacity = $this->validateCapacity($input['capacity'] ?? null);
        // Description is optional; null binds safely and represents no description.
        $description = $input['description'] ?? null;
        if ($description !== null && (!is_string($description) || strlen($description) > 255)) {
            throw new AppError('VALIDATION_ERROR', 'description must be a string of at most 255 bytes or null.', 400);
        }
        // Missing active flag defaults to T; explicit invalid/null values are rejected.
        $active = array_key_exists('is_active', $input) ? $input['is_active'] : 'T';
        if (!in_array($active, ['T', 'F'], true)) {
            throw new AppError('VALIDATION_ERROR', 'is_active must be T or F.', 400);
        }

        // Prerequisite: audit columns must match the shared ME_USERS.ID datatype.
        // Never substitute a hardcoded user or cast a nonnumeric user ID to integer.
        // Start one business transaction; execute() rejects writes without an active transaction.
        $this->connection->begin();
        try {
            // Friendly duplicate check; database unique constraints still protect concurrent writes.
            $duplicates = $this->connection->query(
                'SELECT VEHICLE_CODE, REGISTRATION_NUMBER FROM ME_LOG_VEHICLES
                 WHERE COMPANY_ID = :company_id
                 AND (VEHICLE_CODE = :vehicle_code OR UPPER(TRIM(REGISTRATION_NUMBER)) = :registration)',
                ['company_id' => $actor->companyId, 'vehicle_code' => $input['vehicle_code'], 'registration' => $registration]
            );
            if ($duplicates !== []) {
                throw new AppError('DUPLICATE_VEHICLE', 'Vehicle code or registration number already exists.', 409);
            }
            $this->connection->execute(
                'INSERT INTO ME_LOG_VEHICLES
                 (COMPANY_ID, VEHICLE_CODE, REGISTRATION_NUMBER, DESCRIPTION, CAPACITY, ISACTIVE,
                  CREATED_AT, UPDATED_AT, CREATED_BY, UPDATED_BY)
                 VALUES (:company_id, :vehicle_code, :registration, :description, :capacity, :active,
                         SYSTIMESTAMP, SYSTIMESTAMP, :created_by, :updated_by)',
                [
                    'company_id' => $actor->companyId,
                    'vehicle_code' => $input['vehicle_code'],
                    'registration' => $registration,
                    'description' => $description,
                    'capacity' => $capacity,
                    'active' => $active,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id
                ]
            );
            $rows = $this->connection->query(
                'SELECT ID, VEHICLE_CODE, REGISTRATION_NUMBER, DESCRIPTION, CAPACITY, ISACTIVE
                 FROM ME_LOG_VEHICLES WHERE COMPANY_ID = :company_id AND VEHICLE_CODE = :vehicle_code',
                ['company_id' => $actor->companyId, 'vehicle_code' => $input['vehicle_code']]
            );
            if ($rows === []) {
                throw new \LogicException('Created vehicle could not be retrieved.');
            }
            // Query by company plus unique vehicle_code to retrieve the ID generated by Oracle.
            $result = $this->present($rows[0]);
            // Persist every successful write and release row locks.
            $this->connection->commit();
            return $result;
        } catch (\Throwable $error) {
            // Undo all writes and release locks on any Exception or PHP Error (Throwable covers both).
            $this->connection->rollback();
            // Oracle code 1 means a unique-constraint conflict; return a safe 409 rather than raw SQL.
            if ($error instanceof DatabaseError && $error->oracleCode === 1) {
                throw new AppError('DUPLICATE_VEHICLE', 'Vehicle code or registration number conflicts with an existing record.', 409);
            }
            throw $error;
        }
    }

    /** Change only supplied fields; lock the vehicle and protect assignments/history. */
    public function updateVehicle(Actor $actor, int $id, array $input): array
    {
        $this->authorize($actor, 'update');
        $this->validateId($id);
        // Fixed allowlist limits updateable business fields. Metadata is validated separately.
        $editable = ['vehicle_code', 'registration_number', 'description', 'capacity', 'is_active'];
        $this->allowedFields($input, ['action_reference', ...$editable]);
        $this->validateReference($input);
        // array_keys() extracts supplied names; array_intersect() checks for any editable field.
        if (array_intersect(array_keys($input), $editable) === []) {
            throw new AppError('VALIDATION_ERROR', 'Provide at least one vehicle field to update.', 400);
        }

        // Map validated request fields to trusted database column names; omitted fields stay unchanged.
        $values = [];
        if (array_key_exists('vehicle_code', $input)) {
            if (!is_int($input['vehicle_code']) || $input['vehicle_code'] < 1) {
                throw new AppError('VALIDATION_ERROR', 'vehicle_code must be a positive JSON integer.', 400);
            }
            $values['VEHICLE_CODE'] = $input['vehicle_code'];
        }
        if (array_key_exists('registration_number', $input)) {
            if (!is_string($input['registration_number'])) {
                throw new AppError('VALIDATION_ERROR', 'registration_number must be a nonempty string.', 400);
            }
            $registration = strtoupper(trim($input['registration_number']));
            if ($registration === '' || strlen($registration) > 40 || preg_match('/[\x00-\x1F\x7F]/', $registration)) {
                throw new AppError('VALIDATION_ERROR', 'registration_number must contain 1-40 bytes without control characters.', 400);
            }
            $values['REGISTRATION_NUMBER'] = $registration;
        }
        if (array_key_exists('description', $input)) {
            if ($input['description'] !== null && (!is_string($input['description']) || strlen($input['description']) > 255)) {
                throw new AppError('VALIDATION_ERROR', 'description must be a string of at most 255 bytes or null.', 400);
            }
            $values['DESCRIPTION'] = $input['description'];
        }
        if (array_key_exists('capacity', $input)) {
            $values['CAPACITY'] = $this->validateCapacity($input['capacity']);
        }
        if (array_key_exists('is_active', $input)) {
            if (!in_array($input['is_active'], ['T', 'F'], true)) {
                throw new AppError('VALIDATION_ERROR', 'is_active must be T or F.', 400);
            }
            $values['ISACTIVE'] = $input['is_active'];
        }

        // Start one business transaction; execute() rejects writes without an active transaction.
        $this->connection->begin();
        try {
            $vehicle = $this->find($actor, $id, true);
            // Keep only actual differences to reject no-op updates and avoid false history restrictions.
            $changes = [];
            foreach ($values as $column => $value) {
                $previous = $vehicle[$column];
                // Oracle treats an empty string as NULL. Compare exact KG without floats.
                if ($column === 'CAPACITY') {
                    $same = $this->capacityKey((string) $previous) === $this->capacityKey($value);
                } elseif ($column === 'VEHICLE_CODE') {
                    $same = (string) $previous === (string) $value;
                } else {
                    $same = $previous === ($value === '' ? null : $value);
                }
                if (!$same) {
                    $changes[$column] = $value;
                }
            }
            if ($changes === []) {
                throw new AppError('VALIDATION_ERROR', 'At least one field must actually change.', 400);
            }

            $trips = $this->connection->query(
                'SELECT STATUS FROM ME_LOG_TRIPS WHERE COMPANY_ID = :company_id AND VEHICLE_ID = :vehicle_id',
                ['company_id' => $actor->companyId, 'vehicle_id' => $id]
            );
            if ($trips !== [] && (isset($changes['VEHICLE_CODE']) || isset($changes['REGISTRATION_NUMBER']))) {
                throw new AppError('REFERENCED_RECORD', 'Vehicle code and registration cannot change after first Trip use.', 409);
            }
            // array_filter() keeps active Trip rows; in_array(..., true) checks D/S without type coercion.
            // static fn is a short callback with no $this binding. D=Draft, S=Dispatched.
            $assigned = array_filter($trips, static fn(array $trip): bool => in_array($trip['STATUS'], ['D', 'S'], true)) !== [];
            if ($assigned && (isset($changes['CAPACITY']) || (($changes['ISACTIVE'] ?? null) === 'F'))) {
                throw new AppError('RESOURCE_IN_USE', 'An assigned vehicle cannot change capacity or be deactivated.', 409);
            }

            if (isset($changes['VEHICLE_CODE']) || isset($changes['REGISTRATION_NUMBER'])) {
                // Friendly duplicate check; database unique constraints still protect concurrent writes.
                $duplicates = $this->connection->query(
                    'SELECT ID FROM ME_LOG_VEHICLES WHERE COMPANY_ID = :company_id AND ID <> :id
                     AND (VEHICLE_CODE = :vehicle_code OR UPPER(TRIM(REGISTRATION_NUMBER)) = :registration)',
                    [
                        'company_id' => $actor->companyId,
                        'id' => $id,
                        'vehicle_code' => $changes['VEHICLE_CODE'] ?? $vehicle['VEHICLE_CODE'],
                        'registration' => $changes['REGISTRATION_NUMBER'] ?? $vehicle['REGISTRATION_NUMBER']
                    ]
                );
                if ($duplicates !== []) {
                    throw new AppError('DUPLICATE_VEHICLE', 'Vehicle code or registration number already exists.', 409);
                }
            }

            $set = [];
            $parameters = ['company_id' => $actor->companyId, 'id' => $id, 'updated_by' => $actor->id];
            // These column names come only from the fixed allowlist above.
            foreach ($changes as $column => $value) {
                // strtolower() builds a bind name, e.g. CAPACITY -> set_capacity, not user-provided SQL.
                $parameter = 'set_' . strtolower($column);
                $set[] = $column . ' = :' . $parameter;
                $parameters[$parameter] = $value;
            }
            // Oracle supplies the change time; an insert default would not update this automatically.
            $set[] = 'UPDATED_AT = SYSTIMESTAMP';
            $set[] = 'UPDATED_BY = :updated_by';
            $count = $this->connection->execute(
                'UPDATE ME_LOG_VEHICLES SET ' . implode(', ', $set) . ' WHERE COMPANY_ID = :company_id AND ID = :id',
                $parameters
            );
            // execute() returns affected rows, not a generated ID. A locked single-row write must affect one row.
            if ($count !== 1) {
                throw new \LogicException('Unexpected vehicle update count.');
            }
            $result = $this->present($this->find($actor, $id));
            // Persist every successful write and release row locks.
            $this->connection->commit();
            return $result;
        } catch (\Throwable $error) {
            // Undo all writes and release locks on any Exception or PHP Error (Throwable covers both).
            $this->connection->rollback();
            // Oracle code 1 means a unique-constraint conflict; return a safe 409 rather than raw SQL.
            if ($error instanceof DatabaseError && $error->oracleCode === 1) {
                throw new AppError('DUPLICATE_VEHICLE', 'Vehicle code or registration number conflicts with an existing record.', 409);
            }
            throw $error;
        }
    }

    /** Normalize numeric strings for exact comparison: "30" and "030.000" become "30.000". */
    private function capacityKey(string $value): string
    {
        // explode() splits whole/fraction parts; array_pad() supplies an empty fraction if absent.
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        // ltrim() removes leading zeroes; ?: keeps zero as 0; str_pad() fills three decimal places.
        return (ltrim($whole, '0') ?: '0') . '.' . str_pad($fraction, 3, '0');
    }

    /** Physically delete only a never-used vehicle; retain anything with Trip history. */
    public function deleteVehicle(Actor $actor, int $id, array $input): array
    {
        $this->authorize($actor, 'delete');
        $this->validateId($id);
        $this->allowedFields($input, ['action_reference']);
        $this->validateReference($input);
        // Start one business transaction; execute() rejects writes without an active transaction.
        $this->connection->begin();
        try {
            $this->find($actor, $id, true);
            $trips = $this->connection->query(
                'SELECT ID FROM ME_LOG_TRIPS WHERE COMPANY_ID = :company_id
                 AND VEHICLE_ID = :vehicle_id FETCH FIRST 1 ROWS ONLY',
                ['company_id' => $actor->companyId, 'vehicle_id' => $id]
            );
            if ($trips !== []) {
                throw new AppError('REFERENCED_RECORD', 'Vehicle has trip history and cannot be deleted. Deactivate it when available.', 409);
            }
            $count = $this->connection->execute(
                'DELETE FROM ME_LOG_VEHICLES WHERE COMPANY_ID = :company_id AND ID = :id',
                ['company_id' => $actor->companyId, 'id' => $id]
            );
            if ($count !== 1) {
                throw new \LogicException('Unexpected vehicle delete count.');
            }
            // Persist every successful write and release row locks.
            $this->connection->commit();
            return ['id' => $id, 'deleted' => true];
        } catch (\Throwable $error) {
            // Undo all writes and release locks on any Exception or PHP Error (Throwable covers both).
            $this->connection->rollback();
            // Oracle code 2292 means a child record prevents deletion; preserve that history.
            if ($error instanceof DatabaseError && $error->oracleCode === 2292) {
                throw new AppError('REFERENCED_RECORD', 'Vehicle is referenced by another record and cannot be deleted.', 409);
            }
            throw $error;
        }
    }

    /** Central permission check, also protecting service calls made outside the API route. */
    private function authorize(Actor $actor, string $action): void
    {
        (new Authorization($this->connection))->requirePermission($actor, 'logistics.vehicles', $action);
    }

    /** Reject zero/negative IDs before querying; the route validates the original input type. */
    private function validateId(int $id): void
    {
        if ($id < 1) {
            throw new AppError('VALIDATION_ERROR', 'Vehicle ID must be a positive integer.', 400);
        }
    }

    /** Read within Actor's company; optionally lock the row until transaction commit/rollback. */
    private function find(Actor $actor, int $id, bool $lock = false): array
    {
        $rows = $this->connection->query(
            'SELECT ID, VEHICLE_CODE, REGISTRATION_NUMBER, DESCRIPTION, CAPACITY, ISACTIVE
             FROM ME_LOG_VEHICLES WHERE COMPANY_ID = :company_id AND ID = :id'
                . ($lock ? ' FOR UPDATE' : ''),
            ['company_id' => $actor->companyId, 'id' => $id]
        );
        // Never reveal whether a missing ID actually belongs to another company.
        if ($rows === []) {
            throw new AppError('NOT_FOUND', 'Vehicle not found.', 404);
        }
        return $rows[0];
    }

    /** Reject unknown fields and attempts to supply server-owned fields such as company_id. */
    private function allowedFields(array $input, array $allowed): void
    {
        // array_diff() finds supplied field names that do not occur in the allowlist.
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new AppError('VALIDATION_ERROR', 'Request contains unsupported or read-only fields.', 400);
        }
    }

    /** Check command-reference syntax; this function alone does not implement replay protection. */
    private function validateReference(array $input): void
    {
        // Only letters, digits, underscore and hyphen are allowed, with a length of 1-100.
        $reference = $input['action_reference'] ?? null;
        if (!is_string($reference) || !preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $reference)) {
            throw new AppError('VALIDATION_ERROR', 'action_reference requires 1-100 letters, digits, underscores or hyphens.', 400);
        }
        // Validation only. Persist/replay this reference through the shared action executor.
    }

    /** Validate exact positive NUMBER(12,3) capacity without converting it to a floating-point value. */
    private function validateCapacity(mixed $value): string
    {
        // NUMBER(12,3): at most nine digits before the decimal; no float binding.
        if (
            !is_string($value) || !preg_match('/^[0-9]{1,9}(?:\.[0-9]{1,3})?$/D', $value)
            || !preg_match('/[1-9]/', $value)
        ) {
            throw new AppError('VALIDATION_ERROR', 'capacity must be a positive KG decimal string with at most 9 integer digits and 3 decimal places.', 400);
        }
        return $value;
    }

    /** Convert Oracle's uppercase column names into the JSON field names used by the client. */
    private function present(array $row): array
    {
        return [
            // Generated record identity; use this value in get/update/delete, not vehicle_code.
            'id' => $row['ID'],
            // Business fleet code supplied by the maintainer.
            'vehicle_code' => $row['VEHICLE_CODE'],
            // Vehicle registration in its stored normalized format.
            'registration_number' => $row['REGISTRATION_NUMBER'],
            // Optional description; keep null when absent.
            'description' => $row['DESCRIPTION'],
            // Preserve exact decimal data as a JSON string rather than a floating-point number.
            'capacity' => (string) $row['CAPACITY'],
            // Stored T/F flag; this API exposes that same convention.
            'is_active' => $row['ISACTIVE'],
            // Array unpacking optionally adds a derived field only when a query supplied it.
            ...(array_key_exists('ASSIGNMENT_STATUS', $row) ? ['assignment_status' => $row['ASSIGNMENT_STATUS']] : []),
        ];
    }
}
