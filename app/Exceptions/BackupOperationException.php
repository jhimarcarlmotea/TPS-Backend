<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class BackupOperationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 500,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
