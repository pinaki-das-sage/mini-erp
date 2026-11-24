<?php

declare(strict_types=1);

namespace MiniErp\logistics\vehicle;

use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

class VehicleService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }


    public function getVehicles(Actor $actor, array $pagination): array
    {
        $authorization = new Authorization($this->connection);

        $authorization->requirePermission(
            $actor,
            'logistics.vehicles',
            'read'
        );

        $sql = 'SELECT ID, VEHICLE_CODE, REGISTRATION_NUMBER,
        DESCRIPTION, CAPACITY, ISACTIVE 
        FROM ME_LOG_VEHICLES 
        WHERE COMPANY_ID = :companyId 
        ORDER BY ID
        OFFSET :offset ROWS
        FETCH NEXT :page_size ROWS ONLY';

        $vehicles = $this->connection->query($sql, [
            'companyId' => $actor->companyId,
            'offset' => $pagination['offset'],
            'page_size' => $pagination['page_size']
        ]);

        return [
            'items' => $vehicles,
            'page' => $pagination['page'],
            'page_size' => $pagination['page_size']
        ];
    }
}
