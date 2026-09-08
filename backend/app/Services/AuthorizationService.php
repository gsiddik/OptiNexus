<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
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
     *         keyed by permission_key
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

            foreach ($role->permissions as $permission) {
                if ($permission->status !== \App\Models\Permission::STATUS_ACTIVE) {
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

    public function userHasPermission(User $user, string $permissionKey, ?string $tenantId = null, ?string $applicationId = null): bool
    {
        return $this->effectivePermissions($user, $tenantId, $applicationId)->has($permissionKey);
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
        if (! $user->isActive()) {
            return ['allowed' => false, 'scope' => null, 'reason' => 'USER_NOT_ACTIVE'];
        }

        if ($tenant && ! $this->tenantIsUsable($tenant)) {
            return ['allowed' => false, 'scope' => null, 'reason' => 'TENANT_NOT_ACTIVE'];
        }

        if (! $this->applicationIsUsable($application)) {
            return ['allowed' => false, 'scope' => null, 'reason' => 'APPLICATION_NOT_ACTIVE'];
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

    public function tenantIsUsable(Tenant $tenant): bool
    {
        if ($tenant->status !== Tenant::STATUS_ACTIVE) {
            return false;
        }

        $customer = $tenant->customer;

        return $customer && $customer->status === \App\Models\Customer::STATUS_ACTIVE;
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
            ->where('status', \App\Models\TenantMembership::STATUS_ACTIVE)
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
                ->where('status', \App\Models\UserApplicationAccess::STATUS_ACTIVE)
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
