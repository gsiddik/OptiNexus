<?php

namespace Tests\Feature;

use App\Models\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class ApplicationLifecycleTest extends TestCase
{
    use CreatesGovernanceFixtures, RefreshDatabase;

    public function test_application_moves_through_the_full_registry_lifecycle(): void
    {
        $this->actingAsSuperAdmin();

        $id = $this->postJson('/api/v1/applications', [
            'application_code' => 'vms',
            'name' => 'Vehicle Maintenance System',
        ])->assertStatus(201)->assertJsonPath('data.status', Application::STATUS_DRAFT)->json('data.id');

        $this->postJson("/api/v1/applications/{$id}/submit")->assertJsonPath('data.status', Application::STATUS_REVIEW);
        $this->postJson("/api/v1/applications/{$id}/approve")->assertJsonPath('data.status', Application::STATUS_APPROVED);
        $this->postJson("/api/v1/applications/{$id}/publish")->assertJsonPath('data.status', Application::STATUS_PUBLISHED);

        // Cannot approve an already-published application.
        $this->postJson("/api/v1/applications/{$id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');

        $this->postJson("/api/v1/applications/{$id}/deprecate")->assertJsonPath('data.status', Application::STATUS_DEPRECATED);
        $this->postJson("/api/v1/applications/{$id}/retire")->assertJsonPath('data.status', Application::STATUS_RETIRED);
    }

    public function test_capabilities_and_permissions_are_listed_per_application(): void
    {
        $this->actingAsSuperAdmin();
        $application = $this->makeApplication();
        $this->makePermission($application, $application->application_code.'.thing.view');

        $this->getJson("/api/v1/applications/{$application->id}/permissions")
            ->assertStatus(200)
            ->assertJsonFragment(['permission_key' => $application->application_code.'.thing.view']);
    }
}
