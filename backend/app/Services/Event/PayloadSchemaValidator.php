<?php

namespace App\Services\Event;

/**
 * Minimal JSON-Schema-like validator for event payloads: `required` keys
 * and top-level `properties.*.type` checks only. Deliberately not a full
 * JSON Schema implementation - Phase 3 only needs "does this payload
 * roughly match the registered shape", not full spec compliance.
 *
 * payload_schema shape:
 *   {"required": ["amount"], "properties": {"amount": {"type": "number"}}}
 */
class PayloadSchemaValidator
{
    /**
     * @return string[] validation error messages, empty when valid
     */
    public function validate(array $data, ?array $schema): array
    {
        if (! $schema) {
            return [];
        }

        $errors = [];

        foreach ($schema['required'] ?? [] as $field) {
            if (! array_key_exists($field, $data)) {
                $errors[] = "Missing required field [{$field}].";
            }
        }

        foreach ($schema['properties'] ?? [] as $field => $definition) {
            if (! array_key_exists($field, $data) || ! isset($definition['type'])) {
                continue;
            }

            if (! $this->matchesType($data[$field], $definition['type'])) {
                $errors[] = "Field [{$field}] must be of type [{$definition['type']}].";
            }
        }

        return $errors;
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'number' => is_numeric($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'object' => is_array($value) && (empty($value) || array_is_list($value) === false),
            'array' => is_array($value) && (empty($value) || array_is_list($value)),
            default => true,
        };
    }
}
