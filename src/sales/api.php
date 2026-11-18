<?php
declare(strict_types=1);

use MiniErp\sales\LeadService;
use MiniErp\shared\Api;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;

return static function (Api $api, Connection $connection): void {
    $leads = new LeadService($connection);
    $api->register('GET', '/sales/leads', 'sales.leads', 'read',
        static fn (Actor $actor, array $input, array $params, array $query): array => $leads->list($actor, $query));
    $api->register('GET', '/sales/leads/{id}', 'sales.leads', 'read',
        static fn (Actor $actor, array $input, array $params): array => $leads->detail($actor, $params['id']));
    $api->register('POST', '/sales/leads', 'sales.leads', 'create',
        static fn (Actor $actor, array $input): array => $leads->create($actor, $input), 201);
    $api->register('PATCH', '/sales/leads/{id}', 'sales.leads', 'update',
        static fn (Actor $actor, array $input, array $params): array => $leads->update($actor, $params['id'], $input));
    $api->register('POST', '/sales/leads/{id}/archive', 'sales.leads', 'archive',
        static fn (Actor $actor, array $input, array $params): array => $leads->archive($actor, $params['id'], $input));
    $api->register('POST', '/sales/leads/{id}/restore', 'sales.leads', 'restore',
        static fn (Actor $actor, array $input, array $params): array => $leads->restore($actor, $params['id'], $input));
};
