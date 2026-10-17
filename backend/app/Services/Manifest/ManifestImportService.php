<?php

namespace App\Services\Manifest;

use App\Models\Application;
use App\Models\Capability;
use App\Models\Permission;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CapabilityHierarchyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Additive, versioned import of an application's catalog (audit A02): the capabilities (modules/features) and permissions a
 * product publishes, with the explicit `delegable` flag the tenant delegation API relies on. It only ever adds or refines:
 *
 *  - nothing is deleted or disabled (permissions the manifest no longer lists are reported as `unlisted`, not touched);
 *  - a DISABLED/DEPRECATED capability or permission keeps the status an operator gave it;
 *  - a permission that is not delegable never becomes delegable on a re-import unless the operator says so
 *    (`allow_delegable_widening`), while delegable -> not delegable is applied at once (narrowing is always safe);
 *  - permission keys must carry the application's own prefix and can never be in the governance (`cgo.`) namespace.
 */
class ManifestImportService
{
    public const SUPPORTED_VERSIONS = ['2.0'];

    public function __construct(
        private readonly CapabilityHierarchyService $hierarchy,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array{manifest_version: string, revision: string, capabilities?: array<int, array<string, mixed>>, permissions?: array<int, array<string, mixed>>}  $manifest
     * @return array<string, mixed> summary
     */
    public function import(Application $application, array $manifest, bool $dryRun, bool $allowDelegableWidening, ?User $actor = null, ?Request $request = null): array
    {
        $capabilities = $manifest['capabilities'] ?? [];
        $permissions = $manifest['permissions'] ?? [];

        $this->validateKeys($application, $capabilities, $permissions);

        DB::beginTransaction();

        try {
            $capabilitySummary = $this->importCapabilities($application, $capabilities);
            $permissionSummary = $this->importPermissions($application, $permissions, $allowDelegableWidening);

            $summary = [
                'capabilities' => $capabilitySummary,
                'permissions' => $permissionSummary['counts'],
                'delegable_widening_pending' => $permissionSummary['pending'],
                'unlisted_permissions' => $permissionSummary['unlisted'],
            ];

            // A dry run reports exactly what an import would do, then rolls everything back.
            if ($dryRun) {
                DB::rollBack();

                return ['dry_run' => true, 'revision' => $manifest['revision']] + $summary;
            }

            $metadata = $application->metadata ?? [];
            $metadata['manifest'] = [
                'version' => $manifest['manifest_version'],
                'revision' => $manifest['revision'],
                'synced_at' => now()->toIso8601String(),
                'synced_by' => $actor?->id,
                'capabilities' => count($capabilities),
                'permissions' => count($permissions),
            ];
            $application->update(['metadata' => $metadata]);

            $this->audit->record('application.manifest_imported', $request, actor: $actor, applicationId: $application->id, resourceType: 'Application',
                resourceId: $application->id, newValue: ['revision' => $manifest['revision'], 'summary' => $summary], source: $request ? 'cgo' : 'cli');

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return ['dry_run' => false, 'revision' => $manifest['revision']] + $summary;
    }

    /**
     * @param  array<int, array<string, mixed>>  $capabilities
     * @param  array<int, array<string, mixed>>  $permissions
     */
    private function validateKeys(Application $application, array $capabilities, array $permissions): void
    {
        $errors = [];
        $prefix = $application->application_code.'.';
        $capabilityCodes = array_column($capabilities, 'code');

        if (count($capabilityCodes) !== count(array_unique($capabilityCodes))) {
            $errors['capabilities'][] = 'Duplicate capability codes in the manifest.';
        }

        foreach ($capabilities as $capability) {
            $parent = $capability['parent_code'] ?? null;
            if ($parent !== null && ! in_array($parent, $capabilityCodes, true)
                && ! Capability::query()->where('application_id', $application->id)->where('code', $parent)->exists()) {
                $errors['capabilities'][] = "Capability [{$capability['code']}] names an unknown parent [{$parent}].";
            }
        }

        $keys = array_column($permissions, 'permission_key');
        if (count($keys) !== count(array_unique($keys))) {
            $errors['permissions'][] = 'Duplicate permission keys in the manifest.';
        }

        foreach ($permissions as $permission) {
            $key = $permission['permission_key'];
            if (str_starts_with($key, 'cgo.') || ! str_starts_with($key, $prefix)) {
                $errors['permissions'][] = "Permission [{$key}] is outside the [{$prefix}] namespace of this application.";
            }

            $code = $permission['capability_code'] ?? null;
            if ($code !== null && ! in_array($code, $capabilityCodes, true)
                && ! Capability::query()->where('application_id', $application->id)->where('code', $code)->exists()) {
                $errors['permissions'][] = "Permission [{$key}] names an unknown capability [{$code}].";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $capabilities
     * @return array{created: int, updated: int, unchanged: int}
     */
    private function importCapabilities(Application $application, array $capabilities): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        $pending = $capabilities;
        $guard = count($pending) + 1;

        // Parents first: keep passing over the list until every capability whose parent exists has been handled.
        while ($pending !== [] && $guard-- > 0) {
            foreach ($pending as $index => $row) {
                $parent = null;
                if (($row['parent_code'] ?? null) !== null) {
                    $parent = Capability::query()->where('application_id', $application->id)->where('code', $row['parent_code'])->first();
                    if (! $parent) {
                        continue;
                    }
                }

                $capability = Capability::withTrashed()->where('application_id', $application->id)->where('code', $row['code'])->first();

                if ($capability?->trashed()) {
                    throw ValidationException::withMessages(['capabilities' => ["Capability [{$row['code']}] was deleted by an operator; restore it in the portal first."]]);
                }

                $violation = $this->hierarchy->validate($capability ?? new Capability, $parent?->id, $row['type'], $application->id);
                if ($violation) {
                    throw ValidationException::withMessages(['capabilities' => ["Capability [{$row['code']}]: {$violation}"]]);
                }

                $attributes = [
                    'type' => $row['type'], 'name' => $row['name'], 'description' => $row['description'] ?? null,
                    'sort_order' => (int) ($row['sort_order'] ?? 0), 'parent_id' => $parent?->id,
                ];

                if (! $capability) {
                    Capability::create($attributes + ['application_id' => $application->id, 'code' => $row['code'], 'status' => Capability::STATUS_ACTIVE]);
                    $counts['created']++;
                } elseif ($this->differs($capability, $attributes)) {
                    $capability->update($attributes);
                    $counts['updated']++;
                } else {
                    $counts['unchanged']++;
                }

                unset($pending[$index]);
            }
        }

        return $counts;
    }

    /**
     * @param  array<int, array<string, mixed>>  $permissions
     * @return array{counts: array{created: int, updated: int, unchanged: int}, pending: array<int, string>, unlisted: array<int, string>}
     */
    private function importPermissions(Application $application, array $permissions, bool $allowDelegableWidening): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        $pending = [];

        foreach ($permissions as $row) {
            $capabilityId = ($row['capability_code'] ?? null) !== null
                ? Capability::query()->where('application_id', $application->id)->where('code', $row['capability_code'])->value('id')
                : null;
            $delegable = (bool) ($row['delegable'] ?? false);
            $permission = Permission::query()->where('permission_key', $row['permission_key'])->first();

            if ($permission && $permission->application_id !== $application->id) {
                throw ValidationException::withMessages(['permissions' => ["Permission [{$row['permission_key']}] belongs to another application."]]);
            }

            $attributes = ['name' => $row['name'], 'description' => $row['description'] ?? null, 'capability_id' => $capabilityId];

            if (! $permission) {
                Permission::create($attributes + [
                    'application_id' => $application->id, 'permission_key' => $row['permission_key'],
                    'status' => Permission::STATUS_ACTIVE, 'delegable' => $delegable,
                ]);
                $counts['created']++;

                continue;
            }

            if ($delegable && ! $permission->delegable) {
                if ($allowDelegableWidening) {
                    $attributes['delegable'] = true;
                } else {
                    $pending[] = $row['permission_key'];
                }
            } elseif (! $delegable && $permission->delegable) {
                $attributes['delegable'] = false;
            }

            if ($this->differs($permission, $attributes)) {
                $permission->update($attributes);
                $counts['updated']++;
            } else {
                $counts['unchanged']++;
            }
        }

        $unlisted = Permission::query()->where('application_id', $application->id)
            ->whereNotIn('permission_key', array_column($permissions, 'permission_key'))
            ->orderBy('permission_key')->pluck('permission_key')->all();

        return ['counts' => $counts, 'pending' => $pending, 'unlisted' => $unlisted];
    }

    /** @param array<string, mixed> $attributes */
    private function differs(object $model, array $attributes): bool
    {
        foreach ($attributes as $key => $value) {
            if ($model->{$key} != $value) {
                return true;
            }
        }

        return false;
    }
}
