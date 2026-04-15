<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Event\ReservationSuccessEvent;
use App\Service\Mailer\ReservationMailer;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class ReservationSuccessListener
{
    public function __construct(private ReservationMailer $mailer)
    {
    }

    public function __invoke(ReservationSuccessEvent $event): void
    {
        if ($event->getWaitingList()) {
            $this->mailer->sendWaitingListConfirmationEmail($event->getReservation());

            return;
        }

        $this->mailer->sendConfirmationEmail($event->getReservation());
    }
}
