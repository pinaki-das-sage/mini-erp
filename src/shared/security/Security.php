<?php
declare(strict_types=1);

namespace MiniErp\shared\security;

use MiniErp\shared\AppError;

final class Security
{
    public static function csrfToken(array &$session): string
    {
        if (!isset($session['csrf_token'])) {
            $session['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $session['csrf_token'];
    }

    public static function verifyCsrf(array $session, mixed $token): void
    {
        if (!is_string($token) || !isset($session['csrf_token']) || !hash_equals($session['csrf_token'], $token)) {
            throw new AppError('FORBIDDEN', 'Invalid CSRF token. Refresh the page and try again.', 403);
        }
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
