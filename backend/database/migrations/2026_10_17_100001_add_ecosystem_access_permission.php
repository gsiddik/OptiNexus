<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ecosystem access gate (audit R07/A24). Existing installations already have platform operators holding global (SYSTEM) roles:
 * the new permission is created and attached to every global SYSTEM role, so those operators keep their access and nobody else
 * gains it. A fresh database gets the same rows from PermissionSeeder and the role seeders; this migration is then a no-op
 * because the governance application does not exist yet. Additive and idempotent.
 */
return new class extends Migration
{
    private const KEY = 'cgo.ecosystem.access';

    public function up(): void
    {
        $cgoId = DB::table('applications')->where('application_code', 'cgo')->value('id');

        if (! $cgoId) {
            return;
        }

        $permissionId = DB::table('permissions')->where('permission_key', self::KEY)->value('id');

        if (! $permissionId) {
            $permissionId = (string) Str::uuid();
            DB::table('permissions')->insert([
                'id' => $permissionId,
                'application_id' => $cgoId,
                'permission_key' => self::KEY,
                'name' => 'Ecosystem access',
                'description' => 'Enter the ecosystem portal and call the ecosystem admin API. Platform operators only.',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $roleIds = DB::table('roles')->where('role_type', 'SYSTEM')->whereNull('tenant_id')->pluck('id');

        foreach ($roleIds as $roleId) {
            $exists = DB::table('role_permission')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists();

            if (! $exists) {
                DB::table('role_permission')->insert([
                    'id' => (string) Str::uuid(),
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Additive only: removing the gate permission would lock every operator out of the portal.
    }
};
