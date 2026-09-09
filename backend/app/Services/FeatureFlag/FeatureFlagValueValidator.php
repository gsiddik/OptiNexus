<?php

namespace App\Services\FeatureFlag;

use App\Models\FeatureFlag;

class FeatureFlagValueValidator
{
    public static function matchesType(mixed $value, string $flagType): bool
    {
        return match ($flagType) {
            FeatureFlag::TYPE_BOOLEAN => is_bool($value),
            FeatureFlag::TYPE_STRING => is_string($value),
            FeatureFlag::TYPE_NUMBER => is_int($value) || is_float($value),
            FeatureFlag::TYPE_JSON => is_array($value) || is_object($value),
            default => false,
        };
    }
}
