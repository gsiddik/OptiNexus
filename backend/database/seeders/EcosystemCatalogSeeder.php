<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Capability;
use App\Models\EventCatalogEntry;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\Product;
use App\Services\Event\PlatformEvents;
use App\Services\Manifest\ManifestImportService;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registers the products of the Opti platform in OptiNexus so that all of them can be governed from here: for OptiFleet (optifleet),
 * OptiEntry (optiaccounting), OptiDeli (optideli) and OptiRadar (optiradar) it creates the application, imports its modules, features
 * and permissions from the manifest in database/seeders/manifests, registers the events it reports, and puts every capability of the
 * four into one sellable product and plan ("Opti Suite") so that a tenant can be subscribed, provisioned and activated.
 *
 * Everything is additive and safe to repeat on every deployment, like `ecosystem:import-manifest`:
 *  - an application that exists is left exactly as it is (an operator's real URLs are never overwritten); only the catalog is topped up;
 *  - nothing is deleted; the suite product and plan only gain capabilities, and their status and name are not touched once created;
 *  - no customers, tenants, users, OIDC clients or service accounts are created: those carry secrets, which only
 *    `ecosystem:register-oidc-client` and `ecosystem:register-service-account` hand out (to a 0600 file), once per product.
 * The bootstrap administrator (PLATFORM_SUPERADMIN) is AdminUserSeeder's job and runs after this seeder, together with the system roles,
 * so that the platform roles pick up the imported permissions ("every permission" is resolved when SystemRoleSeeder runs).
 *
 * To refresh a manifest after a product release, replace its file (the product prints it: `optifleet` `optinexus:manifest`,
 * `optiaccounting` `optientry:nexus:manifest --pretty`, `optideli` `optideli:nexus:manifest`) and seed again.
 *
 * Run: php artisan db:seed --class=EcosystemCatalogSeeder
 */
class EcosystemCatalogSeeder extends Seeder
{
    /** Application code => manifest file name in database/seeders/manifests. */
    public const MANIFESTS = [
        'optifleet' => 'optifleet.manifest.json',
        'optiaccounting' => 'optiaccounting.manifest.json',
        'optideli' => 'optideli.manifest.json',
        'optiradar' => 'optiradar.manifest.json',
    ];

    public const SUITE_PRODUCT_CODE = 'OPTI-SUITE';

    public const SUITE_PLAN_CODE = 'SUITE-MONTHLY';

    public function run(ManifestImportService $manifests): void
    {
        $applications = [];
        $limitKeys = [];

        foreach (self::MANIFESTS as $code => $file) {
            $manifest = $this->readManifest($file);

            if ($manifest === null) {
                continue;
            }

            $application = $this->registerApplication($code, $manifest);
            $applications[] = $application;
            $limitKeys = array_merge($limitKeys, array_map('strtolower', (array) ($manifest['entitlement_limit_keys'] ?? [])));

            try {
                $summary = $manifests->import($application, $manifest, dryRun: false, allowDelegableWidening: false);
            } catch (ValidationException $e) {
                $this->command?->error("Manifest of {$code} was refused: ".implode(' ', array_merge(...array_values($e->errors()))));

                continue;
            }

            $this->registerEvents($application, $manifest['events'] ?? []);
            $this->command?->info("{$code}: manifest {$summary['revision']} imported.");
        }

        $this->registerPlatformEvents();
        $this->registerSuite($applications, array_values(array_unique($limitKeys)));
    }

    /** @return array<string, mixed>|null */
    private function readManifest(string $file): ?array
    {
        $path = __DIR__.'/manifests/'.$file;
        $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($manifest)) {
            $this->command?->warn("Manifest {$file} is missing or not valid JSON; skipped.");

            return null;
        }

        return $manifest;
    }

    /** @param  array<string, mixed>  $manifest */
    private function registerApplication(string $code, array $manifest): Application
    {
        $declared = $manifest['application'] ?? [];

        return Application::query()->firstOrCreate(
            ['application_code' => $code],
            [
                'name' => (string) ($declared['name'] ?? $code),
                'description' => $declared['description'] ?? null,
                'frontend_url' => $declared['frontend_url'] ?? null,
                'version' => (string) ($manifest['revision'] ?? '1'),
                'status' => Application::STATUS_PUBLISHED,
            ],
        );
    }

    /**
     * Event types the product reports (POST /api/v1/events). Until one is registered OptiNexus answers 422 and the product's relay retries.
     *
     * @param  array<int, array<string, mixed>>  $events
     */
    private function registerEvents(Application $application, array $events): void
    {
        foreach ($events as $event) {
            EventCatalogEntry::query()->updateOrCreate(
                ['event_key' => $event['event_key']],
                [
                    'application_id' => $application->id,
                    'name' => $event['name'],
                    'description' => $event['description'] ?? '',
                    'schema_version' => (string) ($event['schema_version'] ?? '1'),
                    'payload_schema' => $event['payload_schema'] ?? ApplicationEventCatalogSeeder::PAYLOAD_SCHEMA,
                    'status' => EventCatalogEntry::STATUS_ACTIVE,
                ],
            );
        }
    }

    /** The events OptiNexus publishes itself (PlatformEvents); they belong to no application. */
    private function registerPlatformEvents(): void
    {
        foreach (PlatformEvents::catalog() as $key => $definition) {
            EventCatalogEntry::query()->updateOrCreate(
                ['event_key' => $key],
                [
                    'application_id' => null,
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'schema_version' => '1',
                    'payload_schema' => ApplicationEventCatalogSeeder::PAYLOAD_SCHEMA,
                    'status' => EventCatalogEntry::STATUS_ACTIVE,
                ],
            );
        }
    }

    /**
     * One product and one plan holding every capability of the registered applications. A tenant cannot be provisioned without a
     * subscription, so without this nothing could be onboarded from the console. Operators who sell the products separately create
     * their own products and plans; this one stays as the all-in default.
     *
     * A product that declares a limit key (`user_limit`, `device_limit`, ...) is seat-controlled: with no limit on the plan nobody can be added to
     * it. The all-in suite therefore starts unlimited for every declared key. A limit that exists is never changed, so an operator can set real numbers.
     *
     * @param  array<int, Application>  $applications
     * @param  array<int, string>  $limitKeys
     */
    private function registerSuite(array $applications, array $limitKeys): void
    {
        if ($applications === []) {
            return;
        }

        $product = Product::query()->firstOrCreate(
            ['product_code' => self::SUITE_PRODUCT_CODE],
            ['name' => 'Opti Suite', 'description' => 'Every Opti application and capability.', 'currency' => 'IDR', 'status' => Product::STATUS_ACTIVE],
        );

        $applicationIds = array_map(fn (Application $a) => $a->id, $applications);
        $capabilityIds = Capability::query()->whereIn('application_id', $applicationIds)->pluck('id')->all();

        $this->attachMissing($product->applications(), 'applications.id', $applicationIds);
        $this->attachMissing($product->capabilities(), 'capabilities.id', $capabilityIds);

        $plan = Plan::query()->firstOrCreate(
            ['product_id' => $product->id, 'plan_code' => self::SUITE_PLAN_CODE],
            ['name' => 'Suite Monthly', 'description' => 'All applications, billed monthly.', 'billing_interval' => Plan::INTERVAL_MONTHLY,
                'currency' => 'IDR', 'status' => Plan::STATUS_ACTIVE],
        );

        $this->attachMissing($plan->capabilities(), 'capabilities.id', $capabilityIds);

        foreach ($limitKeys as $key) {
            PlanLimit::query()->firstOrCreate(['plan_id' => $plan->id, 'limit_key' => $key], ['limit_value' => null, 'is_unlimited' => true]);
        }
    }

    /**
     * The pivot tables carry their own uuid primary key, so rows are attached with an id (as the API controllers do) and only
     * the ones that are not there yet.
     *
     * @param  array<int, string>  $ids
     */
    private function attachMissing(BelongsToMany $relation, string $keyColumn, array $ids): void
    {
        $present = $relation->pluck($keyColumn)->all();

        foreach (array_diff($ids, $present) as $id) {
            $relation->attach($id, ['id' => (string) Str::uuid()]);
        }
    }
}
