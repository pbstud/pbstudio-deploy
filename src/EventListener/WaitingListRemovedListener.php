<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Event\WaitingListRemovedEvent;
use App\Service\Mailer\WaitingListMailer;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class WaitingListRemovedListener
{
    public function __construct(private WaitingListMailer $mailer)
    {
    }

    public function __invoke(WaitingListRemovedEvent $event): void
    {
        $this->mailer->sendRemovalEmail($event->getWaitingList());
    }
}
