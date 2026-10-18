<?php

namespace App\Services\Ecosystem;

use RuntimeException;

/**
 * A sign-in or password-change step of a new Admin Tenant that cannot go on. `state` selects the translated page text; the message is
 * for logs only and never contains a password.
 */
final class ActivationException extends RuntimeException
{
    public const WRONG_PASSWORD = 'wrong_password';

    public const LOCKED = 'locked';

    public const EXPIRED = 'expired';

    public const USED = 'used';

    public const INVALID = 'invalid';

    public const SAME_PASSWORD = 'same_password';

    public function __construct(
        public readonly string $state,
        public readonly int $status,
        string $message = '',
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message !== '' ? $message : $state);
    }
}
