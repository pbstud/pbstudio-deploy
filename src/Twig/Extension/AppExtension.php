<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Twig\Runtime\AppExtensionRuntime;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('PackageSessionType', [AppExtensionRuntime::class, 'getPackageSessionType']),
            new TwigFilter('ChargeMethodDescription', [AppExtensionRuntime::class, 'getChargeMethodDescription']),
            new TwigFilter('SessionStatusDescription', [AppExtensionRuntime::class, 'getSessionStatusDescription']),
            new TwigFilter('SessionStatusLabel', [AppExtensionRuntime::class, 'getSessionStatusLabel']),
            new TwigFilter('TransactionStatusDescription', [AppExtensionRuntime::class, 'getTransactionStatusDescription']),
            new TwigFilter('TransactionStatusLabel', [AppExtensionRuntime::class, 'getTransactionStatusLabel']),
            new TwigFilter('ReservationStatusDescription', [AppExtensionRuntime::class, 'getReservationStatusDescription']),
            new TwigFilter('ReservationStatusLabel', [AppExtensionRuntime::class, 'getReservationStatusLabel']),
            new TwigFilter('GiftCardStatusDescription', [AppExtensionRuntime::class, 'getGiftCardStatusDescription']),
            new TwigFilter('GiftCardStatusLabel', [AppExtensionRuntime::class, 'getGiftCardStatusLabel']),
            new TwigFilter('ResolvedGiftCardStatusDescription', [AppExtensionRuntime::class, 'getResolvedGiftCardStatusDescription']),
            new TwigFilter('ResolvedGiftCardStatusLabel', [AppExtensionRuntime::class, 'getResolvedGiftCardStatusLabel']),
            new TwigFilter('GiftCardOriginDescription', [AppExtensionRuntime::class, 'getGiftCardOriginDescription']),
            new TwigFilter('GiftCardOriginLabel', [AppExtensionRuntime::class, 'getGiftCardOriginLabel']),
            new TwigFilter('GiftCardHistoryActionDescription', [AppExtensionRuntime::class, 'getGiftCardHistoryActionDescription']),
            new TwigFilter('GiftCardHistoryActionLabel', [AppExtensionRuntime::class, 'getGiftCardHistoryActionLabel']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('is_allowed_route', [AppExtensionRuntime::class, 'isAllowedRoute']),
            new TwigFunction('reservation_can_cancel', [AppExtensionRuntime::class, 'reservationCanCancel']),
            new TwigFunction('reservation_can_change', [AppExtensionRuntime::class, 'reservationCanChange']),
            new TwigFunction('public_branch_offices', [AppExtensionRuntime::class, 'getPublicBranchOffices']),
        ];
    }
}
