<?php

declare(strict_types=1);

namespace App\Util;

abstract class BaseDescription
{
    public static array $values = [];

    public static function getDescription(string|int $value)
    {
        return static::$values[$value]['description'] ?? '-----';
    }

    public static function getLabel(string|int $value)
    {
        return static::$values[$value]['label'] ?? 'default';
    }
}
