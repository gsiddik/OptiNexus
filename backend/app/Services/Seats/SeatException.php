<?php

namespace App\Services\Seats;

use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A request that cannot get a seat. The codes are a public contract (GOVERNANCE_CONTRACTS.md 2d): products map them to their own
 * wording; the message is English for logs and API clients. Never carries a token or a credential.
 */
final class SeatException extends RuntimeException
{
    public const LIMIT_REACHED = 'SEAT_LIMIT_REACHED';

    public const OVER_LIMIT = 'SEAT_OVER_LIMIT';

    public const ENTITLEMENT_MISSING = 'SEAT_ENTITLEMENT_MISSING';

    public const ENTITLEMENT_INVALID = 'SEAT_ENTITLEMENT_INVALID';

    public const ENTITLEMENT_UNAVAILABLE = 'SEAT_ENTITLEMENT_UNAVAILABLE';

    public const CONTENTION = 'SEAT_CONTENTION';

    public const CODES = [
        self::LIMIT_REACHED, self::OVER_LIMIT, self::ENTITLEMENT_MISSING, self::ENTITLEMENT_INVALID,
        self::ENTITLEMENT_UNAVAILABLE, self::CONTENTION,
    ];

    /**
     * @param  array<string, mixed>  $details  what the caller may show: this tenant's own numbers and the recovery, nothing about others
     * @param  array<string, mixed>  $context  audit context (tenant, application, operation); never sent to the caller
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        public readonly array $details = [],
        public readonly ?int $retryAfter = null,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function retryable(): bool
    {
        return $this->retryAfter !== null;
    }

    /** API refusal in the common envelope; recorded in the audit trail because the transaction that threw it was rolled back. */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        try {
            app(AuditService::class)->record('seat.denied', $request,
                tenantId: $this->context['tenant_id'] ?? null, applicationId: $this->context['application_id'] ?? null,
                resourceType: 'Application', resourceId: $this->context['application_id'] ?? null,
                newValue: ['code' => $this->errorCode] + array_intersect_key($this->details, array_flip(['limit', 'used', 'reserved', 'over_limit'])),
                metadata: array_filter([
                    'operation' => $this->context['operation'] ?? null,
                    'path' => $this->context['path'] ?? null,
                    'service_account_id' => $request->attributes->get('service_account')?->id,
                    'acting_user_id' => is_string($request->input('acting_user_id')) && Str::isUuid($request->input('acting_user_id')) ? $request->input('acting_user_id') : null, // never an unvalidated value in the audit trail
                ], fn ($v) => $v !== null));
        } catch (Throwable $e) {
            report($e);
        }

        $response = response()->json([
            'success' => false,
            'error' => ['code' => $this->errorCode, 'message' => $this->getMessage(), 'details' => $this->details ?: null],
        ], $this->status);

        if ($this->retryAfter !== null) {
            $response->header('Retry-After', (string) max(1, $this->retryAfter));
        }

        return $response;
    }
}
