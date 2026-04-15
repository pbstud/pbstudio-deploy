<?php

declare(strict_types=1);

namespace App\Util;

use App\Entity\Session;

class SessionStatusDescription extends BaseDescription
{
    public static array $values = [
        Session::STATUS_OPEN => [
            'description' => 'Abierta',
            'label' => 'primary',
        ],
        Session::STATUS_FULL => [
            'description' => 'Completa',
            'label' => 'danger',
        ],
        Session::STATUS_CLOSED => [
            'description' => 'Cerrada',
            'label' => 'default',
        ],
        Session::STATUS_CANCEL => [
            'description' => 'Cancelada',
            'label' => 'warning',
        ],
        Session::STATUS_NOT_CANCELED => [
            'description' => 'No Cancelada',
            'label' => 'primary',
        ],
    ];
}
