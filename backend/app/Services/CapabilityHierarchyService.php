<?php

namespace App\Services;

use App\Models\Capability;

class CapabilityHierarchyService
{
    /**
     * @return string|null a human readable violation message, or null when valid
     */
    public function validate(Capability $capability, ?string $parentId, string $type, string $applicationId): ?string
    {
        if ($parentId === null) {
            if ($type !== Capability::TYPE_MODULE) {
                return "A capability of type [{$type}] requires a parent.";
            }

            return null;
        }

        $parent = Capability::query()->find($parentId);

        if (! $parent) {
            return 'The specified parent capability does not exist.';
        }

        if ($parent->application_id !== $applicationId) {
            return 'The parent capability belongs to a different application.';
        }

        $allowed = Capability::ALLOWED_PARENT_TYPES[$type] ?? null;
        if ($allowed === null || ! in_array($parent->type, $allowed, true)) {
            return "A capability of type [{$type}] cannot be nested under a [{$parent->type}].";
        }

        if ($capability->exists) {
            if ($parent->id === $capability->id) {
                return 'A capability cannot be its own parent.';
            }

            if (in_array($parent->id, $capability->descendantIds(), true)) {
                return 'This move would create a circular hierarchy.';
            }
        }

        return null;
    }
}
