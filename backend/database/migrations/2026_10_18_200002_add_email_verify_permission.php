<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Manual e-mail verification (owner decision 2026-10-18, second batch). A dedicated permission, deliberately NOT spread over every
 * global role: existing installations get it on the Platform Super Administrator role only, the way PermissionSeeder +
 * SystemRoleSeeder give it to a fresh database (Platform Administrator is excluded there). Any other operator role receives it
 * only when someone with the right to grant it adds it, through the normal role administration and its escalation guard.
 * Additive and idempotent; a fresh database has no governance application yet, so this is then a no-op.
 */
return new class extends Migration
{
    private const KEY = 'cgo.user.email.verify';

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
                'name' => 'User email verify',
                'description' => 'Record that an operator verified a user e-mail address outside the system (manual verification).',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $roleId = DB::table('roles')->where('code', 'PLATFORM_SUPERADMIN')->whereNull('tenant_id')->where('role_type', 'SYSTEM')->value('id');

        if ($roleId && ! DB::table('role_permission')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
            DB::table('role_permission')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Additive only: removing the permission would orphan the audit trail of verifications already recorded.
    }
};
