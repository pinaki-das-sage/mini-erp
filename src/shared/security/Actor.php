<?php
declare(strict_types=1);

namespace MiniErp\shared\security;

use MiniErp\shared\AppError;

final readonly class Actor
{
    /** @param list<string> $roles */
    public function __construct(
        public string $id,
        public string $username,
        public array $roles,
        public int $companyId,
        public bool $isAdmin = false,
        public int $securityVersion = 1,
        public bool $mustChangePassword = false,
        public string $companyName = 'SIA-MINI-ERP',
    ) {}

    public function requireAdmin(): void
    {
        if ($this->mustChangePassword) {
            throw new AppError('PASSWORD_CHANGE_REQUIRED', 'Change your temporary password first.', 403);
        }
        if (!$this->isAdmin) {
            throw new AppError('FORBIDDEN', 'Only administrators can access Settings.', 403);
        }
    }

    public function requireRole(string $role): void
    {
        if (!in_array($role, $this->roles, true)) {
            throw new AppError('FORBIDDEN', 'Your role cannot perform this operation.', 403);
        }
    }
}
