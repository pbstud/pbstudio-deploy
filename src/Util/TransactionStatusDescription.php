<?php

declare(strict_types=1);

namespace App\Util;

use App\Entity\Transaction;

class TransactionStatusDescription extends BaseDescription
{
    public static array $values = [
        Transaction::STATUS_PAID => [
            'description' => 'Pagada',
            'label' => 'primary',
        ],
        Transaction::STATUS_DENIED => [
            'description' => 'Denegada',
            'label' => 'default',
        ],
        Transaction::STATUS_CANCEL => [
            'description' => 'Cancelada',
            'label' => 'warning',
        ],
    ];
}
