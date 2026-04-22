<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Notification;
use App\Event\WaitingListRemovedEvent;
use App\Service\Mailer\WaitingListMailer;
use App\Service\Notification\NotificationDispatcher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class WaitingListRemovedListener
{
    public function __construct(
        private WaitingListMailer $mailer,
        private NotificationDispatcher $notificationDispatcher,
    ) {
    }

    public function __invoke(WaitingListRemovedEvent $event): void
    {
        $waitingList = $event->getWaitingList();
        $this->mailer->sendRemovalEmail($waitingList);

        try {
            $user       = $waitingList->getUser();
            $session    = $waitingList->getSession();
            $discipline = $session?->getDiscipline()?->getName() ?? 'la clase';
            $date       = ($session && $session->getDateStart() && $session->getTimeStart())
                ? $session->getDateTimeStart()->format('d/m/Y H:i')
                : ($session?->getDateStart()?->format('d/m/Y') ?? '');

            $this->notificationDispatcher->dispatch(
                'waiting_list_removed',
                $user,
                'Saliste de la lista de espera',
                sprintf('Tu lugar en la lista de espera de %s del %s fue eliminado.', $discipline, $date),
                ['resource_key' => sprintf('wl_removed_%d_%d', (int) $user?->getId(), (int) $session?->getId())],
                Notification::PRIORITY_LOW,
            );
        } catch (\Throwable) {}
    }
}
