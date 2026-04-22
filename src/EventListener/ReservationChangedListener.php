<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Notification;
use App\Event\ReservationChangedEvent;
use App\Service\Mailer\ReservationMailer;
use App\Service\Notification\NotificationDispatcher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class ReservationChangedListener
{
    public function __construct(
        private ReservationMailer $mailer,
        private NotificationDispatcher $notificationDispatcher,
    ) {
    }

    public function __invoke(ReservationChangedEvent $event): void
    {
        $this->mailer->sendChangedEmail(
            $event->getReservation(),
            $event->getSourceSession(),
            $event->getSourcePlace(),
            $event->getTargetSession(),
            $event->getTargetPlace(),
        );

        try {
            $user           = $event->getReservation()->getUser();
            $sourceDiscipline = $event->getSourceSession()->getDiscipline()?->getName() ?? 'la clase';
            $sourceDate       = $event->getSourceSession()->getDateStart() && $event->getSourceSession()->getTimeStart()
                ? $event->getSourceSession()->getDateTimeStart()->format('d/m/Y H:i')
                : ($event->getSourceSession()->getDateStart()?->format('d/m/Y') ?? '');
            $targetDiscipline = $event->getTargetSession()->getDiscipline()?->getName() ?? 'la nueva clase';
            $targetDate       = $event->getTargetSession()->getDateStart() && $event->getTargetSession()->getTimeStart()
                ? $event->getTargetSession()->getDateTimeStart()->format('d/m/Y H:i')
                : ($event->getTargetSession()->getDateStart()?->format('d/m/Y') ?? '');

            $this->notificationDispatcher->dispatch(
                'reservation_changed',
                $user,
                '¡Reserva actualizada!',
                sprintf(
                    'Cambiaste tu reserva de %s del %s (asiento %d) a %s del %s (asiento %d).',
                    $sourceDiscipline, $sourceDate, $event->getSourcePlace(),
                    $targetDiscipline, $targetDate, $event->getTargetPlace(),
                ),
                ['resource_key' => 'reservation_changed_' . $event->getReservation()->getId()],
                Notification::PRIORITY_HIGH,
            );
        } catch (\Throwable) {}
    }
}
