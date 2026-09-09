<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\IntegrationEndpoint;
use App\Models\IntegrationLog;
use App\Models\WorkflowInstanceStep;
use App\Services\Integration\IntegrationDeliveryService;
use App\Services\Integration\SsrfProtectedException;
use App\Services\Integration\SsrfSafeHttpClient;
use App\Services\Workflow\WorkflowExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Performs the actual outbound HTTP call for an INTEGRATION workflow step
 * (or a queued retry). Never serializes plaintext secrets into the job
 * payload - only IDs, resolved to the encrypted credential at run time.
 *
 * Implements ShouldQueueAfterCommit so a worker can never pick this job
 * up before the dispatching transaction (which also marks the workflow
 * instance WAITING) has committed - see WorkflowExecutionService::
 * executeIntegrationStep().
 */
class DeliverIntegrationRequest implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public string $integrationId,
        public string $endpointId,
        public string $logId,
        public ?string $instanceStepId,
        public array $body = [],
    ) {}

    public function handle(IntegrationDeliveryService $delivery, SsrfSafeHttpClient $http, WorkflowExecutionService $execution): void
    {
        $log = IntegrationLog::find($this->logId);
        $integration = Integration::find($this->integrationId);
        $endpoint = IntegrationEndpoint::find($this->endpointId);

        if (! $log || ! $integration || ! $endpoint) {
            return;
        }

        $url = rtrim((string) $integration->base_url, '/').'/'.ltrim($endpoint->path, '/');
        $start = microtime(true);

        try {
            $response = $http->send($endpoint->method, $url, [
                'timeout' => $integration->timeout_seconds,
                'headers' => $delivery->authHeaders($integration->activeCredential()),
                'json' => $this->body,
            ]);

            $durationMs = (int) ((microtime(true) - $start) * 1000);
            $success = $response->successful();

            $log->update([
                'response_status' => $response->status(),
                'response_summary' => ['body' => Str::limit($response->body(), 2000)],
                'duration_ms' => $durationMs,
                'status' => $success ? IntegrationLog::STATUS_SUCCESS : IntegrationLog::STATUS_FAILED,
                'error_code' => $success ? null : 'INTEGRATION_DELIVERY_FAILED',
                'attempt_count' => $this->attempts(),
            ]);

            $this->resume($execution, $success, $success ? null : "HTTP {$response->status()}");
        } catch (SsrfProtectedException $e) {
            $log->update(['status' => IntegrationLog::STATUS_FAILED, 'error_code' => 'INTEGRATION_DELIVERY_FAILED', 'error_message' => $e->getMessage(), 'attempt_count' => $this->attempts()]);
            $this->resume($execution, false, $e->getMessage());
            $this->fail($e);
        } catch (\Throwable $e) {
            $log->update([
                'status' => IntegrationLog::STATUS_TIMEOUT,
                'error_code' => 'INTEGRATION_TIMEOUT',
                'error_message' => Str::limit($e->getMessage(), 500),
                'attempt_count' => $this->attempts(),
                'next_retry_at' => $this->attempts() < $this->tries ? now()->addSeconds($this->backoff[$this->attempts() - 1] ?? 300) : null,
            ]);

            if ($this->attempts() >= $this->tries) {
                $this->resume($execution, false, 'Integration delivery timed out after retries.');
            }

            throw $e; // let the queue's backoff/tries retry a transient failure
        }
    }

    private function resume(WorkflowExecutionService $execution, bool $success, ?string $error): void
    {
        if (! $this->instanceStepId) {
            return;
        }

        $instanceStep = WorkflowInstanceStep::find($this->instanceStepId);
        if ($instanceStep) {
            $execution->resumeFromIntegration($instanceStep, $success, $error);
        }
    }
}
