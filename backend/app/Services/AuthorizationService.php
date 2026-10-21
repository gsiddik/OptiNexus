<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Capability;
use App\Models\Customer;
use App\Models\Entitlement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserRole;
use App\Services\Commercial\EntitlementService;
use Illuminate\Support\Collection;

/**
 * Resolves effective RBAC permissions for a user, scoped to an optional
 * tenant and application. This is the single source of truth used by both
 * the internal admin-permission middleware and the public
 * POST /api/v1/authorization/check integration endpoint, so tenant
 * isolation rules only need to be correct in one place.
 *
 * Scope resolution:
 *  - GLOBAL      role assignment has no tenant_id (role_type SYSTEM)
 *  - TENANT      role assignment carries a tenant_id (role_type TENANT)
 *  - APPLICATION role assignment carries a tenant_id and the role is
 *                bound to a specific application (role_type APPLICATION)
 *
 * A role assignment only ever contributes permissions when its tenant_id
 * is null (global) or matches the tenant being evaluated - this is what
 * prevents a tenant-scoped role granted in Tenant A from ever being
 * visible while evaluating Tenant B.
 */
class AuthorizationService
{
    /**
     * @return Collection<string, array{scope: string, role_id: string}>
     *                                                                   keyed by permission_key
     */
    public function effectivePermissions(User $user, ?string $tenantId = null, ?string $applicationId = null): Collection
    {
        if (! $user->isActive()) {
            return collect();
        }

        // Note: a non-active tenant does NOT zero out permissions here - a
        // platform admin must still be able to provision/reactivate/manage
        // a DRAFT or SUSPENDED tenant. Tenant "usability" is instead
        // enforced per role grant in roleGrantIsUsable(), which only
        // requires an active tenant for TENANT/APPLICATION-scoped roles,
        // never for GLOBAL (SYSTEM) ones.
        $tenant = $tenantId ? Tenant::query()->find($tenantId) : null;

        $application = $applicationId ? Application::query()->find($applicationId) : null;

        $assignments = UserRole::query()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($tenantId) {
                $query->whereNull('tenant_id');
                if ($tenantId) {
                    $query->orWhere('tenant_id', $tenantId);
                }
            })
            ->with(['role.permissions'])
            ->get();

        $permissions = collect();

        foreach ($assignments as $assignment) {
            $role = $assignment->role;

            if (! $role || ! $role->isActive()) {
                continue;
            }

            if (! $this->roleGrantIsUsable($user, $role, $assignment->tenant_id, $tenant, $application)) {
                continue;
            }

            $scope = $this->scopeForRole($role);

            $rolePermissions = ($role->auto_capability_permissions) && $assignment->tenant_id
                ? $this->capabilityPermissions($role, $assignment->tenant_id) : $role->permissions;
            foreach ($rolePermissions as $permission) {
                if ($permission->status !== Permission::STATUS_ACTIVE) {
                    continue;
                }

                if ($applicationId && $permission->application_id !== $applicationId) {
                    continue;
                }

                // Keep the broadest scope already recorded for a given key.
                if (! $permissions->has($permission->permission_key)) {
                    $permissions->put($permission->permission_key, [
                        'scope' => $scope,
                        'role_id' => $role->id,
                    ]);
                }
            }
        }

