<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('CGO_SUPERADMIN_EMAIL', 'superadmin@cgo.local');
        $password = env('CGO_SUPERADMIN_PASSWORD');

        if (! $password) {
            if (! app()->environment(['local', 'testing'])) {
                $this->command?->warn('CGO_SUPERADMIN_PASSWORD is not set; skipping bootstrap superadmin creation.');

                return;
            }

            // Local/testing-only fallback so `php artisan migrate:fresh --seed`
            // works out of the box. Never used outside local/testing.
            $password = 'ChangeMe!12345';
        }

        // The password and name are set when the bootstrap administrator is created. Running the seeder again (every deployment does) must
        // not overwrite a password the administrator has changed since, unless CGO_SUPERADMIN_RESET_PASSWORD=true says so.
        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            $user = User::query()->create([
                'email' => $email,
                'name' => 'Platform Super Admin',
                'password' => $password,
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
            ]);
        } elseif (filter_var(env('CGO_SUPERADMIN_RESET_PASSWORD', false), FILTER_VALIDATE_BOOLEAN)) {
            $user->forceFill(['password' => $password, 'status' => User::STATUS_ACTIVE])->save();
        }

        $role = Role::query()->where('code', 'PLATFORM_SUPERADMIN')->whereNull('tenant_id')->first();

        if ($role && ! $user->userRoles()->where('role_id', $role->id)->whereNull('tenant_id')->exists()) {
            $user->userRoles()->create(['role_id' => $role->id, 'tenant_id' => null]);
        }

        $this->command?->info("Seeded bootstrap super admin: {$email}");
    }
}
