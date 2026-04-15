<?php

declare(strict_types=1);

namespace App\Util;

use App\Entity\Transaction;
use App\Util\BaseDescription;

class ReservationStatusDescription extends BaseDescription
{
    public static array $values = [
        0 => [
            'description' => 'Cancelada',
            'label' => 'default',
        ],
        1 => [
            'description' => 'Activa',
            'label' => 'primary',
        ],
    ];
}
