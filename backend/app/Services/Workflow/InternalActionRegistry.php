<?php

namespace App\Services\Workflow;

/**
 * Fixed whitelist of INTERNAL_ACTION step actions. Deliberately not
 * extensible via configuration - "do not build arbitrary code execution
 * nodes" means a workflow definition can only select from this list, never
 * supply its own executable logic.
 */
final class InternalActionRegistry
{
    public const EMIT_EVENT = 'emit_event';

    public const NO_OP = 'no_op';

    public const ACTIONS = [self::EMIT_EVENT, self::NO_OP];
}
