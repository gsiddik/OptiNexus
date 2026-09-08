<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

trait HandlesLifecycleTransitions
{
    /**
     * Applies a status transition if $model's current status is in
     * $allowedFrom, otherwise returns a 409 INVALID_STATE_TRANSITION
     * response. Returns null on success (caller proceeds), a JsonResponse
     * on failure.
     */
    protected function guardTransition(Model $model, string $toStatus, array $allowedFrom, string $column = 'status'): ?JsonResponse
    {
        $current = $model->{$column};

        if (! in_array($current, $allowedFrom, true)) {
            return $this->fail(
                'INVALID_STATE_TRANSITION',
                "Cannot transition from [{$current}] to [{$toStatus}].",
                409,
                ['current_status' => $current, 'requested_status' => $toStatus, 'allowed_from' => $allowedFrom],
            );
        }

        return null;
    }
}
