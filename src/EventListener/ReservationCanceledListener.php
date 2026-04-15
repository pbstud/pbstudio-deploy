<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Event\ReservationCanceledEvent;
use App\Service\Mailer\ReservationMailer;
use App\Service\WaitingList\WaitingListService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class ReservationCanceledListener
{
    public function __construct(
        private ReservationMailer $mailer,
        private WaitingListService $waitingListService,
    ) {
    }

    public function __invoke(ReservationCanceledEvent $event): void
    {
        $reservation = $event->getReservation();

        $this->mailer->sendCancellationMail($reservation, $event->getSource());
        $this->waitingListService->checkAndReserve($reservation->getSession(), $reservation->getPlaceNumber());
    }
}
