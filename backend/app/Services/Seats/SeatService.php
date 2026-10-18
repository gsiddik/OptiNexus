<?php

namespace App\Services\Seats;

use App\Models\Application;
use App\Models\Entitlement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserInvitation;
use App\Services\Commercial\EntitlementService;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * Central seat-limit enforcement (owner decision 2026-10-18; contract: GOVERNANCE_CONTRACTS.md section 2d). OptiNexus is the only
 * authority: products show what this service reports and obey what it refuses.
 *
 *  - A seat belongs to a (tenant, application) pair and is bounded by the tenant's effective `user_limit` entitlement, the same
 *    entitlement source every other limit uses (no second framework). Only applications whose manifest declares `user_limit` are
 *    controlled; for any other the status is NOT_CONTROLLED and nothing is enforced or invented.
 *  - `used` counts people, once, however many roles they hold: distinct identities with an ACTIVE assignment (suspended people and
 *    Admin Tenants included, deleted identities not). Service principals are a separate registry (`service_accounts`) that can never
 *    appear in `user_application_access`, so they are not counted - by structure, not by e-mail pattern.
 *  - `reserved` counts open, unexpired invitations whose recipient does not hold the assignment yet (expiry counts at once, before
 *    any sweeper ran). A recipient who got the assignment another way stops reserving: the same seat is never counted twice.
 *  - A missing, invalid or unreadable limit never means unlimited: additions are refused (SEAT_ENTITLEMENT_*), existing access stays.
 *  - A limit below usage is "over limit": nothing is revoked, additions are refused, releasing seats is always possible.
 *
 * Every write that adds access takes the pair's lock FIRST in its transaction (lock()), then judges (authorize()), then writes.
 * Taking the lock before any row lock keeps one global order, so concurrent paths cannot deadlock on each other.
 */
