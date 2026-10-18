<?php

namespace App\Services\Delegation;

use RuntimeException;

/**
 * An invitation that cannot be shown or accepted. `state` selects the page the invitee sees (and the translated recovery text);
 * the message is for logs only and never contains the token.
 */
final class InvitationException extends RuntimeException
{
    public const INVALID = 'invalid';

    public const USED = 'used';

    public const REVOKED = 'revoked';

    public const EXPIRED = 'expired';

    public const UNAVAILABLE = 'unavailable';

    public const ROLES_CHANGED = 'roles_changed';

    public const NOT_LINKABLE = 'not_linkable';

    public const WRONG_PASSWORD = 'wrong_password';

    public const LOCKED = 'locked';

    public const CONFLICT = 'conflict';

    /** The tenant has no seat for this application right now (limit reached or lowered, entitlement missing or invalid). */
    public const NO_SEAT = 'no_seat';

    /** The seat could not be judged this moment (busy or unreadable source); nothing was granted. */
    public const SEAT_RETRY = 'seat_retry';

    public function __construct(
        public readonly string $state,
        public readonly int $status,
        string $message = '',
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message !== '' ? $message : $state);
    }
}
