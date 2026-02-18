<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * Custom Cast for Money Values
 */
class IntegerMoneyCast implements CastsAttributes
{
    /**
     * Convert from storage (cents) to application (decimal).
     *
     * @param  mixed  $value  The value from database (integer cents)
     * @return float|null     The value for application (decimal)
     */
    public function get($model, string $key, $value, array $attributes)
    {
        if (is_null($value)) {
            return null;
        }

        // Convert cents to decimal: 12345 -> 123.45
        return (float) ($value / 100);
    }

    /**
     * Convert from application (decimal) to storage (cents).
     *
     * @param  mixed  $value  The value from application (decimal)
     * @return int|null       The value for database (integer cents)
     */
    public function set($model, string $key, $value, array $attributes)
    {
        if (is_null($value)) {
            return null;
        }

        // Convert decimal to cents: 123.45 -> 12345
        // Use round() to handle floating-point precision issues
        return (int) round($value * 100);
    }
}
