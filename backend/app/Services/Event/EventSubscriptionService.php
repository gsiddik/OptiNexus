<?php

namespace App\Services\Event;

use App\Models\Application;
use App\Models\Event;
use App\Models\EventSubscription;
use App\Models\Tenant;
use App\Services\Commercial\SubscriptionAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Event broker subscriptions (owner decision K4: notifications only; financial facts stay on ordered feeds). An application
 * receives an event only when it subscribed to the key, the event belongs to a tenant the application is assigned to (and
 * subscribed to, when its subscription is required), and the subscription is not limited to another tenant. An event without
 * a tenant (platform event) only reaches subscriptions without a tenant. The producer never receives its own events.
 */
class EventSubscriptionService
{
    public const PATTERN = '/^[a-z][a-z0-9_-]*(\.[A-Za-z0-9_-]+)*(\.\*)?$/';

    public function __construct(private readonly SubscriptionAccess $subscriptions) {}

    /** @return ?string why the address cannot be used, or null */
    public function endpointProblem(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return match (true) {
            $parts === false || empty($parts['host']) => 'The endpoint must be an absolute URL.',
            isset($parts['user']) || isset($parts['pass']) => 'The endpoint must not carry credentials.',
            isset($parts['fragment']) => 'The endpoint must not have a fragment.',
            $scheme === 'https' => null,
            $scheme === 'http' && config('event_broker.allow_http') => null,
            default => 'The endpoint must use https.',
        };
    }

    public function patternProblem(string $pattern): ?string
    {
        return preg_match(self::PATTERN, $pattern) && str_contains($pattern, '.') ? null : 'Use an event key or a prefix ending in ".*" (for example optifleet.vehicle.*).';
    }

    /** @return array{0: EventSubscription, 1: string} the subscription and its signing secret (shown once) */
    public function create(Application $application, string $pattern, ?string $tenantId, string $url, ?string $actorId): array
    {
        $secret = $this->newSecret();
        $subscription = EventSubscription::create([
            'application_id' => $application->id,
            'event_key_pattern' => $pattern,
            'tenant_id' => $tenantId,
            'endpoint_url' => $url,
            'signing_secret' => $secret,
            'status' => EventSubscription::STATUS_ACTIVE,
            'created_by' => $actorId,
        ]);

        return [$subscription, $secret];
    }

    public function rotate(EventSubscription $subscription): string
    {
        $secret = $this->newSecret();
        $subscription->update(['signing_secret' => $secret, 'secret_rotated_at' => now()]);

        return $secret;
    }

    /** @return Collection<int, EventSubscription> */
    public function recipients(Event $event): Collection
    {
        return EventSubscription::query()
            ->where('status', EventSubscription::STATUS_ACTIVE)
            ->when($event->source_application_id, fn ($q) => $q->where('application_id', '!=', $event->source_application_id))
            ->with('application')
            ->get()
            ->filter(fn (EventSubscription $s) => $s->matches($event->event_key) && $this->mayReceive($s, $event))
            ->values();
    }

    /** Checked at fan-out and again before every attempt, so an unassigned tenant stops receiving at once. */
    public function mayReceive(EventSubscription $subscription, Event $event): bool
    {
        if (! $subscription->isActive() || ! $subscription->application) {
            return false;
        }
        if ($event->tenant_id === null) {
            return $subscription->tenant_id === null;
        }
        if ($subscription->tenant_id !== null && $subscription->tenant_id !== $event->tenant_id) {
            return false;
        }

        $tenant = Tenant::query()->find($event->tenant_id);
        $application = $subscription->application;
        if (! $tenant || ! $tenant->applications()->where('applications.id', $application->id)->wherePivot('status', 'ACTIVE')->exists()) {
            return false;
        }

        return ! $this->subscriptions->required($application) || $this->subscriptions->allowed($tenant, $application);
    }

    private function newSecret(): string
    {
        return 'whsec_'.Str::random(48);
    }
}
