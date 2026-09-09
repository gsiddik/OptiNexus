<?php

namespace App\Services\Policy;

/**
 * Safe, declarative condition evaluator shared by Policy and Workflow
 * CONDITION steps/transitions. Deliberately NOT a general-purpose
 * expression language: no eval(), no arbitrary PHP/JS/SQL, a fixed
 * operator whitelist, and dot-path field resolution over a plain PHP
 * array context - there is no way to reach outside the given $context.
 *
 * Grammar:
 *   {"all": [condition, ...]}          - AND
 *   {"any": [condition, ...]}          - OR
 *   {"not": condition}                 - NOT
 *   {"field": "a.b", "operator": "eq", "value": ...}
 *   {"field": "a.b", "operator": "eq_context", "value": "c.d"} - compares
 *     two paths within the same context (e.g. tenant scoping checks)
 */
class PolicyConditionEvaluator
{
    public const OPERATORS = [
        'eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'contains', 'eq_context', 'ne_context',
    ];

    private const MAX_DEPTH = 8;

    /**
     * Throws InvalidPolicyConditionException on any structurally unsafe or
     * malformed condition. Call this before persisting a condition, not
     * only at evaluation time, so an unsafe definition is rejected at
     * creation (POLICY_INVALID) rather than merely failing later.
     */
    public function validate(array $condition, int $depth = 0): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidPolicyConditionException('Condition is nested too deeply.');
        }

        if (array_key_exists('all', $condition) || array_key_exists('any', $condition)) {
            $key = array_key_exists('all', $condition) ? 'all' : 'any';
            if (! is_array($condition[$key])) {
                throw new InvalidPolicyConditionException("\"{$key}\" must be an array of conditions.");
            }
            foreach ($condition[$key] as $sub) {
                if (! is_array($sub)) {
                    throw new InvalidPolicyConditionException("Each entry under \"{$key}\" must be a condition object.");
                }
                $this->validate($sub, $depth + 1);
            }

            return;
        }

        if (array_key_exists('not', $condition)) {
            if (! is_array($condition['not'])) {
                throw new InvalidPolicyConditionException('"not" must be a condition object.');
            }
            $this->validate($condition['not'], $depth + 1);

            return;
        }

        if (! isset($condition['field'], $condition['operator']) || ! is_string($condition['field']) || ! is_string($condition['operator'])) {
            throw new InvalidPolicyConditionException('A condition leaf requires string "field" and "operator".');
        }

        if (! in_array($condition['operator'], self::OPERATORS, true)) {
            throw new InvalidPolicyConditionException("Unsupported operator [{$condition['operator']}].");
        }

        if (! preg_match('/^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)*$/', $condition['field'])) {
            throw new InvalidPolicyConditionException("Invalid field path [{$condition['field']}].");
        }

        if (! array_key_exists('value', $condition)) {
            throw new InvalidPolicyConditionException('A condition leaf requires "value".');
        }

        if (str_ends_with($condition['operator'], '_context')) {
            if (! is_string($condition['value']) || ! preg_match('/^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)*$/', $condition['value'])) {
                throw new InvalidPolicyConditionException('A *_context operator\'s "value" must be a field path.');
            }
        }

        if (in_array($condition['operator'], ['in', 'not_in'], true) && ! is_array($condition['value'])) {
            throw new InvalidPolicyConditionException('"in"/"not_in" require an array "value".');
        }
    }

    public function evaluate(array $condition, array $context): bool
    {
        if (array_key_exists('all', $condition)) {
            foreach ($condition['all'] as $sub) {
                if (! $this->evaluate($sub, $context)) {
                    return false;
                }
            }

            return true;
        }

        if (array_key_exists('any', $condition)) {
            foreach ($condition['any'] as $sub) {
                if ($this->evaluate($sub, $context)) {
                    return true;
                }
            }

            return false;
        }

        if (array_key_exists('not', $condition)) {
            return ! $this->evaluate($condition['not'], $context);
        }

        $operator = $condition['operator'];
        $left = $this->resolve($condition['field'], $context);
        $rawValue = $condition['value'] ?? null;
        $right = str_ends_with($operator, '_context') ? $this->resolve((string) $rawValue, $context) : $rawValue;

        return match ($operator) {
            'eq', 'eq_context' => $this->looseEquals($left, $right),
            'ne', 'ne_context' => ! $this->looseEquals($left, $right),
            'gt' => is_numeric($left) && is_numeric($right) && (float) $left > (float) $right,
            'gte' => is_numeric($left) && is_numeric($right) && (float) $left >= (float) $right,
            'lt' => is_numeric($left) && is_numeric($right) && (float) $left < (float) $right,
            'lte' => is_numeric($left) && is_numeric($right) && (float) $left <= (float) $right,
            'in' => is_array($right) && in_array($left, $right, false),
            'not_in' => is_array($right) && ! in_array($left, $right, false),
            'contains' => $this->contains($left, $right),
            default => false,
        };
    }

    private function looseEquals(mixed $left, mixed $right): bool
    {
        if (is_numeric($left) && is_numeric($right)) {
            return (float) $left === (float) $right;
        }

        return $left === $right;
    }

    private function contains(mixed $left, mixed $right): bool
    {
        if (is_string($left) && is_string($right)) {
            return str_contains($left, $right);
        }
        if (is_array($left)) {
            return in_array($right, $left, false);
        }

        return false;
    }

    private function resolve(string $path, array $context): mixed
    {
        $value = $context;
        foreach (explode('.', $path) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return null;
            }
        }

        return $value;
    }
}
