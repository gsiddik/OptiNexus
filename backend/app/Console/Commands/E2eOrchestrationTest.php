<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\ApprovalDefinition;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventCatalogEntry;
use App\Models\EventDelivery;
use App\Models\FeatureFlag;
use App\Models\Integration;
use App\Models\IntegrationEndpoint;
use App\Models\IntegrationLog;
use App\Models\Notification;
use App\Models\NotificationRule;
use App\Models\NotificationTemplate;
use App\Models\Policy;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowVersion;
use App\Services\Access\AccessEvaluationService;
use App\Services\Approval\ApprovalService;
use App\Services\Event\EventDispatchService;
use App\Services\FeatureFlag\FeatureFlagEvaluationService;
use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Console\Command;

/**
 * End-to-end orchestration scenario mandated by the Phase 3 spec:
 * subscription.expiring Event -> Policy threshold gate -> Workflow ->
 * Approval -> Integration call -> completion Event -> Notification,
 * plus a tenant-scoped Feature Flag check and a full correlation/audit
 * trail verification. All entities are created and torn down within this
 * command - nothing here is meant to persist in the seeded dataset.
 */
class E2eOrchestrationTest extends Command
{
    protected $signature = 'cgo:e2e-orchestration-test';

    public function handle(
        EventDispatchService $events,
        WorkflowDefinitionService $definitions,
        ApprovalService $approvals,
        FeatureFlagEvaluationService $featureFlags,
        AccessEvaluationService $access,
    ): int {
        // Deliberately NOT forced to the sync queue driver: several steps
        // rely on a queued job only resuming after its dispatching
        // transaction commits (see WorkflowExecutionService::
        // executeIntegrationStep()), which sync execution would violate
        // by running inline. Jobs are drained explicitly via drainQueue()
        // at each real async hand-off instead, matching production timing.
        Http::fake(['example.com/*' => Http::response(['status' => 'accepted'], 200)]);

        $tenant = Tenant::where('tenant_code', 'ACME-SG')->first();
        $cgoApp = Application::where('application_code', 'cgo')->first();
        if (! $tenant || ! $cgoApp) {
            $this->error('Seeded ACME-SG tenant / cgo application not found. Run db:seed first.');

            return self::FAILURE;
        }

        $approver = User::create(['name' => 'E2E Commercial Approver', 'email' => 'e2e.approver@acme.example', 'password' => 'ChangeMe!12345', 'status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);

        $producer = ServiceAccount::create(['application_id' => $cgoApp->id, 'name' => 'E2E Event Producer', 'status' => ServiceAccount::STATUS_ACTIVE]);

        // --- 1. Event Catalog ---
        $expiringEntry = EventCatalogEntry::create(['event_key' => 'e2e.subscription.expiring', 'application_id' => $cgoApp->id, 'name' => 'Subscription Expiring', 'schema_version' => '1', 'payload_schema' => ['required' => ['subscription_id', 'remaining_days'], 'properties' => ['subscription_id' => ['type' => 'string'], 'remaining_days' => ['type' => 'number']]], 'status' => 'ACTIVE']);
        // No required fields: the emitting INTERNAL_ACTION step's
        // config.data is a static payload (not templated from the
        // trigger), so this schema only documents the optional shape.
        $completedEntry = EventCatalogEntry::create(['event_key' => 'e2e.subscription.renewal.completed', 'application_id' => $cgoApp->id, 'name' => 'Subscription Renewal Completed', 'schema_version' => '1', 'payload_schema' => ['properties' => ['reason' => ['type' => 'string']]], 'status' => 'ACTIVE']);

        // --- 2. Policy: block renewal workflow when far from expiry ---
        $policy = Policy::create(['policy_code' => 'E2E_RENEWAL_TOO_EARLY', 'name' => 'Renewal too early', 'policy_type' => Policy::TYPE_WORKFLOW_ROUTING, 'tenant_id' => $tenant->id, 'priority' => 100, 'effect' => Policy::EFFECT_DENY, 'status' => Policy::STATUS_ACTIVE, 'condition_definition' => ['field' => 'resource.remaining_days', 'operator' => 'gt', 'value' => 30]]);

        // --- 3. Approval Definition ---
        $definition = ApprovalDefinition::create(['definition_code' => 'E2E_RENEWAL_APPROVAL', 'name' => 'Subscription Renewal Approval', 'tenant_id' => $tenant->id, 'rule_type' => ApprovalDefinition::RULE_SEQUENTIAL, 'status' => ApprovalDefinition::STATUS_ACTIVE, 'self_approval_allowed' => false]);
        $definition->levels()->create(['level_order' => 1, 'name' => 'Commercial Approval', 'approver_type' => 'USER', 'approver_reference' => $approver->id]);

        // --- 4. Integration (stubbed OptiAccounting) ---
        $integration = Integration::create(['integration_code' => 'E2E_OPTIACCOUNTING', 'name' => 'OptiAccounting (stub)', 'source_application_id' => $cgoApp->id, 'integration_type' => Integration::TYPE_REST, 'status' => Integration::STATUS_ACTIVE, 'base_url' => 'https://example.com', 'timeout_seconds' => 10]);
        $endpoint = IntegrationEndpoint::create(['integration_id' => $integration->id, 'endpoint_key' => 'create_renewal_record', 'method' => 'POST', 'path' => '/webhook']);

        // --- 5. Notification template + rule ---
        $template = NotificationTemplate::create(['template_code' => 'E2E_RENEWAL_NOTICE', 'name' => 'Renewal Notice', 'channel' => NotificationTemplate::CHANNEL_IN_APP, 'body_template' => 'Subscription renewal completed for event {{event.key}}.', 'status' => NotificationTemplate::STATUS_ACTIVE]);
        $rule = NotificationRule::create(['rule_code' => 'E2E_RENEWAL_NOTICE_RULE', 'name' => 'Renewal Notice Rule', 'tenant_id' => $tenant->id, 'trigger_event_key' => 'e2e.subscription.renewal.completed', 'recipient_type' => NotificationRule::RECIPIENT_SPECIFIC_USER, 'recipient_reference' => $approver->id, 'notification_template_id' => $template->id, 'channel' => NotificationTemplate::CHANNEL_IN_APP, 'status' => NotificationRule::STATUS_ACTIVE]);

        // --- 6. Feature flag, tenant-scoped ---
        $flag = FeatureFlag::create(['flag_key' => 'e2e.subscription.renewal.autopilot', 'application_id' => $cgoApp->id, 'name' => 'Renewal Autopilot', 'flag_type' => FeatureFlag::TYPE_BOOLEAN, 'default_value' => false, 'status' => FeatureFlag::STATUS_ACTIVE]);
        $flag->overrides()->create(['scope_type' => FeatureFlag::SCOPE_TENANT, 'scope_id' => $tenant->id, 'value' => true]);

        // --- 7. Workflow: START -> APPROVAL -> CONDITION -> INTEGRATION -> INTERNAL_ACTION -> END ---
        $steps = [
            ['step_key' => 'start', 'step_type' => 'START'],
            ['step_key' => 'approve', 'step_type' => 'APPROVAL', 'config' => ['approval_definition_code' => 'E2E_RENEWAL_APPROVAL']],
            ['step_key' => 'check_decision', 'step_type' => 'CONDITION'],
            ['step_key' => 'call_integration', 'step_type' => 'INTEGRATION', 'config' => ['integration_code' => 'E2E_OPTIACCOUNTING', 'endpoint_key' => 'create_renewal_record']],
            ['step_key' => 'emit_completion', 'step_type' => 'INTERNAL_ACTION', 'config' => ['action' => 'emit_event', 'event_key' => 'e2e.subscription.renewal.completed', 'data' => ['reason' => 'renewal_completed']]],
            ['step_key' => 'end_approved', 'step_type' => 'END'],
            ['step_key' => 'end_rejected', 'step_type' => 'END'],
        ];
        $transitions = [
            ['from_step_key' => 'start', 'to_step_key' => 'approve'],
            ['from_step_key' => 'approve', 'to_step_key' => 'check_decision'],
            ['from_step_key' => 'check_decision', 'to_step_key' => 'call_integration', 'condition' => ['field' => 'steps.approve.approval_decision', 'operator' => 'eq', 'value' => 'APPROVED'], 'sort_order' => 1],
            ['from_step_key' => 'check_decision', 'to_step_key' => 'end_rejected', 'sort_order' => 2],
            ['from_step_key' => 'call_integration', 'to_step_key' => 'emit_completion'],
            ['from_step_key' => 'emit_completion', 'to_step_key' => 'end_approved'],
        ];

        $errors = $definitions->validateStructure($steps, $transitions);
        if ($errors) {
            $this->error('Workflow definition invalid: '.json_encode($errors));
            $this->cleanupAll();

            return self::FAILURE;
        }

        $workflow = Workflow::create(['workflow_code' => 'E2E_SUBSCRIPTION_RENEWAL', 'name' => 'Subscription Renewal', 'tenant_id' => $tenant->id, 'application_id' => $cgoApp->id, 'status' => Workflow::STATUS_DRAFT, 'current_version' => 0, 'trigger_type' => Workflow::TRIGGER_EVENT, 'trigger_event_key' => 'e2e.subscription.expiring']);
        $version = $workflow->versions()->create(['version' => 1, 'status' => WorkflowVersion::STATUS_DRAFT]);
        $definitions->replaceDraftDefinition($version, $steps, $transitions);
        DB::transaction(function () use ($workflow, $version) {
            $version->update(['status' => WorkflowVersion::STATUS_ACTIVE, 'activated_at' => now()]);
            $workflow->update(['status' => Workflow::STATUS_ACTIVE, 'current_version' => 1]);
        });

        $this->info('Setup complete. Running scenario A: remaining_days=90 (policy should DENY the trigger)...');

        // --- Scenario A: too early - policy should block the workflow trigger ---
        [$eventA] = $events->ingest(['event_key' => 'e2e.subscription.expiring', 'tenant_id' => $tenant->id, 'data' => ['subscription_id' => 'E2E-SUB-A', 'remaining_days' => 90]], $producer);
        $this->drainQueue();
        $deliveryA = EventDelivery::where('event_id', $eventA->id)->where('consumer_type', EventDelivery::CONSUMER_WORKFLOW)->first();
        $instancesA = WorkflowInstance::where('workflow_id', $workflow->id)->count();

        $this->checkResult('Scenario A delivery discarded by policy', $deliveryA?->status === EventDelivery::STATUS_DISCARDED);
        $this->checkResult('Scenario A created no workflow instance', $instancesA === 0);

        $this->info('Running scenario B: remaining_days=15 (policy should ALLOW the trigger)...');

        // --- Scenario B: within threshold - full orchestration chain ---
        [$eventB] = $events->ingest(['event_key' => 'e2e.subscription.expiring', 'tenant_id' => $tenant->id, 'data' => ['subscription_id' => 'E2E-SUB-B', 'remaining_days' => 15]], $producer);
        $correlationId = $eventB->correlation_id;
        $this->drainQueue(); // DeliverEventToConsumer(WORKFLOW): policy allows, workflow triggers to WAITING_APPROVAL

        $instance = WorkflowInstance::where('workflow_id', $workflow->id)->where('trigger_event_id', $eventB->id)->first();
        $this->checkResult('Scenario B created a workflow instance', (bool) $instance);
        $this->checkResult('Instance status is WAITING_APPROVAL', $instance?->status === WorkflowInstance::STATUS_WAITING_APPROVAL);

        $approvalRequest = ApprovalRequest::where('workflow_instance_id', $instance->id)->first();
        $this->checkResult('Approval request created and PENDING', $approvalRequest?->status === ApprovalRequest::STATUS_PENDING);

        $decisionError = $approvals->decide($approvalRequest, $approver, 'APPROVE', 'Approved for E2E test.');
        $this->checkResult('Approval decision accepted', $decisionError === null);

        // Drains, in order: DeliverIntegrationRequest (integration call ->
        // resumes workflow -> emits completion event -> queues its own
        // consumer delivery), DeliverEventToConsumer(NOTIFICATION), then
        // SendNotification. Each hop only queues the next once it runs.
        $this->drainQueue(3);

        $instance->refresh();
        $this->checkResult('Instance status is COMPLETED after approval cascade', $instance->status === WorkflowInstance::STATUS_COMPLETED);

        foreach ($instance->steps()->with('step')->orderBy('created_at')->get() as $s) {
            $this->info('  step: '.($s->step?->step_key).' ('.$s->step?->step_type.') status='.$s->status.' output='.json_encode($s->output).' error='.$s->last_error_message);
        }

        $integrationLog = IntegrationLog::where('integration_id', $integration->id)->where('correlation_id', $correlationId)->first();
        $this->checkResult('Integration delivery logged as SUCCESS', $integrationLog?->status === IntegrationLog::STATUS_SUCCESS);

        $completionEvent = Event::where('event_key', 'e2e.subscription.renewal.completed')->where('correlation_id', $correlationId)->first();
        $this->checkResult('Completion event emitted with propagated correlation_id', (bool) $completionEvent);

        $notification = Notification::where('notification_rule_id', $rule->id)->where('correlation_id', $correlationId)->first();
        $this->checkResult('Notification dispatched and SENT', $notification?->status === Notification::STATUS_SENT);

        $flagResult = $featureFlags->evaluate($flag, $tenant->id, null);
        $this->checkResult('Feature flag tenant override evaluates enabled=true', $flagResult['enabled'] === true && $flagResult['source'] === FeatureFlag::SCOPE_TENANT);

        $accessResultBefore = $access->evaluate(['user_id' => $approver->id, 'tenant_id' => $tenant->id, 'application_code' => 'cgo', 'permission' => 'cgo.approval.approve']);
        $this->checkResult('Access evaluate denies before role grant (permission check fails)', $accessResultBefore['allowed'] === false && $accessResultBefore['checks']['permission'] === false);

        $approvalAdminRole = \App\Models\Role::where('code', 'APPROVAL_ADMIN')->first();
        \App\Models\UserRole::create(['user_id' => $approver->id, 'role_id' => $approvalAdminRole->id, 'tenant_id' => null]);

        $accessResultAfter = $access->evaluate(['user_id' => $approver->id, 'tenant_id' => $tenant->id, 'application_code' => 'cgo', 'permission' => 'cgo.approval.approve']);
        $this->checkResult('Access evaluate allows after role grant (entitlement+permission+policy+feature_flag all true)', $accessResultAfter['allowed'] === true);
        $this->info('Access evaluate result: '.json_encode($accessResultAfter));

        \App\Models\UserRole::where('user_id', $approver->id)->where('role_id', $approvalAdminRole->id)->delete();

        $auditCount = AuditLog::where('correlation_id', $correlationId)->count();
        $this->checkResult('Audit trail carries the correlation_id across the chain', $auditCount >= 3);

        $this->info('');
        $this->info('--- Correlation trail (Scenario B) ---');
        $this->info('correlation_id: '.$correlationId);
        $this->info('trigger event_id: '.$eventB->id);
        $this->info('workflow_instance_id: '.$instance->id);
        $this->info('approval_request_id: '.$approvalRequest->id);
        $this->info('integration_log_id: '.$integrationLog?->id);
        $this->info('completion event_id: '.$completionEvent?->id);
        $this->info('notification_id: '.$notification?->id);
        $this->info('audit_log_rows_with_correlation_id: '.$auditCount);

        $this->cleanupAll();

        return self::SUCCESS;
    }

    private function checkResult(string $label, bool $ok): void
    {
        $this->info(($ok ? 'PASS' : 'FAIL').': '.$label);
    }

    /**
     * Runs the real queue worker for exactly $hops jobs, one at a time,
     * against the configured (database) queue connection - the same
     * execution path production uses, just driven synchronously here so
     * the scenario is deterministic within a single command run.
     */
    private function drainQueue(int $hops = 1): void
    {
        for ($i = 0; $i < $hops; $i++) {
            Artisan::call('queue:work', ['--once' => true, '--memory' => 512]);
        }
    }

    /**
     * Explicit, cascade-safe deletion order - several FKs in the Phase 3
     * schema are deliberately RESTRICT (not cascade) since they protect
     * real referential integrity (e.g. an approval_request must outlive
     * cursory definition edits), so children must be removed before their
     * parents here even though this is disposable test data.
     */
    private function cleanupAll(): void
    {
        Notification::whereIn('notification_rule_id', NotificationRule::where('rule_code', 'like', 'E2E_%')->pluck('id'))->get()->each(function ($n) {
            $n->deliveries()->delete();
            $n->delete();
        });

        foreach (WorkflowInstance::whereIn('workflow_id', Workflow::where('workflow_code', 'like', 'E2E_%')->pluck('id'))->get() as $instance) {
            $instance->steps()->delete();
            $instance->delete();
        }

        \App\Models\ApprovalDecision::whereIn('approval_request_id', ApprovalRequest::whereIn('approval_definition_id', ApprovalDefinition::where('definition_code', 'like', 'E2E_%')->pluck('id'))->pluck('id'))->delete();
        \App\Models\ApprovalRequestStep::whereIn('approval_request_id', ApprovalRequest::whereIn('approval_definition_id', ApprovalDefinition::where('definition_code', 'like', 'E2E_%')->pluck('id'))->pluck('id'))->delete();
        ApprovalRequest::whereIn('approval_definition_id', ApprovalDefinition::where('definition_code', 'like', 'E2E_%')->pluck('id'))->delete();

        IntegrationLog::whereIn('integration_id', Integration::where('integration_code', 'like', 'E2E_%')->pluck('id'))->delete();

        $e2eEventIds = Event::where('event_key', 'like', 'e2e.%')->pluck('id');
        EventDelivery::whereIn('event_id', $e2eEventIds)->delete();
        Event::whereIn('id', $e2eEventIds)->delete();

        // Must precede their referenced parents (notification_template_id
        // and trigger_event_key are both plain RESTRICT foreign keys).
        NotificationRule::where('rule_code', 'like', 'E2E_%')->forceDelete();
        NotificationTemplate::where('template_code', 'like', 'E2E_%')->forceDelete();

        // Must precede EventCatalogEntry (workflows.trigger_event_key is
        // also RESTRICT); workflow_versions/steps/transitions cascade.
        Workflow::where('workflow_code', 'like', 'E2E_%')->forceDelete();

        // approval_levels/integration_endpoints/integration_credentials/
        // feature_flag_overrides all cascade from their parent.
        ApprovalDefinition::where('definition_code', 'like', 'E2E_%')->forceDelete();
        Integration::where('integration_code', 'like', 'E2E_%')->forceDelete();
        Policy::where('policy_code', 'like', 'E2E_%')->forceDelete();
        FeatureFlag::where('flag_key', 'like', 'e2e.%')->forceDelete();

        EventCatalogEntry::where('event_key', 'like', 'e2e.%')->delete();
        User::where('email', 'e2e.approver@acme.example')->forceDelete();
        ServiceAccount::where('name', 'E2E Event Producer')->delete();

        $this->info('Cleanup complete - no E2E test data left behind.');
    }
}
