<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesSecretFile;
use App\Models\Application;
use App\Models\EventSubscription;
use App\Services\AuditService;
use App\Services\Event\EventSubscriptionService;
use Illuminate\Console\Command;

/**
 * Operator path to subscribe a product to event keys through the event broker (INTEGRATION_GUIDE.md §4b). Idempotent by
 * (application, pattern, tenant scope): a repeat only updates the endpoint. The signing secret exists only on creation or
 * with --rotate and goes to --secret-file (mode 0600), never to stdout or logs.
 */
class RegisterEventSubscription extends Command
{
    use WritesSecretFile;

    protected $signature = 'ecosystem:register-event-subscription
        {application_code : subscribing application}
        {event_key_pattern : event key, or prefix ending in ".*"}
        {--url= : webhook endpoint (https; http only with EVENT_WEBHOOK_ALLOW_HTTP=true)}
        {--tenant= : limit the subscription to one tenant id (default: every tenant the application is assigned to)}
        {--rotate : issue a new signing secret for the existing subscription}
        {--secret-file= : file that receives EVENT_WEBHOOK_SECRET (required when a secret is issued)}';

    protected $description = 'Subscribe a product to events delivered as signed webhooks (secret only to a 0600 file)';

    public function handle(EventSubscriptionService $subscriptions, AuditService $audit): int
    {
        $application = Application::query()->where('application_code', (string) $this->argument('application_code'))->first();
        if (! $application || $application->application_code === 'cgo') {
            $this->error('Unknown or non-registrable application_code.');

            return self::FAILURE;
        }

        $pattern = (string) $this->argument('event_key_pattern');
        $url = (string) $this->option('url');
        $tenantId = $this->option('tenant') ?: null;
        $file = (string) $this->option('secret-file');
        if ($problem = $subscriptions->patternProblem($pattern) ?? ($url !== '' ? $subscriptions->endpointProblem($url) : null)) {
            $this->error($problem);

            return self::FAILURE;
        }

        $subscription = EventSubscription::query()->where('application_id', $application->id)->where('event_key_pattern', $pattern)
            ->where('tenant_id', $tenantId)->first();
        $secret = null;

        if (! $subscription) {
            if ($url === '' || $file === '') {
                $this->error('A new subscription needs --url and --secret-file (the signing secret is issued now).');

                return self::FAILURE;
            }
            [$subscription, $secret] = $subscriptions->create($application, $pattern, $tenantId, $url, null);
            $action = 'created';
        } else {
            if ($url !== '' && $url !== $subscription->endpoint_url) {
                $subscription->update(['endpoint_url' => $url]);
            }
            if ($this->option('rotate')) {
                if ($file === '') {
                    $this->error('A secret is being issued: pass --secret-file so it can be stored with mode 0600.');

                    return self::FAILURE;
                }
                $secret = $subscriptions->rotate($subscription);
            }
            $action = $secret ? 'rotated' : 'exists';
        }

        if ($secret !== null && ! $this->writeSecretFile($file, ['EVENT_WEBHOOK_SECRET' => $secret])) {
            $this->error('The secret file could not be written; run again with --rotate to issue a fresh secret.');

            return self::FAILURE;
        }

        $audit->record('event.subscription.'.($action === 'exists' ? 'updated' : $action), null, applicationId: $application->id, tenantId: $tenantId,
            resourceType: 'EventSubscription', resourceId: $subscription->id, newValue: $subscription->only(['event_key_pattern', 'tenant_id', 'endpoint_url', 'status']), source: 'cli');

        $this->line("{$action} event_subscription={$subscription->id} secret_written=".($secret ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
