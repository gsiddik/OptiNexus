<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Consistent correlation/causation id resolution across Phase 3
 * orchestration writes (Events, Workflow, Approval, Integration,
 * Notification, Audit). correlation_id identifies one end-to-end flow;
 * causation_id identifies the specific upstream record that caused a
 * given write (e.g. the event_id that started a workflow instance).
 */
final class Correlation
{
    public static function resolve(?Request $request, ?string $given = null): string
    {
        return $given
            ?? $request?->headers->get('X-Correlation-Id')
            ?? $request?->input('correlation_id')
            ?? (string) Str::uuid();
    }

    public static function newId(): string
    {
        return (string) Str::uuid();
    }
}
