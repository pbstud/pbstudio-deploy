<?php

declare(strict_types=1);

namespace App\Util;

use App\Entity\GiftCard;

class GiftCardStatusDescription extends BaseDescription
{
    public static array $values = [
        GiftCard::STATUS_GENERATED => [
            'description' => 'Generada',
            'label' => 'default',
        ],
        GiftCard::STATUS_REDEEMED => [
            'description' => 'Canjeada',
            'label' => 'success',
        ],
        GiftCard::STATUS_FROZEN => [
            'description' => 'Congelada',
            'label' => 'info',
        ],
        GiftCard::STATUS_EXPIRED => [
            'description' => 'Expirada',
            'label' => 'warning',
        ],
        GiftCard::STATUS_CANCELLED => [
            'description' => 'Cancelada',
            'label' => 'danger',
        ],
    ];
}
