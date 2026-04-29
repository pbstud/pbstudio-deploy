<?php

declare(strict_types=1);

namespace App\Util;

use App\Entity\GiftCard;

class GiftCardOriginDescription extends BaseDescription
{
    public static array $values = [
        GiftCard::ORIGIN_FRONTEND => [
            'description' => 'Frontend',
            'label' => 'info',
        ],
        GiftCard::ORIGIN_BACKEND => [
            'description' => 'Backend',
            'label' => 'primary',
        ],
    ];
}
