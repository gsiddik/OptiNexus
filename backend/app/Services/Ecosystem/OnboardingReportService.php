<?php

namespace App\Services\Ecosystem;

use App\Models\Application;
use App\Models\OidcClient;
use App\Models\Permission;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Services\AuthorizationService;

/**
 * What is still missing before a tenant can really use its products (audit A14): a read-only report of actionable gaps per
 * tenant and application. It states only what Nexus itself can see (records), never a guess about the product's own side:
 * nothing here pretends a callback or connection works, because Nexus does not call the product to find out.
 */
class OnboardingReportService
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    /** @return array<string, mixed> */
    public function report(Tenant $tenant): array
    {
        $checks = [];

        $checks[] = $this->check('tenant.usable', $this->authorization->tenantIsUsable($tenant), 'tenant_not_active',
            ['label_key' => 'onboarding.action.open_tenant', 'route' => "/tenants/{$tenant->id}"]);

        $admins = TenantMembership::query()->where('tenant_id', $tenant->id)->where('is_tenant_admin', true)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereIn('user_id', User::query()->where('status', User::STATUS_ACTIVE)->select('id'))->count();
        $checks[] = $this->check('tenant.admin', $admins > 0, 'no_tenant_admin',
            ['label_key' => 'onboarding.action.assign_admin', 'route' => "/tenants/{$tenant->id}/admins"], detail: ['count' => $admins]);

        $applications = $tenant->applications()->wherePivot('status', 'ACTIVE')->where('applications.application_code', '!=', 'cgo')->orderBy('name')->get();
        $checks[] = $this->check('tenant.applications', $applications->isNotEmpty(), 'no_application_assigned',
            ['label_key' => 'onboarding.action.assign_application', 'route' => "/tenants/{$tenant->id}/applications"]);

        $rows = [];
        foreach ($applications as $application) {
            $row = $this->applicationRow($tenant, $application);
            $rows[] = $row['view'];
            array_push($checks, ...$row['checks']);
        }

        return [
            'tenant' => ['id' => $tenant->id, 'code' => $tenant->tenant_code, 'name' => $tenant->name, 'status' => $tenant->status],
            'ready' => collect($checks)->every(fn (array $c): bool => $c['status'] !== 'MISSING'),
            'checks' => $checks,
            'applications' => $rows,
        ];
    }

    /** @return array{view: array<string, mixed>, checks: array<int, array<string, mixed>>} */
    private function applicationRow(Tenant $tenant, Application $application): array
    {
        $code = $application->application_code;
        $client = OidcClient::query()->where('application_id', $application->id)->where('status', OidcClient::STATUS_ACTIVE)->first();
        $account = ServiceAccount::query()->where('application_id', $application->id)->where('status', ServiceAccount::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenant->id))->orderByDesc('last_used_at')->first();
        $manifest = $application->metadata['manifest'] ?? null;

        $access = UserApplicationAccess::query()->where('tenant_id', $tenant->id)->where('application_id', $application->id)
            ->where('status', UserApplicationAccess::STATUS_ACTIVE)->pluck('user_id');
        $adminsWithAccess = TenantMembership::query()->where('tenant_id', $tenant->id)->where('is_tenant_admin', true)
            ->where('status', TenantMembership::STATUS_ACTIVE)->whereIn('user_id', $access)->count();
        $delegable = Permission::query()->where('application_id', $application->id)->where('delegable', true)
            ->where('status', Permission::STATUS_ACTIVE)->count();

        $oidcAction = ['label_key' => 'onboarding.action.open_oidc_clients', 'route' => '/sso-clients'];
        $accountAction = ['label_key' => 'onboarding.action.open_service_accounts', 'route' => '/service-accounts'];
        $accessAction = ['label_key' => 'onboarding.action.assign_access', 'route' => "/tenants/{$tenant->id}/applications"];
        $manifestAction = ['label_key' => 'onboarding.action.open_application', 'route' => "/applications/{$application->id}"];

        $checks = [
            $this->check("{$code}.oidc_client", $client !== null, 'oidc_client_missing', $oidcAction, $code),
            $this->check("{$code}.oidc_pkce", $client === null || $client->require_pkce, 'oidc_pkce_not_required', $oidcAction, $code, warn: true),
            $this->check("{$code}.oidc_launch_url", $client === null || filled($client->launch_url ?? $application->frontend_url), 'launch_url_missing', $oidcAction, $code, warn: true),
            $this->check("{$code}.service_account", $account !== null, 'service_account_missing', $accountAction, $code),
            $this->check("{$code}.manifest", $manifest !== null, 'manifest_not_imported', $manifestAction, $code, warn: true),
            $this->check("{$code}.delegable_permissions", $delegable > 0, 'nothing_delegable', $manifestAction, $code, warn: true),
            $this->check("{$code}.users", $access->isNotEmpty(), 'no_user_has_access', $accessAction, $code, detail: ['count' => $access->count()]),
            $this->check("{$code}.tenant_admin_access", $adminsWithAccess > 0, 'no_tenant_admin_has_access', $accessAction, $code, detail: ['count' => $adminsWithAccess]),
        ];

        return [
            'view' => [
                'code' => $code,
                'name' => $application->name,
                'subscribed' => true,
                'oidc_client' => $client ? ['status' => $client->status, 'pkce_required' => (bool) $client->require_pkce, 'redirect_uris' => count($client->redirect_uris ?? [])] : null,
                'service_account' => ['present' => $account !== null, 'last_used_at' => $account?->last_used_at?->toIso8601String()],
                'manifest' => ['revision' => $manifest['revision'] ?? null, 'synced_at' => $manifest['synced_at'] ?? null],
                'users_with_access' => $access->count(),
                'tenant_admins' => $adminsWithAccess,
            ],
            'checks' => $checks,
        ];
    }

    /**
     * @param  array<string, string>  $action
     * @param  array<string, mixed>|null  $detail
     * @return array<string, mixed>
     */
    private function check(string $key, bool $ok, string $messageCode, array $action, ?string $application = null, bool $warn = false, ?array $detail = null): array
    {
        return [
            'key' => $key,
            'application' => $application,
            'status' => $ok ? 'OK' : ($warn ? 'WARN' : 'MISSING'),
            'message_code' => $ok ? 'ok' : $messageCode,
            'detail' => $detail,
            'action' => $ok ? null : $action,
        ];
    }
}
