<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Notification;
use App\Event\WaitingListExpiredEvent;
use App\Service\Mailer\WaitingListMailer;
use App\Service\Notification\NotificationDispatcher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class WaitingListExpiredListener
{
    public function __construct(
        private readonly WaitingListMailer $mailer,
        private readonly NotificationDispatcher $notificationDispatcher,
    ) {
    }

    public function __invoke(WaitingListExpiredEvent $event): void
    {
        $waitingList = $event->getWaitingList();
        $this->mailer->sendExpirationEmail($waitingList);

        $user    = $waitingList->getUser();
        $session = $waitingList->getSession();
        if ($user !== null && $session !== null) {
            $disciplineName = $session->getDiscipline()?->getName() ?? 'la clase';
            $sessionDate    = $session->getDateStart()?->format('d/m/Y') ?? '';
            try {
                $this->notificationDispatcher->dispatch(
                    'waiting_list_expired',
                    $user,
                    'Lista de espera expirada',
                    sprintf('Tu lugar en la lista de espera de %s del %s expiró sin que se liberara un lugar.', $disciplineName, $sessionDate),
                    ['resource_key' => sprintf('wl_expired_%d_%d', (int) $user->getId(), (int) $session->getId())],
                    Notification::PRIORITY_LOW,
                );
            } catch (\Throwable) {}
        }
    }
}
