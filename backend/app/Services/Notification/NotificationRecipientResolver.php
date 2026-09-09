<?php

namespace App\Services\Notification;

use App\Models\NotificationRule;
use App\Models\Tenant;
use App\Models\UserRole;

/**
 * Resolves a rule's recipient_type + recipient_reference against a
 * trusted context (built by the caller, e.g. from an ingested Event or a
 * workflow instance) into a list of concrete user ids. Every resolution
 * path is scoped by tenant (and, where applicable, application) so an
 * external event can never fan a notification out to users outside the
 * rule's own tenant/application boundary - the one exception is
 * EVENT_CONTEXT, which is deliberately restricted to reading only under
 * the "data." prefix of the trusted event payload (never "actor" or
 * arbitrary context) so a producer cannot redirect notifications by
 * spoofing unrelated context fields.
 *
 * @return string[] user ids
 */
class NotificationRecipientResolver
{
    public function resolve(NotificationRule $rule, array $context): array
    {
        $tenantId = $rule->tenant_id ?? ($context['tenant']['id'] ?? null);
        $applicationId = $rule->application_id ?? ($context['application']['id'] ?? null);

        return match ($rule->recipient_type) {
            NotificationRule::RECIPIENT_SPECIFIC_USER => $rule->recipient_reference ? [$rule->recipient_reference] : [],
            NotificationRule::RECIPIENT_ACTOR => $this->single($context['actor']['user_id'] ?? null),
            NotificationRule::RECIPIENT_RESOURCE_OWNER => $this->single($context['resource']['owner_user_id'] ?? null),
            NotificationRule::RECIPIENT_EVENT_CONTEXT => $this->resolveEventContext($rule->recipient_reference, $context, $tenantId),
            NotificationRule::RECIPIENT_ROLE_MEMBERS => $this->usersWithRole($rule->recipient_reference, $tenantId),
            NotificationRule::RECIPIENT_TENANT_ADMINS => $this->usersWithRole($rule->recipient_reference ?: 'TENANT_ADMIN', $tenantId),
            NotificationRule::RECIPIENT_APPLICATION_ADMINS => $this->usersWithApplicationRole($rule->recipient_reference ?: 'APPLICATION_ADMIN', $applicationId),
            NotificationRule::RECIPIENT_CUSTOMER_ADMINS => $this->usersWithCustomerRole($rule->recipient_reference ?: 'CUSTOMER_ADMIN', $tenantId),
            default => [],
        };
    }

    private function single(mixed $value): array
    {
        return $value ? [(string) $value] : [];
    }

    private function resolveEventContext(?string $reference, array $context, ?string $tenantId): array
    {
        if (! $reference || ! str_starts_with($reference, 'data.')) {
            return [];
        }

        $value = $context;
        foreach (explode('.', $reference) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return [];
            }
            $value = $value[$segment];
        }

        if (! is_string($value) || ! $value) {
            return [];
        }

        // Cross-tenant guard: an event-context recipient must belong to
        // the same tenant the rule is scoped to (when the rule is scoped).
        if ($tenantId && ! \App\Models\User::whereKey($value)->whereHas('tenantMemberships', fn ($q) => $q->where('tenant_id', $tenantId))->exists()) {
            return [];
        }

        return [$value];
    }

    private function usersWithRole(?string $roleCode, ?string $tenantId): array
    {
        if (! $roleCode) {
            return [];
        }

        return UserRole::whereHas('role', fn ($q) => $q->where('code', $roleCode))
            ->when($tenantId, fn ($q) => $q->where(fn ($q2) => $q2->whereNull('tenant_id')->orWhere('tenant_id', $tenantId)))
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();
    }

    private function usersWithApplicationRole(?string $roleCode, ?string $applicationId): array
    {
        if (! $roleCode || ! $applicationId) {
            return [];
        }

        return UserRole::whereHas('role', fn ($q) => $q->where('code', $roleCode)->where('application_id', $applicationId))
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();
    }

    private function usersWithCustomerRole(?string $roleCode, ?string $tenantId): array
    {
        if (! $roleCode || ! $tenantId) {
            return [];
        }

        $customerId = Tenant::whereKey($tenantId)->value('customer_id');
        if (! $customerId) {
            return [];
        }

        $sisterTenantIds = Tenant::where('customer_id', $customerId)->pluck('id');

        return UserRole::whereHas('role', fn ($q) => $q->where('code', $roleCode))
            ->whereIn('tenant_id', $sisterTenantIds)
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();
    }
}
