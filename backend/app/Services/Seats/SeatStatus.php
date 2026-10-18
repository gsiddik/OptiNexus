<?php

namespace App\Services\Seats;

/**
 * The seat picture of one application in one tenant, derived on demand from the source rows (access assignments and open
 * invitations) and the effective `user_limit` entitlement. Nothing here is stored, so it cannot drift.
 *
 * `used` = distinct people (once, whatever their roles) holding an ACTIVE assignment; `reserved` = open, unexpired invitations whose
 * recipient does not hold the assignment yet. Availability is never negative: a limit below usage is reported as `over_limit`.
 */
final readonly class SeatStatus
{
    public const NOT_CONTROLLED = 'NOT_CONTROLLED';

    public const LIMITED = 'LIMITED';

    public const UNLIMITED = 'UNLIMITED';

    public const ENTITLEMENT_MISSING = 'ENTITLEMENT_MISSING';

    public const ENTITLEMENT_INVALID = 'ENTITLEMENT_INVALID';

    public const ENTITLEMENT_UNAVAILABLE = 'ENTITLEMENT_UNAVAILABLE';

    public function __construct(
        public string $applicationCode,
        public string $state,
        public ?int $limit,
        public int $used,
        public int $reserved,
        public ?string $limitSource = null,
        public ?string $problem = null,
    ) {}

    public function controlled(): bool
    {
        return $this->state !== self::NOT_CONTROLLED;
    }

    /** Whether the limit is known: only then can an addition be judged. Anything else fails closed, it never means unlimited. */
    public function decided(): bool
    {
        return in_array($this->state, [self::NOT_CONTROLLED, self::LIMITED, self::UNLIMITED], true);
    }

    public function unlimited(): bool
    {
        return $this->state === self::UNLIMITED;
    }

    public function occupied(): int
    {
        return $this->used + $this->reserved;
    }

    public function available(): ?int
    {
        return $this->state === self::LIMITED ? max(0, $this->limit - $this->occupied()) : null;
    }

    public function overBy(): int
    {
        return $this->state === self::LIMITED ? max(0, $this->occupied() - $this->limit) : 0;
    }

    /** The code an addition would be refused with right now, or null when one more person fits. */
    public function blockedReason(): ?string
    {
        return match ($this->state) {
            self::ENTITLEMENT_MISSING => SeatException::ENTITLEMENT_MISSING,
            self::ENTITLEMENT_INVALID => SeatException::ENTITLEMENT_INVALID,
            self::ENTITLEMENT_UNAVAILABLE => SeatException::ENTITLEMENT_UNAVAILABLE,
            self::LIMITED => $this->overBy() > 0 ? SeatException::OVER_LIMIT : ($this->available() === 0 ? SeatException::LIMIT_REACHED : null),
            default => null,
        };
    }

    public function canAdd(): bool
    {
        return $this->blockedReason() === null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'application_code' => $this->applicationCode,
            'controlled' => $this->controlled(),
            'state' => $this->state,
            'limit' => $this->limit,
            'unlimited' => $this->unlimited(),
            'used' => $this->used,
            'reserved' => $this->reserved,
            'available' => $this->available(),
            'over_limit' => $this->overBy() > 0,
            'over_by' => $this->overBy(),
            'can_add' => $this->canAdd(),
            'blocked_reason' => $this->blockedReason(),
            'limit_source' => $this->limitSource,
        ];
    }
}
