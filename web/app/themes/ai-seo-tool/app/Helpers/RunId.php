<?php
namespace AISEO\Helpers;

class RunId
{
    public static function new(): string
    {
        $ts = gmdate('Y-m-d_His');
        $rand = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        return "{$ts}_{$rand}";
    }
}
