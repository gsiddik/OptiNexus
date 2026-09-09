<?php

namespace App\Support;

/**
 * Central, deterministic money arithmetic. No bcmath extension is assumed
 * to be present, and PHP floats are never used for the arithmetic itself
 * (only decimal strings and integer minor-unit representations) - this is
 * the single place rounding rules live, per the "no floating point money,
 * central rounding strategy" requirement. All monetary amounts are decimal
 * strings with exactly 2 fractional digits ("1234.50"); quantities may
 * carry up to 4 fractional digits (numeric(18,4) columns).
 *
 * Rounding mode: round-half-up (commercial rounding), applied once at the
 * point a value is converted to money scale - never accumulated across
 * intermediate float operations.
 */
final class Money
{
    public const SCALE = 2;

    public static function zero(): string
    {
        return '0.00';
    }

    public static function normalize(string|int|float $amount): string
    {
        return self::fromMinorUnits(self::toMinorUnits($amount));
    }

    public static function add(string $a, string $b): string
    {
        return self::fromMinorUnits(self::toMinorUnits($a) + self::toMinorUnits($b));
    }

    public static function subtract(string $a, string $b): string
    {
        return self::fromMinorUnits(self::toMinorUnits($a) - self::toMinorUnits($b));
    }

    /**
     * Subtract two quantity strings (up to 4 decimal places), clamped to a
     * minimum of zero - used for "quantity minus included_quantity"
     * overage math, where a negative result has no billing meaning.
     */
    public static function subtractQuantityFloorZero(string $a, string $b): string
    {
        $scale = 4;
        $result = self::toMinorUnits($a, $scale) - self::toMinorUnits($b, $scale);

        return self::fromMinorUnits(max(0, $result), $scale);
    }

    public static function minQuantity(string $a, string $b): string
    {
        $scale = 4;

        return self::toMinorUnits($a, $scale) <= self::toMinorUnits($b, $scale) ? $a : $b;
    }

    public static function compareQuantity(string $a, string $b): int
    {
        return self::toMinorUnits($a, 4) <=> self::toMinorUnits($b, 4);
    }

    public static function sum(array $amounts): string
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += self::toMinorUnits($amount);
        }

        return self::fromMinorUnits($total);
    }

    /**
     * Multiply a money amount by a quantity (up to 4 decimal places),
     * rounding the result to money scale using round-half-up.
     */
    public static function multiplyByQuantity(string $unitAmount, string|int|float $quantity): string
    {
        $unitMinor = self::toMinorUnits($unitAmount, self::SCALE);
        $qtyMinor = self::toMinorUnits((string) $quantity, 4);

        $negative = ($unitMinor < 0) !== ($qtyMinor < 0);
        $product = abs($unitMinor) * abs($qtyMinor); // scale = SCALE + 4

        $divisor = 10 ** 4;
        $quotient = intdiv($product, $divisor);
        $remainder = $product % $divisor;

        if ($remainder * 2 >= $divisor) {
            $quotient++;
        }

        return self::fromMinorUnits($negative ? -$quotient : $quotient);
    }

    /**
     * Apply a percentage rate (e.g. "11.0000" for 11%) to a money amount,
     * rounding half-up to money scale.
     */
    public static function applyPercentage(string $amount, string|int|float $ratePercent): string
    {
        $amountMinor = self::toMinorUnits($amount, self::SCALE);
        $rateMinor = self::toMinorUnits((string) $ratePercent, 4); // rate * 10^4

        $negative = ($amountMinor < 0) !== ($rateMinor < 0);
        $product = abs($amountMinor) * abs($rateMinor); // scale = SCALE + 4, still needs /100 for percent

        $divisor = 10 ** 4 * 100;
        $quotient = intdiv($product, $divisor);
        $remainder = $product % $divisor;

        if ($remainder * 2 >= $divisor) {
            $quotient++;
        }

        return self::fromMinorUnits($negative ? -$quotient : $quotient);
    }

    public static function isNegative(string $amount): bool
    {
        return self::toMinorUnits($amount) < 0;
    }

    public static function equals(string $a, string $b): bool
    {
        return self::toMinorUnits($a) === self::toMinorUnits($b);
    }

    public static function compare(string $a, string $b): int
    {
        return self::toMinorUnits($a) <=> self::toMinorUnits($b);
    }

    /**
     * Parses a decimal string/number into integer minor units at the given
     * scale, rounding half-up on any excess fractional digits.
     */
    public static function toMinorUnits(string|int|float $amount, int $scale = self::SCALE): int
    {
        $amount = trim((string) $amount);
        if ($amount === '' || $amount === '-') {
            return 0;
        }

        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '+-');

        [$intPart, $fracPart] = array_pad(explode('.', $amount, 2), 2, '');
        $intPart = ltrim($intPart, '0') ?: '0';

        // Keep `scale` digits plus one extra to decide rounding.
        $fracPart = str_pad($fracPart, $scale + 1, '0');
        $keep = substr($fracPart, 0, $scale);
        $roundDigit = (int) ($fracPart[$scale] ?? '0');

        $value = (int) ($intPart.$keep);
        if ($roundDigit >= 5) {
            $value++;
        }

        return $negative ? -$value : $value;
    }

    public static function fromMinorUnits(int $minorUnits, int $scale = self::SCALE): string
    {
        $negative = $minorUnits < 0;
        $digits = str_pad((string) abs($minorUnits), $scale + 1, '0', STR_PAD_LEFT);

        $intPart = substr($digits, 0, -$scale);
        $fracPart = substr($digits, -$scale);

        return ($negative ? '-' : '').$intPart.'.'.$fracPart;
    }
}
