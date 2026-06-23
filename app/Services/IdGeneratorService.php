<?php

namespace App\Services;

use Illuminate\Support\Str;

class IdGeneratorService
{
    public static function generateId(String $prefix): string
    {
        $timeHex = str_pad(dechex(time()), 8, '0', STR_PAD_LEFT);
        $randomSuffix = Str::random(8);

        return $prefix . '_' . strtoupper($timeHex . $randomSuffix);
    }
}