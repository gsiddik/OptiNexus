<?php

namespace App\Services\Event;

/**
 * Events OptiNexus itself publishes (no producing application, so `source_system` is `optinexus` in the webhook). They go through
 * the same broker as product events: an application receives one only when it subscribed to the key
 * (`ecosystem:register-event-subscription`) and is assigned to the event's tenant.
 *
 * Both are facts about the tenant's application assignment, which only a subscription changes (SubscriptionApplicationService).
 * `data` follows the shared envelope: aggregate_type `tenant`, aggregate_id the tenant, and a payload naming the application and
 * the tenant, so a receiver needs no call back to OptiNexus.
 */
final class PlatformEvents
{
    /** The shared event envelope (the same as ApplicationEventCatalogSeeder::PAYLOAD_SCHEMA), used for the catalog entries of platform events. */
    public const PAYLOAD_SCHEMA = [
        'required' => ['aggregate_type', 'aggregate_id', 'payload'],
        'properties' => [
            'aggregate_type' => ['type' => 'string'],
            'aggregate_id' => ['type' => 'string'],
            'correlation' => ['type' => 'object'],
            'payload' => ['type' => 'object'],
        ],
    ];

    /** An application was assigned to a tenant (its subscription now covers it). A receiver can prepare the tenant, for example OptiRadar creates the tenant's group. */
    public const TENANT_APPLICATION_ASSIGNED = 'cgo.tenant.application_assigned';

    /** An application is no longer assigned to a tenant. Delivered only while the receiver is still assigned, so in practice to the other applications. */
    public const TENANT_APPLICATION_REMOVED = 'cgo.tenant.application_removed';

    /**
     * Whether the key is about the assignment of an application to a tenant. Applications are assigned when a subscription is created,
     * before it is ACTIVE, and these events exist so the application can prepare the tenant at once; the broker therefore delivers
     * them to an application assigned to the tenant even while its subscription is not active yet (nothing gives anyone access).
     */
    public static function isAssignment(string $eventKey): bool
    {
        return in_array($eventKey, [self::TENANT_APPLICATION_ASSIGNED, self::TENANT_APPLICATION_REMOVED], true);
    }

    /** @return array<string, array{name: string, description: string}> keyed by event_key */
    public static function catalog(): array
    {
        return [
            self::TENANT_APPLICATION_ASSIGNED => [
                'name' => 'Tenant application assigned',
                'description' => 'An application was assigned to a tenant by its subscription. payload: application_code, application_name, tenant_code, tenant_name.',
            ],
            self::TENANT_APPLICATION_REMOVED => [
                'name' => 'Tenant application removed',
                'description' => 'An application was removed from a tenant because no subscription covers it any more. payload: application_code, application_name, tenant_code, tenant_name.',
            ],
        ];
    }
}
