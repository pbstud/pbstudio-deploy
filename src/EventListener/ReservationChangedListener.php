<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Event\ReservationChangedEvent;
use App\Service\Mailer\ReservationMailer;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class ReservationChangedListener
{
    public function __construct(private ReservationMailer $mailer)
    {
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
    }
}
