<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Notification;
use App\Entity\Reservation;
use App\Entity\Transaction;
use App\Event\SessionCanceledEvent;
use App\Repository\ReservationRepository;
use App\Repository\WaitingListRepository;
use App\Service\Mailer\ReservationMailer;
use App\Service\Mailer\WaitingListMailer;
use App\Service\Notification\NotificationDispatcher;
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
        private NotificationDispatcher $notificationDispatcher,
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

        $discipline = $session->getDiscipline()?->getName() ?? 'la clase';
        $date       = ($session->getDateStart() && $session->getTimeStart())
            ? $session->getDateTimeStart()->format('d/m/Y H:i')
            : ($session->getDateStart()?->format('d/m/Y') ?? '');

        /** @var Reservation $reservation */
        foreach ($reservations as $reservation) {
            $this->reservationMailer->sendSessionCanceledEmail($reservation);

            try {
                $user = $reservation->getUser();
                if (null !== $user) {
                    $this->notificationDispatcher->dispatch(
                        'session_canceled',
                        $user,
                        'Clase cancelada',
                        sprintf('La clase de %s del %s (asiento %d) fue cancelada. Tu pase ha sido devuelto.', $discipline, $date, $reservation->getPlaceNumber()),
                        ['resource_key' => sprintf('session_canceled_%d_%d', (int) $session->getId(), (int) $user->getId())],
                        Notification::PRIORITY_HIGH,
                    );
                }
            } catch (\Throwable) {}
        }

        foreach ($waitingListEntries as $waitingListEntry) {
            $this->waitingListMailer->sendSessionCanceledEmail($waitingListEntry);

            try {
                $user = $waitingListEntry->getUser();
                if (null !== $user) {
                    $this->notificationDispatcher->dispatch(
                        'session_canceled',
                        $user,
                        'Clase cancelada',
                        sprintf('La clase de %s del %s en la que estabas en lista de espera fue cancelada.', $discipline, $date),
                        ['resource_key' => sprintf('session_canceled_wl_%d_%d', (int) $session->getId(), (int) $user->getId())],
                        Notification::PRIORITY_MEDIUM,
                    );
                }
            } catch (\Throwable) {}
        }
    }
}
