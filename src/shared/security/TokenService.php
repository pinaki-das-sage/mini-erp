<?php
declare(strict_types=1);

namespace MiniErp\shared\security;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;

final readonly class TokenService
{
    private \Closure $clock;

    public function __construct(private Connection $connection, private int $ttl = 3600, ?\Closure $clock = null)
    {
        if ($ttl < 60 || $ttl > 86400) {
            throw new AppError('CONFIGURATION_ERROR', 'API token lifetime must be 60-86400 seconds.', 503);
        }
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function login(string $username, #[\SensitiveParameter] string $password, string $company, string $clientAddress): array
    {
        $this->throttle($username, $company, $clientAddress);
        try {
            $actor = (new AuthService($this->connection))->authenticate($username, $password, $company);
        } catch (AppError $error) {
            if ($error->errorCode !== 'FORBIDDEN') {
                throw $error;
            }
            throw new AppError('UNAUTHENTICATED', 'Invalid company, username or password.', 401);
        }
        $token = bin2hex(random_bytes(32));
        $now = ($this->clock)();
        (new TransactionManager($this->connection))->run(function (TransactionContext $tx) use ($actor, $token, $now): void {
            $tx->execute(
                'INSERT INTO ME_API_TOKENS (TOKEN_HASH, COMPANY_ID, USER_ID, SECURITY_VERSION, ISSUED_AT, EXPIRES_AT)
                 VALUES (:hash, :company_id, :user_id, :version,
                    TO_TIMESTAMP_TZ(:issued, \'YYYY-MM-DD"T"HH24:MI:SSTZH:TZM\'),
                    TO_TIMESTAMP_TZ(:expires, \'YYYY-MM-DD"T"HH24:MI:SSTZH:TZM\'))',
                ['hash' => hash('sha256', $token), 'company_id' => $actor->companyId, 'user_id' => $actor->id,
                    'version' => $actor->securityVersion, 'issued' => gmdate('Y-m-d\TH:i:s', $now) . '+00:00',
                    'expires' => gmdate('Y-m-d\TH:i:s', $now + $this->ttl) . '+00:00'],
            );
        });
        return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $this->ttl,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + $this->ttl), 'must_change_password' => $actor->mustChangePassword];
    }

    public function authenticate(#[\SensitiveParameter] string $token): Actor
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            throw new AppError('UNAUTHENTICATED', 'Invalid or expired API token.', 401);
        }
        $rows = $this->connection->query(
            'SELECT USER_ID, COMPANY_ID, SECURITY_VERSION,
                ROUND((CAST(EXPIRES_AT AT TIME ZONE \'UTC\' AS DATE) - DATE \'1970-01-01\') * 86400) AS EXPIRES_AT,
                REVOKED_AT FROM ME_API_TOKENS WHERE TOKEN_HASH = :hash',
            ['hash' => hash('sha256', $token)],
        );
        if ($rows === [] || $rows[0]['REVOKED_AT'] !== null || (int) $rows[0]['EXPIRES_AT'] <= ($this->clock)()) {
            throw new AppError('UNAUTHENTICATED', 'Invalid or expired API token.', 401);
        }
        try {
            return (new AuthService($this->connection))->actor(
                $rows[0]['USER_ID'], (int) $rows[0]['COMPANY_ID'], (int) $rows[0]['SECURITY_VERSION'],
            );
        } catch (AppError $error) {
            if (!in_array($error->errorCode, ['FORBIDDEN', 'SESSION_EXPIRED'], true)) {
                throw $error;
            }
            throw new AppError('UNAUTHENTICATED', 'Invalid or expired API token.', 401);
        }
    }

    public function revoke(#[\SensitiveParameter] string $token): void
    {
        $this->authenticate($token);
        (new TransactionManager($this->connection))->run(function (TransactionContext $tx) use ($token): void {
            $changed = $tx->execute('UPDATE ME_API_TOKENS SET REVOKED_AT = TO_TIMESTAMP_TZ(:now, \'YYYY-MM-DD"T"HH24:MI:SSTZH:TZM\')
                WHERE TOKEN_HASH = :hash AND REVOKED_AT IS NULL',
                ['now' => gmdate('Y-m-d\TH:i:s', ($this->clock)()) . '+00:00', 'hash' => hash('sha256', $token)]);
            if ($changed !== 1) {
                throw new AppError('UNAUTHENTICATED', 'Invalid or expired API token.', 401);
            }
        });
    }

    private function throttle(string $username, string $company, string $clientAddress): void
    {
        $window = intdiv(($this->clock)(), 60);
        $keys = [
            hash('sha256', 'ip:' . $clientAddress) => 100,
            hash('sha256', 'account:' . strtoupper(trim($company)) . "\0" . strtolower(trim($username))) => 5,
        ];
        for ($attempt = 0; ; $attempt++) {
            try {
                // Commit counters even when authentication subsequently fails.
                $limited = (new TransactionManager($this->connection))->run(function (TransactionContext $tx) use ($keys, $window): bool {
                    $limited = false;
                    foreach ($keys as $key => $maximum) {
                        $tx->execute('MERGE INTO ME_API_LOGIN_LIMITS target USING (SELECT :key AS LOGIN_KEY FROM DUAL) source
                            ON (target.LOGIN_KEY = source.LOGIN_KEY)
                            WHEN MATCHED THEN UPDATE SET
                                ATTEMPTS = CASE WHEN target.WINDOW_ID < :window THEN 1 ELSE target.ATTEMPTS + 1 END,
                                WINDOW_ID = CASE WHEN target.WINDOW_ID < :window THEN :window ELSE target.WINDOW_ID END
                            WHEN NOT MATCHED THEN INSERT (LOGIN_KEY, WINDOW_ID, ATTEMPTS) VALUES (:key, :window, 1)',
                            ['key' => $key, 'window' => $window]);
                        $rows = $tx->query('SELECT WINDOW_ID, ATTEMPTS FROM ME_API_LOGIN_LIMITS WHERE LOGIN_KEY = :key', ['key' => $key]);
                        if ($rows === []) {
                            throw new AppError('DATABASE_ERROR', 'Login throttle record is missing.', 503);
                        }
                        $limited = $limited || (int) $rows[0]['ATTEMPTS'] > $maximum;
                    }
                    return $limited;
                });
                break;
            } catch (DatabaseError $error) {
                // Concurrent first inserts can collide; retry once after rollback.
                if ($error->oracleCode !== 1 || $attempt !== 0) {
                    throw $error;
                }
            }
        }
        if ($limited) {
            throw new AppError('RATE_LIMITED', 'Too many login attempts. Try again after one minute.', 429);
        }
    }
}