        return $permissions;
    }

    /** Permissions of purchased capabilities and the core of their enabled application. */
    public function capabilityPermissions(Role $role, string $tenantId): Collection
    {
        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant || ! $this->tenantIsUsable($tenant)) {
            return collect();
        }

        $entitlements = app(EntitlementService::class);
        $applicationIds = $entitlements->effectiveRows($tenant)->pluck('application_id')->filter()->unique();
        $applications = Application::query()->whereIn('id', $applicationIds)
            ->where('application_code', '!=', 'cgo')
            ->when($role->application_id, fn ($query) => $query->where('id', $role->application_id))->get();
        $permissions = collect();

        foreach ($applications as $application) {
            if (! $this->applicationIsUsable($application)) {
                continue;
            }
            $effective = $entitlements->effectiveEntitlements($tenant, $application->application_code);
            $applicationGrant = $effective->first(fn (array $row): bool => $row['entitlement_type'] === Entitlement::TYPE_APPLICATION
                && $row['entitlement_key'] === $application->application_code);
            $capabilityGrants = $effective->where('entitlement_type', Entitlement::TYPE_CAPABILITY)->keyBy('entitlement_key');
            $enabled = $capabilityGrants->whereStrict('value', true)->keys()->all();
            $denied = $capabilityGrants->whereStrict('value', false)->keys()->all();
            if (($applicationGrant && $applicationGrant['value'] !== true) || (! $applicationGrant && $enabled === [])) {
                continue;
            }

            $capabilities = Capability::query()->where('application_id', $application->id)->get()->keyBy('id');
            $grantedIds = $capabilities->filter(function (Capability $capability) use ($capabilities, $enabled, $denied): bool {
                $granted = false;
                $visited = [];
                do {
                    if (isset($visited[$capability->id]) || $capability->status !== Capability::STATUS_ACTIVE || in_array($capability->code, $denied, true)) {
                        return false;
                    }
                    $visited[$capability->id] = true;
                    $granted = $granted || in_array($capability->code, $enabled, true);
                    if ($capability->parent_id === null) {
                        return $granted;
                    }
                    $capability = $capabilities->get($capability->parent_id);
                } while ($capability);

                return false;
            })->keys();

            $permissions = $permissions->merge(Permission::query()
                ->with(['application', 'capability.parent.parent.parent.parent.parent'])
                ->where('application_id', $application->id)->where('status', Permission::STATUS_ACTIVE)
                ->where(fn ($query) => $query->whereNull('capability_id')->orWhereIn('capability_id', $grantedIds))->get());
        }

        return $permissions;
    }

    public function userHasPermission(User $user, string $permissionKey, ?string $tenantId = null, ?string $applicationId = null): bool
    {
        return $this->effectivePermissions($user, $tenantId, $applicationId)->has($permissionKey);
    }

    /**
     * Whether the user may enter the ecosystem portal and call the ecosystem admin API (audit R07/A24). Resolved without a
     * tenant, so only a global (SYSTEM) role assignment can satisfy it: tenant users and tenant administrators are refused
     * however their tenant-scoped roles are composed, and a tenant-scoped role that is given the permission anyway stays inert.
     */
    public function hasEcosystemAccess(User $user): bool
    {
        return $this->userHasPermission($user, Permission::ECOSYSTEM_ACCESS);
    }

    /**
     * Full evaluation used by the external Authorization Check API.
     *
     * @return array{allowed: bool, scope: ?string, reason: ?string}
     */
    public function check(
        User $user,
        ?Tenant $tenant,
        Application $application,
        string $permissionKey,
    ): array {
        if ($reason = $this->gateDenial($user, $tenant, $application)) {
            return ['allowed' => false, 'scope' => null, 'reason' => $reason];
        }

        $permissions = $this->effectivePermissions($user, $tenant?->id, $application->id);

        if (! $permissions->has($permissionKey)) {
            return ['allowed' => false, 'scope' => null, 'reason' => 'PERMISSION_NOT_GRANTED'];
        }

        return [
            'allowed' => true,
            'scope' => $permissions->get($permissionKey)['scope'],
            'reason' => null,
        ];
    }

    /**
     * Bulk counterpart of check() for POST /authorization/effective-permissions: every permission one user holds in one
     * tenant for one application. It runs the same gates and the same effectivePermissions() resolver as check(), so a key is
     * in the answer exactly when check() would allow it, with the scope check() would report for that key. Only granted keys
     * are returned - the application's catalog is never listed.
     *
     * @return array{allowed: bool, reason: ?string, permissions: array<string, string>} permission_key => scope
     */
    public function effectiveAccess(User $user, ?Tenant $tenant, Application $application): array
    {
        if ($reason = $this->gateDenial($user, $tenant, $application)) {
            return ['allowed' => false, 'reason' => $reason, 'permissions' => []];
        }

        $granted = $this->effectivePermissions($user, $tenant?->id, $application->id)
            ->map(fn (array $grant): string => $grant['scope'])
            ->sortKeys()
            ->all();

        return ['allowed' => $granted !== [], 'reason' => $granted === [] ? 'PERMISSION_NOT_GRANTED' : null, 'permissions' => $granted];
    }

    /** The reason the user, tenant or application is not usable at all (before any permission is looked at), or null. */
    private function gateDenial(User $user, ?Tenant $tenant, Application $application): ?string
    {
        if (! $user->isActive()) {
            return 'USER_NOT_ACTIVE';
        }

        if ($tenant && ! $this->tenantIsUsable($tenant)) {
            return 'TENANT_NOT_ACTIVE';
        }

        if (! $this->applicationIsUsable($application)) {
            return 'APPLICATION_NOT_ACTIVE';
        }

        return null;
    }

    public function tenantIsUsable(Tenant $tenant): bool
    {
        if ($tenant->status !== Tenant::STATUS_ACTIVE) {
            return false;
        }

        $customer = $tenant->customer;

        return $customer && $customer->status === Customer::STATUS_ACTIVE;
    }

    public function applicationIsUsable(Application $application): bool
    {
        return in_array($application->status, [Application::STATUS_PUBLISHED, Application::STATUS_DEPRECATED], true);
    }

    private function scopeForRole(Role $role): string
    {
        return match ($role->role_type) {
            Role::TYPE_SYSTEM => 'GLOBAL',
            Role::TYPE_APPLICATION => 'APPLICATION',
            Role::TYPE_TENANT => 'TENANT',
            default => 'TENANT',
        };
    }

    /**
     * Validates the membership / application-access preconditions that must
     * hold for a given role assignment to actually contribute permissions.
     */
    private function roleGrantIsUsable(User $user, Role $role, ?string $assignmentTenantId, ?Tenant $tenant, ?Application $application): bool
    {
        if ($role->role_type === Role::TYPE_SYSTEM) {
            // Platform-wide roles are never tenant-bound.
            return true;
        }

        if (! $assignmentTenantId) {
            // Tenant/Application roles must always be granted within a tenant.
            return false;
        }

        if (! $tenant || $tenant->id !== $assignmentTenantId) {
            return false;
        }

        // TENANT/APPLICATION-scoped grants only apply while the tenant
        // itself is active - e.g. a suspended tenant's own admins cannot
        // use their tenant-scoped role to reactivate it. Global (SYSTEM)
        // roles are exempt so platform admins can still manage a tenant
        // through its DRAFT/SUSPENDED states.
        if (! $this->tenantIsUsable($tenant)) {
            return false;
        }

        $membership = $user->tenantMemberships()
            ->where('tenant_id', $tenant->id)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->first();

        if (! $membership) {
            return false;
        }

        if ($role->role_type === Role::TYPE_APPLICATION) {
            if ($role->application_id === null) {
                return false;
            }

            if ($application && $role->application_id !== $application->id) {
                return false;
            }

            $applicationIdToCheck = $application?->id ?? $role->application_id;

            $access = $user->applicationAccess()
                ->where('tenant_id', $tenant->id)
                ->where('application_id', $applicationIdToCheck)
                ->where('status', UserApplicationAccess::STATUS_ACTIVE)
                ->first();

            if (! $access) {
                return false;
            }

            $tenantApplication = $tenant->applications()
                ->wherePivot('status', 'ACTIVE')
                ->where('applications.id', $applicationIdToCheck)
                ->first();

            if (! $tenantApplication) {
                return false;
            }
        }

        return true;
    }
}
