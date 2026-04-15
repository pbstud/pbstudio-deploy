<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Event\WaitingListSuccessEvent;
use App\Service\Mailer\WaitingListMailer;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class WaitingListSuccessListener
{
    public function __construct(private WaitingListMailer $mailer)
    {
    }

    public function __invoke(WaitingListSuccessEvent $event): void
    {
        $this->mailer->sendRegistrationConfirmationEmail($event->getWaitingList());
    }
}
