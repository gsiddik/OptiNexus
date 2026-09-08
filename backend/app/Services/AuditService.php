<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Central write path for audit_logs. Records are append-only (see
 * AuditLog::booted) - this service is the only supported way to create them.
 */
class AuditService
{
    public function record(
        string $action,
        ?Request $request = null,
        ?User $actor = null,
        ?string $actorIdentity = null,
        ?string $tenantId = null,
        ?string $customerId = null,
        ?string $applicationId = null,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $source = 'cgo',
        ?array $metadata = null,
    ): AuditLog {
        $actor ??= $request?->user() instanceof User ? $request->user() : null;

        return AuditLog::create([
            'actor_user_id' => $actor?->id,
            'actor_identity' => $actorIdentity ?? $actor?->email,
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'application_id' => $applicationId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'request_id' => $request?->headers->get('X-Request-Id') ?? (string) Str::uuid(),
            'correlation_id' => $request?->headers->get('X-Correlation-Id'),
            'source' => $source,
            'metadata' => $metadata,
        ]);
    }
}
