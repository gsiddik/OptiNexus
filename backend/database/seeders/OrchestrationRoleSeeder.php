<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class OrchestrationRoleSeeder extends Seeder
{
    /**
     * @var array<string, array{name: string, description: string, permissions: array<int, string>}>
     */
    public const ROLES = [
        'POLICY_ADMIN' => [
            'name' => 'Policy Administrator',
            'description' => 'Authors, activates, and simulates contextual authorization/routing policies.',
            'permissions' => [
                'cgo.policy.view', 'cgo.policy.create', 'cgo.policy.update', 'cgo.policy.activate',
                'cgo.policy.deactivate', 'cgo.policy.deprecate', 'cgo.policy.simulate',
            ],
        ],
        'WORKFLOW_ADMIN' => [
            'name' => 'Workflow Administrator',
            'description' => 'Designs, activates, and operates workflow definitions and their running instances.',
            'permissions' => [
                'cgo.workflow.view', 'cgo.workflow.create', 'cgo.workflow.update', 'cgo.workflow.activate',
                'cgo.workflow.execute', 'cgo.workflow.retry', 'cgo.workflow.cancel',
            ],
        ],
        'APPROVAL_ADMIN' => [
            'name' => 'Approval Administrator',
            'description' => 'Manages approval definitions and can act as a business approver on pending requests.',
            'permissions' => [
                'cgo.approval.definition.view', 'cgo.approval.definition.manage', 'cgo.approval.request.view',
                'cgo.approval.approve', 'cgo.approval.reject', 'cgo.approval.return', 'cgo.approval.delegate',
            ],
        ],
        'INTEGRATION_ADMIN' => [
            'name' => 'Integration Administrator',
            'description' => 'Registers integrations and endpoints and manages credential lifecycle (rotate/revoke).',
            'permissions' => [
                'cgo.integration.view', 'cgo.integration.create', 'cgo.integration.update', 'cgo.integration.activate',
                'cgo.integration.test', 'cgo.integration.credential.rotate',
            ],
        ],
        'EVENT_ADMIN' => [
            'name' => 'Event Administrator',
            'description' => 'Manages the event catalog and operates failed/dead-lettered event deliveries.',
            'permissions' => [
                'cgo.event.catalog.view', 'cgo.event.catalog.manage', 'cgo.event.delivery.view',
                'cgo.event.delivery.retry', 'cgo.event.delivery.discard',
            ],
        ],
        'FEATURE_FLAG_ADMIN' => [
            'name' => 'Feature Flag Administrator',
            'description' => 'Manages feature flags, their default values, and scoped overrides.',
            'permissions' => [
                'cgo.featureflag.view', 'cgo.featureflag.create', 'cgo.featureflag.update', 'cgo.featureflag.override',
            ],
        ],
        'NOTIFICATION_ADMIN' => [
            'name' => 'Notification Administrator',
            'description' => 'Manages notification templates and rules, and can trigger direct sends.',
            'permissions' => [
                'cgo.notification.template.manage', 'cgo.notification.rule.manage', 'cgo.notification.send',
                'cgo.notification.delivery.view', 'cgo.notification.delivery.retry',
            ],
        ],
        // Read-only across every Phase 3 domain plus the immutable audit
        // trail - deliberately excludes every .create/.update/.activate/
        // .manage/.send/.approve permission so an auditor can observe
        // orchestration state without being able to change it.
        'ORCHESTRATION_AUDITOR' => [
            'name' => 'Orchestration Auditor',
            'description' => 'Read-only visibility across policies, workflows, approvals, integrations, events, feature flags, and notifications.',
            'permissions' => [
                'cgo.policy.view', 'cgo.workflow.view', 'cgo.approval.definition.view', 'cgo.approval.request.view',
                'cgo.integration.view', 'cgo.event.catalog.view', 'cgo.event.delivery.view',
                'cgo.featureflag.view', 'cgo.notification.delivery.view', 'cgo.audit.view',
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::ROLES as $code => $definition) {
            $role = Role::query()->updateOrCreate(
                ['code' => $code, 'tenant_id' => null],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'role_type' => Role::TYPE_SYSTEM,
                    'is_system' => true,
                    'status' => Role::STATUS_ACTIVE,
                ],
            );

            $permissionIds = Permission::query()->whereIn('permission_key', $definition['permissions'])->pluck('id');

            $sync = [];
            foreach ($permissionIds as $id) {
                $sync[$id] = ['id' => (string) Str::uuid()];
            }
            $role->permissions()->sync($sync);
        }
    }
}
