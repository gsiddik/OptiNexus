<?php

namespace Tests\Feature\Integration;

use App\Models\Application;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Services\Oidc\SsoAccessResolver;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\OptiRadarApplicationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesIntegrationFixtures;
use Tests\TestCase;

class OptiRadarApplicationSeederTest extends TestCase
{
    use CreatesIntegrationFixtures, RefreshDatabase;

    public function test_it_registers_a_published_optiradar_application_once(): void
    {
        $this->seed(OptiRadarApplicationSeeder::class);
        $this->seed(OptiRadarApplicationSeeder::class);

        $apps = Application::query()->where('application_code', 'optiradar')->get();
        $this->assertCount(1, $apps);
        $this->assertSame(Application::STATUS_PUBLISHED, $apps->first()->status);
    }

    public function test_a_repeated_seed_keeps_the_launch_urls_an_operator_has_set(): void
    {
        $this->seed(OptiRadarApplicationSeeder::class);
        Application::query()->where('application_code', 'optiradar')->update([
            'frontend_url' => 'https://radar.customer.test',
            'backend_url' => 'https://radar-api.customer.test',
        ]);

        $this->seed(OptiRadarApplicationSeeder::class);

        $radar = Application::query()->where('application_code', 'optiradar')->firstOrFail();
        $this->assertSame('https://radar.customer.test', $radar->frontend_url);
        $this->assertSame('https://radar-api.customer.test', $radar->backend_url);
    }

    public function test_without_the_demo_tenant_only_the_application_is_registered(): void
    {
        $this->seed(OptiRadarApplicationSeeder::class);

        $radar = Application::query()->where('application_code', 'optiradar')->firstOrFail();
        $this->assertSame(0, $radar->tenants()->count());
        $this->assertSame(0, UserApplicationAccess::query()->where('application_id', $radar->id)->count());
    }

    public function test_the_default_seed_lets_the_demo_user_open_both_optifleet_and_optiradar(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', OptiRadarApplicationSeeder::DEMO_USER_EMAIL)->firstOrFail();
        $resolver = app(SsoAccessResolver::class);

        foreach (['optifleet', 'optiradar'] as $code) {
            $application = Application::query()->where('application_code', $code)->firstOrFail();
            $tenants = $resolver->eligibleTenants($user, $application);

            $this->assertSame(['ACME-SG'], $tenants->pluck('tenant_code')->all(), $code);
        }

        $radar = Application::query()->where('application_code', 'optiradar')->firstOrFail();
        $this->assertSame(1, UserApplicationAccess::query()->where('application_id', $radar->id)->count());
    }
}
