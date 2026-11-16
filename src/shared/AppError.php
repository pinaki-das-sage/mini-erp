<?php
declare(strict_types=1);

namespace MiniErp\shared;

final class AppError extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
    ) {
        parent::__construct($message);
    }
}
