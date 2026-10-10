<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserApplicationAccess;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Registers OptiRadar (GPS telematics, based on Traccar) as an application, the
 * counterpart of the OptiFleet application that DemoDataSeeder registers.
 * Single sign-on and the API Gateway look the application up by the code
 * `optiradar` (config gateway.optiradar.application_code).
 *
 * An application that already exists is left exactly as it is, so a repeated
 * seed never overwrites the real launch URLs an operator has set. When the
 * demo tenant and demo user exist (DemoDataSeeder), the tenant is also
 * assigned OptiRadar and the demo user gets access, so the demo can sign in
 * to OptiRadar through OptiNexus the same way it does to OptiFleet.
 *
 * OptiRadar needs no permission catalog here: it does not read OptiNexus
 * roles, its access is the tenant assignment plus the user's access grant.
 *
 * Run: php artisan db:seed --class=OptiRadarApplicationSeeder
 */
class OptiRadarApplicationSeeder extends Seeder
{
    public const APPLICATION_CODE = 'optiradar';

    public const DEMO_TENANT_CODE = 'ACME-SG';

    public const DEMO_USER_EMAIL = 'tenant.admin@acme.example';

    public function run(): void
    {
        $radar = Application::query()->firstOrCreate(
            ['application_code' => self::APPLICATION_CODE],
            [
                'name' => 'OptiRadar',
                'description' => 'GPS telematics and vehicle tracking.',
                'owner' => 'OptiRadar Team',
                'version' => '1.0.0',
                'status' => Application::STATUS_PUBLISHED,
                'frontend_url' => 'https://optiradar.example.com',
                'backend_url' => 'https://api.optiradar.example.com',
            ],
        );

        $tenant = Tenant::query()->where('tenant_code', self::DEMO_TENANT_CODE)->first();

        if (! $tenant) {
            return;
        }

        if (! $tenant->applications()->where('applications.id', $radar->id)->exists()) {
            $tenant->applications()->attach($radar->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        }

        $demoUser = User::query()->where('email', self::DEMO_USER_EMAIL)->first();

        if ($demoUser) {
            UserApplicationAccess::query()->firstOrCreate(
                ['user_id' => $demoUser->id, 'tenant_id' => $tenant->id, 'application_id' => $radar->id],
                ['status' => UserApplicationAccess::STATUS_ACTIVE],
            );
        }
    }
}
