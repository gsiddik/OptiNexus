<?php

namespace App\Services\Integration;

use App\Jobs\DeliverIntegrationRequest;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\IntegrationEndpoint;
use App\Models\IntegrationLog;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStep;
use Illuminate\Support\Str;

/**
 * Resolves an Integration + IntegrationEndpoint from a workflow step's
 * config, writes a PENDING IntegrationLog, and queues the actual outbound
 * call (see DeliverIntegrationRequest) so the workflow engine never blocks
 * an HTTP request on an external system's response time.
 */
class IntegrationDeliveryService
{
    public function __construct(private readonly SsrfSafeHttpClient $http) {}

    /**
     * @return array{0: ?IntegrationLog, 1: ?string} [log, errorCode]
     */
    public function dispatchForWorkflow(WorkflowInstance $instance, WorkflowStep $step): array
    {
        $integration = Integration::query()->where('integration_code', $step->config['integration_code'] ?? null)->first();
        if (! $integration || ! $integration->isActive()) {
            return [null, 'INTEGRATION_NOT_ACTIVE'];
        }

        $endpoint = $integration->endpoints()->where('endpoint_key', $step->config['endpoint_key'] ?? null)->first();
        if (! $endpoint) {
            return [null, 'INTEGRATION_NOT_ACTIVE'];
        }

        $instanceStep = $instance->steps()->where('workflow_step_id', $step->id)->latest('created_at')->first();

        $log = $integration->logs()->create([
            'workflow_instance_step_id' => $instanceStep?->id,
            'correlation_id' => $instance->correlation_id,
            'direction' => IntegrationLog::DIRECTION_OUTBOUND,
            'request_summary' => ['endpoint_key' => $endpoint->endpoint_key, 'method' => $endpoint->method, 'path' => $endpoint->path],
            'status' => IntegrationLog::STATUS_PENDING,
        ]);

        DeliverIntegrationRequest::dispatch($integration->id, $endpoint->id, $log->id, $instanceStep?->id, $step->config['body'] ?? []);

        return [$log, null];
    }

    /**
     * Synchronous connectivity check for POST /integrations/{integration}/test -
     * the admin is actively waiting on the result, so this does not queue.
     */
    public function test(Integration $integration): array
    {
        if (! $integration->base_url) {
            return ['ok' => false, 'error' => 'No base_url configured.'];
        }

        $start = microtime(true);

        try {
            $response = $this->http->send('GET', $integration->base_url, [
                'timeout' => min($integration->timeout_seconds, 10),
                'headers' => $this->authHeaders($integration->activeCredential()),
            ]);
            $durationMs = (int) ((microtime(true) - $start) * 1000);

            $log = $integration->logs()->create([
                'direction' => IntegrationLog::DIRECTION_OUTBOUND,
                'request_summary' => ['method' => 'GET', 'url' => $integration->base_url],
                'response_status' => $response->status(),
                'duration_ms' => $durationMs,
                'status' => $response->successful() ? IntegrationLog::STATUS_SUCCESS : IntegrationLog::STATUS_FAILED,
            ]);

            return ['ok' => $response->successful(), 'status' => $response->status(), 'duration_ms' => $durationMs, 'log_id' => $log->id];
        } catch (SsrfProtectedException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            $integration->logs()->create([
                'direction' => IntegrationLog::DIRECTION_OUTBOUND,
                'request_summary' => ['method' => 'GET', 'url' => $integration->base_url],
                'status' => IntegrationLog::STATUS_FAILED,
                'error_code' => 'INTEGRATION_TIMEOUT',
                'error_message' => Str::limit($e->getMessage(), 500),
            ]);

            return ['ok' => false, 'error' => 'The connection attempt failed or timed out.'];
        }
    }

    public function authHeaders(?IntegrationCredential $credential): array
    {
        if (! $credential) {
            return [];
        }

        $secret = $credential->encrypted_secret; // decrypted transparently by the 'encrypted' cast

        return match ($credential->credential_type) {
            IntegrationCredential::TYPE_BEARER => ['Authorization' => "Bearer {$secret}"],
            IntegrationCredential::TYPE_API_KEY => ['X-API-Key' => $secret],
            IntegrationCredential::TYPE_BASIC => ['Authorization' => 'Basic '.base64_encode($secret)],
            default => [],
        };
    }
}
