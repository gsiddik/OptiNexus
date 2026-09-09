<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\EventDelivery;
use App\Models\NotificationRule;
use App\Models\Policy;
use App\Models\Workflow;
use App\Services\Notification\NotificationDispatchService;
use App\Services\Policy\PolicyConditionEvaluator;
use App\Services\Policy\PolicyEvaluationService;
use App\Services\Workflow\WorkflowExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Fans a single ingested Event out to one matching consumer (a Workflow
 * awaiting this trigger_event_key, or a NotificationRule awaiting it).
 * One EventDelivery row per consumer keeps each fan-out independently
 * retryable/discardable - a failing notification rule never blocks the
 * workflow it shares the event with, and vice versa.
 */
class DeliverEventToConsumer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(public string $deliveryId) {}

    public function handle(WorkflowExecutionService $execution, NotificationDispatchService $notificationDispatch, PolicyConditionEvaluator $evaluator, PolicyEvaluationService $policyEvaluation): void
    {
        $delivery = EventDelivery::find($this->deliveryId);
        if (! $delivery || $delivery->status !== EventDelivery::STATUS_PENDING) {
            return;
        }

        $event = $delivery->event;
        if (! $event) {
            $delivery->update(['status' => EventDelivery::STATUS_FAILED, 'last_error_code' => 'EVENT_INVALID', 'last_error_message' => 'The source event no longer exists.']);

            return;
        }

        try {
            $skipped = match ($delivery->consumer_type) {
                EventDelivery::CONSUMER_WORKFLOW => $this->deliverToWorkflow($delivery, $event, $execution, $policyEvaluation),
                EventDelivery::CONSUMER_NOTIFICATION => $this->deliverToNotification($delivery, $event, $notificationDispatch, $evaluator),
                default => throw new \RuntimeException("Unsupported consumer_type [{$delivery->consumer_type}]."),
            };

            $delivery->update($skipped
                ? ['status' => EventDelivery::STATUS_DISCARDED, 'last_error_message' => $skipped]
                : ['status' => EventDelivery::STATUS_DELIVERED, 'delivered_at' => now(), 'attempt_count' => $delivery->attempt_count + 1]);
        } catch (\Throwable $e) {
            $attempt = $delivery->attempt_count + 1;
            $message = Str::limit(preg_replace('/[A-Za-z0-9+\/=]{32,}/', '[redacted]', $e->getMessage()), 500);

            if ($attempt >= $this->tries) {
                $delivery->update(['status' => EventDelivery::STATUS_FAILED, 'attempt_count' => $attempt, 'last_error_code' => 'EVENT_DELIVERY_FAILED', 'last_error_message' => $message]);

                return; // never discard silently - stays FAILED for manual retry/discard
            }

            $delivery->update(['attempt_count' => $attempt, 'next_retry_at' => now()->addSeconds($this->backoff[$attempt - 1] ?? 300), 'last_error_message' => $message]);

            throw $e; // let the queue's own backoff/tries retry a transient failure
        }
    }

    /**
     * Per the orchestration model (Event -> Policy Evaluation -> Workflow
     * Resolution), a WORKFLOW_ROUTING policy scoped to this event's
     * tenant/application is consulted before the workflow is triggered -
     * an explicit DENY skips the trigger instead of failing the delivery.
     *
     * @return ?string a skip reason if the trigger was withheld, else null
     */
    private function deliverToWorkflow(EventDelivery $delivery, Event $event, WorkflowExecutionService $execution, PolicyEvaluationService $policyEvaluation): ?string
    {
        $workflow = Workflow::find($delivery->consumer_reference);
        if (! $workflow) {
            throw new \RuntimeException('The target workflow no longer exists.');
        }

        $policyResult = $policyEvaluation->evaluate([
            'policy_type' => Policy::TYPE_WORKFLOW_ROUTING,
            'tenant_id' => $workflow->tenant_id ?? $event->tenant_id,
            'application_id' => $workflow->application_id ?? $event->source_application_id,
            'action' => $workflow->workflow_code,
            'resource' => $event->data,
            'context' => ['event' => ['key' => $event->event_key]],
        ]);

        if ($policyResult['decision'] === 'DENY') {
            return "Workflow trigger skipped: {$policyResult['reason_code']}.";
        }

        // event_id doubles as the idempotency key so a replayed/re-delivered
        // event can never spawn a second instance of the same workflow.
        [, $error] = $execution->trigger($workflow, Workflow::TRIGGER_EVENT, $event->data, $event->correlation_id, $event->id, $event->id, null, $event);

        if ($error) {
            throw new \RuntimeException("Workflow trigger failed: {$error}");
        }

        return null;
    }

    private function deliverToNotification(EventDelivery $delivery, Event $event, NotificationDispatchService $dispatch, PolicyConditionEvaluator $evaluator): ?string
    {
        $rule = NotificationRule::find($delivery->consumer_reference);
        if (! $rule) {
            throw new \RuntimeException('The target notification rule no longer exists.');
        }

        $context = [
            'tenant' => ['id' => $event->tenant_id],
            'application' => ['id' => $event->source_application_id],
            'event' => ['key' => $event->event_key, 'id' => $event->id],
            'data' => $event->data,
        ];

        if ($rule->condition && ! $evaluator->evaluate($rule->condition, $context)) {
            return 'Notification skipped: rule condition not met.';
        }

        $dispatch->dispatchForRule($rule, $context, $event->correlation_id, $event->id);

        return null;
    }
}
