<?php

namespace App\Services\Delegation;

use RuntimeException;

/** A delegated request that must be refused; carries the API error code, HTTP status and optional details. */
final class DelegationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 403,
        public readonly mixed $details = null,
    ) {
        parent::__construct($message);
    }
}
