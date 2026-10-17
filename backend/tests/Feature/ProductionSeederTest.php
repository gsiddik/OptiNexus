<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductionSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        unset($_ENV['CGO_SUPERADMIN_PASSWORD'], $_SERVER['CGO_SUPERADMIN_PASSWORD'], $_ENV['CGO_SUPERADMIN_RESET_PASSWORD'], $_SERVER['CGO_SUPERADMIN_RESET_PASSWORD']);
        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    public function test_the_production_seeder_installs_the_catalog_without_demo_data(): void
    {
        $this->seed(ProductionSeeder::class);

        $this->assertGreaterThan(0, Permission::query()->count());
        $this->assertSame(0, Customer::query()->count(), 'no demo customers');
        $this->assertSame(1, User::query()->count(), 'only the bootstrap administrator');
    }

    public function test_seeding_again_keeps_a_password_the_administrator_has_changed(): void
    {
        $this->setEnv('CGO_SUPERADMIN_PASSWORD', 'First-Password-1!');
        $this->seed(ProductionSeeder::class);

        $admin = User::query()->where('email', 'superadmin@cgo.local')->firstOrFail();
        $admin->forceFill(['password' => 'Changed-By-Admin-2!'])->save();

        $this->setEnv('CGO_SUPERADMIN_PASSWORD', 'Another-Env-Value-3!');
        $this->seed(ProductionSeeder::class);

        $this->assertTrue(Hash::check('Changed-By-Admin-2!', $admin->refresh()->password));
        $this->assertSame(1, User::query()->count());
    }

    public function test_the_password_is_replaced_only_when_a_reset_is_requested(): void
    {
        $this->setEnv('CGO_SUPERADMIN_PASSWORD', 'First-Password-1!');
        $this->seed(ProductionSeeder::class);

        $this->setEnv('CGO_SUPERADMIN_PASSWORD', 'Reset-To-This-4!');
        $this->setEnv('CGO_SUPERADMIN_RESET_PASSWORD', 'true');
        $this->seed(ProductionSeeder::class);

        $this->assertTrue(Hash::check('Reset-To-This-4!', User::query()->where('email', 'superadmin@cgo.local')->firstOrFail()->password));
    }
}
