<?php
declare(strict_types=1);

namespace MiniErp\purchasing\Model;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;

final readonly class VendorModel
{
    private const SELECT_COLUMNS = "CNYID, ID, VENDOR_ID, NAME, STATUS, CONTACT, EMAIL, PHONE, ADDR_1, ADDR_2,
        CITY, STATE, POSTAL_CODE, COUNTRY, PAYMENT_TERMS, CREATEDBY, MODIFIEDBY,
        TO_CHAR(WHENCREATED, 'YYYY-MM-DD\"T\"HH24:MI:SS.FF3\"Z\"') AS WHENCREATED,
        TO_CHAR(WHENMODIFIED, 'YYYY-MM-DD\"T\"HH24:MI:SS.FF3\"Z\"') AS WHENMODIFIED";

    public function __construct(private Connection $connection) {}

    /** @param array{offset: int, page_size: int} $page
     *  @param array{status?: string, search?: string} $filters
     *  @return list<array<string, mixed>>
     */
    public function list(Actor $actor, array $page, array $filters): array
    {
        $where = ['CNYID = :company_id'];
        $bindings = ['company_id' => $actor->companyId];
        if (isset($filters['status'])) {
            $where[] = 'LOWER(STATUS) = :status';
            $bindings['status'] = $filters['status'];
        }
        if (isset($filters['search'])) {
            $where[] = '(UPPER(NAME) LIKE UPPER(:search) OR UPPER(VENDOR_ID) LIKE UPPER(:search))';
            $bindings['search'] = '%' . $filters['search'] . '%';
        }
        $bindings['offset'] = $page['offset'];
        $bindings['limit'] = $page['page_size'];
        $rows = $this->connection->query(
            'SELECT ' . self::SELECT_COLUMNS . ' FROM ME_PUR_VENDOR WHERE ' . implode(' AND ', $where)
                . ' ORDER BY ID OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY',
            $bindings,
        );
        return array_map(self::mapVendor(...), $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(Actor $actor, string $id): ?array
    {
        $rows = $this->connection->query(
            'SELECT ' . self::SELECT_COLUMNS . ' FROM ME_PUR_VENDOR WHERE CNYID = :company_id AND ID = :id',
            ['company_id' => $actor->companyId, 'id' => $id],
        );
        return $rows === [] ? null : self::mapVendor($rows[0]);
    }

    /** @param array<string, string|null> $values
     *  @return array{id: string, cnyid: int, name: string}
     */
    public function create(Actor $actor, array $values): array
    {
        $id = bin2hex(random_bytes(16));
        try {
            (new TransactionManager($this->connection))->run(
                static fn (TransactionContext $tx): int => $tx->execute(
                    'INSERT INTO ME_PUR_VENDOR
                        (CNYID, ID, VENDOR_ID, NAME, STATUS, CONTACT, EMAIL, PHONE, ADDR_1, ADDR_2,
                         CITY, STATE, POSTAL_CODE, COUNTRY, PAYMENT_TERMS, CREATEDBY, MODIFIEDBY,
                         WHENCREATED, WHENMODIFIED)
                     VALUES
                        (:company_id, :id, :vendor_id, :name, :status, :contact, :email, :phone, :addr_1, :addr_2,
                         :city, :state, :postal_code, :country, :payment_terms, :created_by, :modified_by,
                         SYSTIMESTAMP, SYSTIMESTAMP)',
                    [
                        'company_id' => $actor->companyId, 'id' => $id,
                        'vendor_id' => $values['vendor_id'], 'name' => $values['name'],
                        'status' => $values['status'], 'contact' => $values['contact'],
                        'email' => $values['email'], 'phone' => $values['phone'],
                        'addr_1' => $values['addr_1'], 'addr_2' => $values['addr_2'],
                        'city' => $values['city'], 'state' => $values['state'],
                        'postal_code' => $values['postal_code'], 'country' => $values['country'],
                        'payment_terms' => $values['payment_terms'],
                        'created_by' => $actor->id, 'modified_by' => $actor->id,
                    ],
                ),
            );
        } catch (DatabaseError $error) {
            if ($error->oracleCode === 1) {
                throw new AppError('CONFLICT', 'A vendor with this vendor_id already exists in this company.', 409);
            }
            throw $error;
        }
        return ['id' => $id, 'cnyid' => $actor->companyId, 'name' => $values['name']];
    }

    /** @param array{status?: string, payment_terms?: string} $changes */
    public function update(Actor $actor, string $id, array $changes): array
    {
        return (new TransactionManager($this->connection))->run(
            function (TransactionContext $tx) use ($actor, $id, $changes): array {
                $rows = $tx->query(
                    'SELECT NAME FROM ME_PUR_VENDOR WHERE CNYID = :company_id AND ID = :id FOR UPDATE',
                    ['company_id' => $actor->companyId, 'id' => $id],
                );
                if ($rows === []) {
                    throw new AppError('NOT_FOUND', 'Vendor not found.', 404);
                }
                $sets = [];
                $bindings = ['company_id' => $actor->companyId, 'id' => $id, 'modified_by' => $actor->id];
                foreach (['status', 'payment_terms'] as $field) {
                    if (array_key_exists($field, $changes)) {
                        $sets[] = strtoupper($field) . ' = :' . $field;
                        $bindings[$field] = $changes[$field];
                    }
                }
                $sets[] = 'MODIFIEDBY = :modified_by';
                $sets[] = 'WHENMODIFIED = SYSTIMESTAMP';
                $tx->execute(
                    'UPDATE ME_PUR_VENDOR SET ' . implode(', ', $sets) . ' WHERE CNYID = :company_id AND ID = :id',
                    $bindings,
                );
                return ['id' => $id, 'cnyid' => $actor->companyId, 'name' => $rows[0]['NAME']];
            },
        );
    }

    /** @return array<string, mixed> */
    private static function mapVendor(array $row): array
    {
        return [
            'cnyid' => (int) $row['CNYID'],
            'id' => (string) $row['ID'],
            'vendor_id' => (string) $row['VENDOR_ID'],
            'name' => $row['NAME'],
            'status' => $row['STATUS'] === null ? null : strtolower((string) $row['STATUS']),
            'contact' => $row['CONTACT'] ?? null,
            'email' => $row['EMAIL'] ?? null,
            'phone' => $row['PHONE'] ?? null,
            'addr_1' => $row['ADDR_1'],
            'addr_2' => $row['ADDR_2'] ?? null,
            'city' => $row['CITY'],
            'state' => $row['STATE'] ?? null,
            'postal_code' => $row['POSTAL_CODE'] ?? null,
            'country' => $row['COUNTRY'],
            'payment_terms' => $row['PAYMENT_TERMS'] === null ? null : strtolower((string) $row['PAYMENT_TERMS']),
            'createdby' => $row['CREATEDBY'],
            'modifiedby' => $row['MODIFIEDBY'],
            'whencreated' => $row['WHENCREATED'],
            'whenmodified' => $row['WHENMODIFIED'],
        ];
    }
}
