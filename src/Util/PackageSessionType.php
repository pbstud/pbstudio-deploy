<?php

declare(strict_types=1);

namespace App\Util;

class PackageSessionType extends BaseDescription
{
    public const TYPE_INDIVIDUAL = 'i';
    public const TYPE_GROUP = 'g';

    public const TYPES = [
        self::TYPE_INDIVIDUAL,
        self::TYPE_GROUP,
    ];

    public static array $values = [
        self::TYPE_INDIVIDUAL => [
            'description' => 'Individual',
        ],
        self::TYPE_GROUP => [
            'description' => 'Grupal',
        ],
    ];
}
