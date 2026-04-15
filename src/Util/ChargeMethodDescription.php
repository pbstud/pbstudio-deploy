<?php

declare(strict_types=1);

namespace App\Util;

class ChargeMethodDescription extends BaseDescription
{
    public static array $values = [
        'payment.free' => [
            'description' => 'Gratis',
        ],
        'payment.cash' => [
            'description' => 'Efectivo',
        ],
        'payment.card' => [
            'description' => 'Tarjeta',
        ],
        'payment.pos' => [
            'description' => 'Terminal',
        ],
    ];
}
