<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\EventCatalogEntry;
use Illuminate\Database\Seeder;

/**
 * Registers the events an integrated application reports to OptiNexus
 * (POST /api/v1/events) in the Event Catalog. Idempotent: running it again
 * updates the entries. The application must exist first; until it does the
 * seeder only warns, so a default seed on a fresh installation never fails.
 *
 * Every event shares one envelope in `data`:
 *   aggregate_type  what the event is about (workshop_invoice, device, ...)
 *   aggregate_id    id of that record in the producing application
 *   correlation     ids that tie the event to its context
 *   payload         the facts of this event type
 */
abstract class ApplicationEventCatalogSeeder extends Seeder
{
    public const PAYLOAD_SCHEMA = [
        'required' => ['aggregate_type', 'aggregate_id', 'payload'],
        'properties' => [
            'aggregate_type' => ['type' => 'string'],
            'aggregate_id' => ['type' => 'string'],
            'correlation' => ['type' => 'object'],
            'payload' => ['type' => 'object'],
        ],
    ];

    abstract protected function applicationCode(): string;

    /**
     * @return array<string, array{name: string, description: string}> keyed by event_key
     */
    abstract protected function events(): array;

    public function run(): void
    {
        $application = Application::query()->where('application_code', $this->applicationCode())->first();

        if (! $application) {
            $this->command?->warn('Application "'.$this->applicationCode().'" does not exist yet; register it, then run this seeder again.');

            return;
        }

        foreach ($this->events() as $key => $definition) {
            EventCatalogEntry::query()->updateOrCreate(
                ['event_key' => $key],
                [
                    'application_id' => $application->id,
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'schema_version' => '1',
                    'payload_schema' => self::PAYLOAD_SCHEMA,
                    'status' => EventCatalogEntry::STATUS_ACTIVE,
                ],
            );
        }
    }
}
