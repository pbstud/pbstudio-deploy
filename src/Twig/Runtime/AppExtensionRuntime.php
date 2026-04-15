<?php

declare(strict_types=1);

namespace App\Twig\Runtime;

use App\Entity\Reservation;
use App\Repository\SessionAuditRepository;
use App\Security\Voter\RouteAccessVoter;
use App\Service\Reservation\ReservationService;
use App\Service\WaitingList\WaitingListCancellationWindowService;
use App\Util\ChargeMethodDescription;
use App\Util\PackageSessionType;
use App\Util\ReservationStatusDescription;
use App\Util\SessionStatusDescription;
use App\Util\TransactionStatusDescription;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\RuntimeExtensionInterface;

readonly class AppExtensionRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private Security $security,
        private ReservationService $reservationService,
        private SessionAuditRepository $sessionAuditRepository,
        private WaitingListCancellationWindowService $waitingListCancellationWindowService,
    ) {
    }

    public function isAllowedRoute(string $route): bool
    {
        return $this->security->isGranted(RouteAccessVoter::ALLOWED_ROUTE_ACCESS, $route);
    }

    public function getPackageSessionType(string $type): string
    {
        return PackageSessionType::getDescription($type);
    }

    public function getChargeMethodDescription(string $method): string
    {
        return ChargeMethodDescription::getDescription($method);
    }

    public function getSessionStatusDescription(string $value): string
    {
        return SessionStatusDescription::getDescription($value);
    }

    public function getSessionStatusLabel(string $value): string
    {
        return SessionStatusDescription::getLabel($value);
    }

    public function getTransactionStatusDescription(string $value): string
    {
        return TransactionStatusDescription::getDescription($value);
    }

    public function getTransactionStatusLabel(string $value): string
    {
        return TransactionStatusDescription::getLabel($value);
    }

    public function getReservationStatusDescription(string $value): string
    {
        return ReservationStatusDescription::getDescription($value);
    }

    public function getReservationStatusLabel(string $value): string
    {
        return ReservationStatusDescription::getLabel($value);
    }

    public function reservationCanCancel(Reservation $reservation): bool
    {
        if ($this->reservationHasChangeFlow($reservation)) {
            return false;
        }

        if ($this->waitingListCancellationWindowService->canCancelWithoutPenalty($reservation)) {
            return true;
        }

        return $this->reservationService->canCancel($reservation);
    }

    public function reservationCanChange(Reservation $reservation): bool
    {
        if ($this->reservationHasChangeFlow($reservation)) {
            return false;
        }

        return $this->reservationService->canChange($reservation);
    }

    private function reservationHasChangeFlow(Reservation $reservation): bool
    {
        if ($reservation->getChangedAt() !== null) {
            return true;
        }

        $reservationId = $reservation->getId();
        if ($reservationId === null) {
            return false;
        }

        return $this->sessionAuditRepository->hasReservationBeenChanged($reservationId);
    }
}
