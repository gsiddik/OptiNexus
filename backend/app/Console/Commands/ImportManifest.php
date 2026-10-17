<?php

namespace App\Console\Commands;

use App\Http\Requests\V1\ImportManifestRequest;
use App\Models\Application;
use App\Services\Manifest\ManifestImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Operator path for the catalog import (audit A02), for installs without a signed-in operator (first boot, Podman rehearsal,
 * CI). Reads the JSON a product prints (for example `optientry:nexus:manifest`), validates it exactly like the API and applies
 * it additively. Prints counts only; nothing sensitive is read or written.
 */
class ImportManifest extends Command
{
    protected $signature = 'ecosystem:import-manifest
        {application_code : application code the manifest belongs to}
        {file : path of the manifest JSON}
        {--dry-run : report what would change and change nothing}
        {--allow-delegable-widening : let existing permissions become delegable}
        {--create-application : register the application (PUBLISHED) from the manifest when it does not exist yet}';

    protected $description = 'Import a product catalog (capabilities, permissions, delegable flag) into the governance core, additively';

    public function handle(ManifestImportService $manifests): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Cannot read [{$path}].");

            return self::FAILURE;
        }

        $manifest = json_decode((string) file_get_contents($path), true);

        if (! is_array($manifest)) {
            $this->error('The file is not valid JSON.');

            return self::FAILURE;
        }

        $validator = Validator::make($manifest, ImportManifestRequest::manifestRules());

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $code = (string) $this->argument('application_code');
        $application = Application::query()->where('application_code', $code)->first();

        if (! $application) {
            $declared = $manifest['application'] ?? null;

            if (! $this->option('create-application') || ! is_array($declared) || ($declared['application_code'] ?? null) !== $code) {
                $this->error("Application [{$code}] is not registered. Use --create-application with a manifest that declares it.");

                return self::FAILURE;
            }

            if ($this->option('dry-run')) {
                $this->info("Application [{$code}] would be registered (PUBLISHED).");
            } else {
                $application = Application::create([
                    'application_code' => $code,
                    'name' => (string) ($declared['name'] ?? $code),
                    'description' => $declared['description'] ?? null,
                    'frontend_url' => $declared['frontend_url'] ?? null,
                    'version' => (string) ($manifest['revision'] ?? '1'),
                    'status' => Application::STATUS_PUBLISHED,
                ]);
            }
        }

        if (! $application) {
            return self::SUCCESS;
        }

        try {
            $summary = $manifests->import(
                $application,
                $manifest,
                dryRun: (bool) $this->option('dry-run'),
                allowDelegableWidening: (bool) $this->option('allow-delegable-widening'),
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->line((string) json_encode($summary, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
