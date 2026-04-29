<?php

declare(strict_types=1);

namespace App\Util;

use App\Entity\GiftCardHistory;

class GiftCardHistoryActionDescription extends BaseDescription
{
    public static array $values = [
        GiftCardHistory::ACTION_GENERATED => [
            'description' => 'Creada',
            'label' => 'info',
        ],
        GiftCardHistory::ACTION_EMAIL_SENT => [
            'description' => 'Email enviado',
            'label' => 'info',
        ],
        GiftCardHistory::ACTION_SHARED_MANUALLY => [
            'description' => 'Compartida manualmente',
            'label' => 'primary',
        ],
        GiftCardHistory::ACTION_REDEEM_ATTEMPT_INVALID => [
            'description' => 'Intento de canje invalido',
            'label' => 'warning',
        ],
        GiftCardHistory::ACTION_REDEEMED => [
            'description' => 'Canjeada',
            'label' => 'primary',
        ],
        GiftCardHistory::ACTION_CANCELLED => [
            'description' => 'Cancelada',
            'label' => 'default',
        ],
        GiftCardHistory::ACTION_EXPIRED => [
            'description' => 'Expirada',
            'label' => 'warning',
        ],
        GiftCardHistory::ACTION_RESENT => [
            'description' => 'Reenviada',
            'label' => 'info',
        ],
        GiftCardHistory::ACTION_CREATED_FROM_BACKEND => [
            'description' => 'Creada desde backend',
            'label' => 'info',
        ],
    ];
}