class SeatService
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function limitKey(): string
    {
        return (string) config('seats.limit_key', 'user_limit');
    }

    /** Whether the application's own manifest declared the seat entitlement; without it the application has no seat contract. */
    public function declares(Application $application): bool
    {
        $keys = $application->metadata['manifest']['entitlement_limit_keys'] ?? [];

        return is_array($keys) && in_array($this->limitKey(), array_map('strtolower', array_filter($keys, 'is_string')), true);
    }

    // ---------------------------------------------------------------- reading

    /** Read-only; takes no lock, so it is a snapshot. Use authorize() inside a locked transaction to decide. */
    public function status(Tenant $tenant, Application $application): SeatStatus
    {
        [$used, $reserved] = $this->usage($tenant->id, $application->id);
        $code = $application->application_code;

        if (! $this->declares($application)) {
            return new SeatStatus($code, SeatStatus::NOT_CONTROLLED, null, $used, $reserved);
        }

        try {
            $limit = $this->resolveLimit($tenant, $application);
        } catch (Throwable $e) {
            report($e);

            return new SeatStatus($code, SeatStatus::ENTITLEMENT_UNAVAILABLE, null, $used, $reserved, problem: 'source_unreadable');
        }

        return new SeatStatus($code, $limit['state'], $limit['limit'], $used, $reserved, $limit['source'], $limit['problem']);
    }

    /**
     * Every application the tenant has assigned, with its seat picture (applications without a seat contract show NOT_CONTROLLED).
     *
     * @return Collection<int, SeatStatus>
     */
    public function forTenant(Tenant $tenant): Collection
    {
        return $tenant->applications()->wherePivot('status', 'ACTIVE')->orderBy('applications.name')->get()
            ->map(fn (Application $application): SeatStatus => $this->status($tenant, $application))->values();
    }

    /** @return array{0: int, 1: int} used, reserved - counted from the source rows. */
    public function usage(string $tenantId, string $applicationId): array
    {
        $used = DB::table('user_application_access as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->whereNull('u.deleted_at')
            ->where('a.tenant_id', $tenantId)->where('a.application_id', $applicationId)
            ->where('a.status', UserApplicationAccess::STATUS_ACTIVE)
            ->distinct()->count('a.user_id');

        $reserved = DB::table('user_invitations as i')
            ->where('i.tenant_id', $tenantId)->where('i.application_id', $applicationId)
            ->where('i.status', UserInvitation::STATUS_PENDING)->where('i.expires_at', '>', now())
            ->whereNotExists(fn (QueryBuilder $q) => $q->select(DB::raw(1))->from('users as u')
                ->join('user_application_access as a', 'a.user_id', '=', 'u.id')
                ->whereRaw('lower(u.email) = i.email')->whereNull('u.deleted_at')
                ->whereColumn('a.tenant_id', 'i.tenant_id')->whereColumn('a.application_id', 'i.application_id')
                ->where('a.status', UserApplicationAccess::STATUS_ACTIVE))
            ->count();

        return [(int) $used, (int) $reserved];
    }

    /**
     * What the source rows say that the counted figures do not: rows that exist but hold no seat. Read-only, for reconciliation; none
     * of it is ever changed by OptiNexus on its own (nothing is deleted, nothing is granted).
     *
     * @return array{expired_unstamped_invitations: int, redundant_invitations: int, assignments_of_deleted_identities: int, assignments_without_membership: int}
     */
    public function anomalies(string $tenantId, string $applicationId): array
    {
        $expired = UserInvitation::query()->where('tenant_id', $tenantId)->where('application_id', $applicationId)
            ->where('status', UserInvitation::STATUS_PENDING)->where('expires_at', '<=', now())->count();

        $pending = DB::table('user_invitations as i')
            ->where('i.tenant_id', $tenantId)->where('i.application_id', $applicationId)
            ->where('i.status', UserInvitation::STATUS_PENDING)->where('i.expires_at', '>', now());
        $redundant = (clone $pending)->whereExists(fn (QueryBuilder $q) => $q->select(DB::raw(1))->from('users as u')
            ->join('user_application_access as a', 'a.user_id', '=', 'u.id')
            ->whereRaw('lower(u.email) = i.email')->whereNull('u.deleted_at')
            ->whereColumn('a.tenant_id', 'i.tenant_id')->whereColumn('a.application_id', 'i.application_id')
            ->where('a.status', UserApplicationAccess::STATUS_ACTIVE))->count();

        $deleted = DB::table('user_application_access as a')->join('users as u', 'u.id', '=', 'a.user_id')->whereNotNull('u.deleted_at')
            ->where('a.tenant_id', $tenantId)->where('a.application_id', $applicationId)->where('a.status', UserApplicationAccess::STATUS_ACTIVE)->count();

        $memberless = DB::table('user_application_access as a')->join('users as u', 'u.id', '=', 'a.user_id')->whereNull('u.deleted_at')
            ->where('a.tenant_id', $tenantId)->where('a.application_id', $applicationId)->where('a.status', UserApplicationAccess::STATUS_ACTIVE)
            ->whereNotExists(fn (QueryBuilder $q) => $q->select(DB::raw(1))->from('tenant_memberships as m')
                ->whereColumn('m.user_id', 'a.user_id')->whereColumn('m.tenant_id', 'a.tenant_id'))->count();

        return [
            'expired_unstamped_invitations' => $expired,
            'redundant_invitations' => $redundant,
            'assignments_of_deleted_identities' => $deleted,
            'assignments_without_membership' => $memberless,
        ];
    }

    // ---------------------------------------------------------------- writing

    /**
     * Serialises every seat decision for the given applications of one tenant until the surrounding transaction ends. Call it FIRST in
     * the transaction, before any row lock or insert. Waits a bounded time, then answers "retry" instead of hanging.
     *
     * @param  array<int, string>  $applicationIds
     */
    public function lock(string $tenantId, array $applicationIds): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('The seat lock only exists inside a transaction.');
        }

        $ids = array_values(array_unique($applicationIds));
        sort($ids);

        foreach ($ids as $applicationId) {
            $deadline = microtime(true) + (float) config('seats.lock_wait_seconds', 5);

            do {
                $row = DB::selectOne('select pg_try_advisory_xact_lock(hashtextextended(?, 0)) as acquired', ['seat:'.$tenantId.':'.$applicationId]);

                if ($row && $row->acquired) {
                    continue 2;
                }

                usleep(20_000);
            } while (microtime(true) < $deadline);

            throw new SeatException(SeatException::CONTENTION, 'Another request is changing the seats of this tenant right now. Try again.', 503,
                ['recovery' => 'RETRY'], (int) config('seats.retry_after_seconds', 2), ['tenant_id' => $tenantId, 'application_id' => $applicationId]);
        }
    }

    /**
     * Judges one addition and refuses it, or returns the status it was judged on. Must run after lock() in the same transaction when
     * the caller is about to write. Never writes anything itself.
     *
     *  - Reserve: a new invitation (or a re-opened expired one) needs one free seat, unless the recipient already reserves or holds one.
     *  - Take: access granted (directly or by accepting an invitation). A recipient who holds a reservation converts it, so only
     *    `used + 1 <= limit` must hold; otherwise one free seat is needed.
     *
     * @throws SeatException
     */
    public function authorize(SeatOperation $operation, Tenant $tenant, Application $application, string $email, ?string $userId = null): SeatStatus
    {
        $status = $this->status($tenant, $application);

        if (! $status->controlled()) {
            return $status;
        }

        if ($userId !== null && $this->holdsSeat($tenant->id, $application->id, $userId)) {
            return $status;
        }

        if (! $status->decided()) {
            throw $this->refusal($status->blockedReason(), $status, $operation, $tenant, $application);
        }

        $reserved = $this->hasReservation($tenant->id, $application->id, $email);

        if ($operation === SeatOperation::Reserve && $reserved) {
            return $status;
        }

        if ($status->unlimited()) {
            return $status;
        }

        $needed = ($operation === SeatOperation::Take && $reserved) ? $status->used + 1 : $status->occupied() + 1;

        if ($needed <= $status->limit) {
            return $status;
        }

        throw $this->refusal($status->overBy() > 0 ? SeatException::OVER_LIMIT : SeatException::LIMIT_REACHED, $status, $operation, $tenant, $application);
    }

    /**
     * The one place that creates or re-activates an application assignment. Atomic: lock, judge, write in one transaction; an
     * assignment the person already holds is returned untouched (no second seat, no re-check).
     *
     * @throws SeatException
     */
    public function grantAccess(Tenant $tenant, Application $application, User $user): UserApplicationAccess
    {
        return DB::transaction(function () use ($tenant, $application, $user): UserApplicationAccess {
            $this->lock($tenant->id, [$application->id]);

            $access = UserApplicationAccess::query()->where('user_id', $user->id)->where('tenant_id', $tenant->id)
                ->where('application_id', $application->id)->lockForUpdate()->first();

            if ($access?->status === UserApplicationAccess::STATUS_ACTIVE) {
                return $access;
            }

            $this->authorize(SeatOperation::Take, $tenant, $application, $user->email, $user->id);

            if ($access) {
                $access->update(['status' => UserApplicationAccess::STATUS_ACTIVE]);

                return $access;
            }

            return UserApplicationAccess::query()->create([
                'user_id' => $user->id, 'tenant_id' => $tenant->id, 'application_id' => $application->id,
                'status' => UserApplicationAccess::STATUS_ACTIVE,
            ]);
        });
    }

    // ---------------------------------------------------------------- internals

    private function holdsSeat(string $tenantId, string $applicationId, string $userId): bool
    {
        return DB::table('user_application_access as a')->join('users as u', 'u.id', '=', 'a.user_id')
            ->whereNull('u.deleted_at')->where('a.user_id', $userId)->where('a.tenant_id', $tenantId)
            ->where('a.application_id', $applicationId)->where('a.status', UserApplicationAccess::STATUS_ACTIVE)->exists();
    }

    private function hasReservation(string $tenantId, string $applicationId, string $email): bool
    {
        return UserInvitation::query()->where('tenant_id', $tenantId)->where('application_id', $applicationId)
            ->where('email', mb_strtolower(trim($email)))->where('status', UserInvitation::STATUS_PENDING)
            ->where('expires_at', '>', now())->exists();
    }

    /**
     * The effective limit, told apart from every way it can fail to exist. Reads the same rows EntitlementService aggregates, so a value
     * that aggregation would silently turn into 0 (text, conflicting overrides) is reported as invalid instead.
     *
     * @return array{state: string, limit: ?int, source: ?string, problem: ?string}
     */
    protected function resolveLimit(Tenant $tenant, Application $application): array
    {
        $key = $this->limitKey();
        $rows = $this->entitlements->effectiveRows($tenant, $application->application_code)
            ->filter(fn (Entitlement $e): bool => $e->entitlement_type === Entitlement::TYPE_LIMIT && $e->entitlement_key === $key)->values();

        if ($rows->isEmpty()) {
            return ['state' => SeatStatus::ENTITLEMENT_MISSING, 'limit' => null, 'source' => null, 'problem' => null];
        }

        foreach ($rows as $row) {
            $raw = (string) $row->value;

            // Only the exact word is unlimited. A number that overflows to INF ("1e999") or is absurdly large is invalid, never unlimited.
            if ($raw !== 'unlimited' && (! is_numeric($raw) || ! is_finite((float) $raw) || (float) $raw > 2_000_000_000)) {
                return $this->invalid('non_numeric_value');
            }
        }

        $overrides = $rows->where('source_type', Entitlement::SOURCE_MANUAL_OVERRIDE);
        if ($overrides->count() > 1 && $overrides->map(fn (Entitlement $e): string => trim((string) $e->value))->unique()->count() > 1) {
            return $this->invalid('conflicting_overrides');
        }

        $effective = $this->entitlements->effectiveEntitlements($tenant, $application->application_code)
            ->first(fn (array $row): bool => $row['entitlement_type'] === Entitlement::TYPE_LIMIT && $row['entitlement_key'] === $key);

        if (! $effective) {
            return ['state' => SeatStatus::ENTITLEMENT_MISSING, 'limit' => null, 'source' => null, 'problem' => null];
        }

        $value = $effective['value'];

        if ($value === 'unlimited' || (is_float($value) && is_infinite($value))) {
            return ['state' => SeatStatus::UNLIMITED, 'limit' => null, 'source' => $effective['source'], 'problem' => null];
        }

        if (! is_numeric($value) || $value < 0 || floor((float) $value) !== (float) $value || $value > 2_000_000_000) {
            return $this->invalid('not_a_whole_non_negative_number');
        }

        return ['state' => SeatStatus::LIMITED, 'limit' => (int) $value, 'source' => $effective['source'], 'problem' => null];
    }

    /** @return array{state: string, limit: null, source: null, problem: string} */
    private function invalid(string $problem): array
    {
        return ['state' => SeatStatus::ENTITLEMENT_INVALID, 'limit' => null, 'source' => null, 'problem' => $problem];
    }

    private function refusal(string $code, SeatStatus $status, SeatOperation $operation, Tenant $tenant, Application $application): SeatException
    {
        [$message, $http, $recovery] = match ($code) {
            SeatException::LIMIT_REACHED => ['No seat is left for this application in this tenant. Free a seat (remove access or withdraw a pending invitation) or ask an operator to raise the user limit.', 409, 'FREE_SEAT_OR_RAISE_LIMIT'],
            SeatException::OVER_LIMIT => ['This tenant uses more seats than its current user limit for this application. Nothing is removed automatically, but nobody can be added until usage is back within the limit or the limit is raised.', 409, 'REDUCE_USAGE_OR_RAISE_LIMIT'],
            SeatException::ENTITLEMENT_MISSING => ['This application is seat-controlled but the tenant has no active user limit for it, so nobody can be added. An operator must set the entitlement.', 409, 'SET_ENTITLEMENT'],
            SeatException::ENTITLEMENT_INVALID => ['The user limit of this tenant for this application is not valid, so nobody can be added. An operator must correct the entitlement.', 409, 'FIX_ENTITLEMENT'],
            default => ['Seat capacity could not be determined right now. Nobody was added. Try again shortly.', 503, 'RETRY'],
        };

        return new SeatException($code, $message, $http, [
            'application_code' => $application->application_code,
            'limit' => $status->limit,
            'used' => $status->used,
            'reserved' => $status->reserved,
            'available' => $status->available(),
            'over_limit' => $status->overBy() > 0,
            'recovery' => $recovery,
        ], $http === 503 ? (int) config('seats.retry_after_seconds', 2) : null, [
            'tenant_id' => $tenant->id, 'application_id' => $application->id, 'operation' => $operation->value,
        ]);
    }
}
