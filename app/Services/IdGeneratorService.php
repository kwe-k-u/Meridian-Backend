<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Generates unique, prefixed IDs combining a timestamp hex and random suffix.
 * Used across the application for all primary key-like identifiers.
 */
class IdGeneratorService
{
    /**
     * Generate a unique ID with the given prefix (e.g. 'USR', 'TRP').
     *
     * Format: {PREFIX}_{8-char-hex-time}{8-char-random}
     */
    public static function generateId(String $prefix): string
    {
        $timeHex = str_pad(dechex(time()), 8, '0', STR_PAD_LEFT);
        $randomSuffix = Str::random(8);

        return $prefix . '_' . strtoupper($timeHex . $randomSuffix);
    }
}