<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

/**
 * Prevents a caller from granting permissions or roles that exceed their
 * own authorized administrative boundary - e.g. a Tenant Admin cannot grant
 * a permission they do not themselves hold, and cannot assign a role whose
 * permission set they do not fully possess.
 */
class PrivilegeEscalationGuard
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function canGrantPermission(User $actor, Permission $permission, ?string $tenantId = null): bool
    {
        return $this->authorization->effectivePermissions($actor, $tenantId)->has($permission->permission_key);
    }

    public function canGrantRole(User $actor, Role $role, ?string $tenantId = null): bool
    {
        $actorPermissions = $this->authorization->effectivePermissions($actor, $tenantId)->keys();

        $rolePermissionKeys = $role->permissions()->pluck('permission_key');

        return $rolePermissionKeys->diff($actorPermissions)->isEmpty();
    }
}
