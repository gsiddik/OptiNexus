<?php

namespace App\Services\Ecosystem;

use RuntimeException;

/** An operator request of the first Admin Tenant flow that must be refused; carries the API error code, HTTP status and optional details. */
final class AdminBootstrapException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        public readonly mixed $details = null,
    ) {
        parent::__construct($message);
    }
}
