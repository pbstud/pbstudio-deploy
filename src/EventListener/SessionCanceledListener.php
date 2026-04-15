<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Reservation;
use App\Entity\Transaction;
use App\Event\SessionCanceledEvent;
use App\Repository\ReservationRepository;
use App\Repository\WaitingListRepository;
use App\Service\Mailer\ReservationMailer;
use App\Service\Mailer\WaitingListMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class SessionCanceledListener
{
    public function __construct(
        private EntityManagerInterface $em,
        private ReservationRepository $reservationRepository,
        private WaitingListRepository $waitingListRepository,
        private ReservationMailer $reservationMailer,
        private WaitingListMailer $waitingListMailer,
    ) {
    }

    public function __invoke(SessionCanceledEvent $event): void
    {
        $session = $event->getSession();
        $reservations = $this->reservationRepository->getReservationsBySession($session);
        $waitingListEntries = $this->waitingListRepository->getAvailableBySession($session);

        /** @var Reservation $reservation */
        foreach ($reservations as $reservation) {
            /** @var Transaction $transaction */
            $transaction = $reservation->getTransaction();

            if (!$transaction->isHaveSessionsAvailable()) {
                $transaction->setHaveSessionsAvailable(true);

                $this->em->persist($transaction);
            }
        }

        $this->em->flush();

        /** @var Reservation $reservation */
        foreach ($reservations as $reservation) {
            $this->reservationMailer->sendSessionCanceledEmail($reservation);
        }

        foreach ($waitingListEntries as $waitingListEntry) {
            $this->waitingListMailer->sendSessionCanceledEmail($waitingListEntry);
        }
    }
}
