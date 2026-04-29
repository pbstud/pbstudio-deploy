<?php

declare(strict_types=1);

namespace App\Util;

use App\Entity\GiftCard;
use App\Entity\Transaction;

class GiftCardStatusResolver
{
    public static function resolve(GiftCard $giftCard): string
    {
        if (GiftCard::STATUS_CANCELLED === $giftCard->getStatus()) {
            return GiftCard::STATUS_CANCELLED;
        }

        if (self::hasTransactionStatus($giftCard->getRedemptionTransaction(), Transaction::STATUS_CANCEL)
            || self::hasTransactionStatus($giftCard->getPurchaseTransaction(), Transaction::STATUS_CANCEL)) {
            return GiftCard::STATUS_CANCELLED;
        }

        if (GiftCard::STATUS_EXPIRED === $giftCard->getStatus()) {
            return GiftCard::STATUS_EXPIRED;
        }

        if (self::hasTransactionStatus($giftCard->getRedemptionTransaction(), Transaction::STATUS_FROZEN)
            || self::hasTransactionStatus($giftCard->getPurchaseTransaction(), Transaction::STATUS_FROZEN)) {
            return GiftCard::STATUS_FROZEN;
        }

        return $giftCard->getStatus();
    }

    public static function getDescription(GiftCard $giftCard): string
    {
        return GiftCardStatusDescription::getDescription(self::resolve($giftCard));
    }

    public static function getLabel(GiftCard $giftCard): string
    {
        return GiftCardStatusDescription::getLabel(self::resolve($giftCard));
    }

    private static function hasTransactionStatus(?Transaction $transaction, int $status): bool
    {
        return null !== $transaction && $transaction->getStatus() === $status;
    }
}