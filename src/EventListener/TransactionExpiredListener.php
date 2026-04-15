<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Notification;
use App\Event\TransactionExpiredEvent;
use App\Service\Mailer\TransactionMailer;
use App\Service\Notification\NotificationDispatcher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class TransactionExpiredListener
{
    /**
     * Expired Transaction Listener constructor.
     *
     * @param TransactionMailer $mailer
     */
    public function __construct(
        private TransactionMailer $mailer,
        private NotificationDispatcher $notificationDispatcher,
    ) {
    }

    public function __invoke(TransactionExpiredEvent $event): void
    {
        $transaction = $event->getTransaction();
        $this->mailer->sendExpirationNotificationEmail($transaction);

        try {
            $user = $transaction->getUser();
            if (null !== $user) {
                $classes    = $transaction->getPackageTotalClasses();
                $expiredAt  = $transaction->getExpirationAt()?->format('d/m/Y') ?? '';
                $body       = $classes
                    ? sprintf('Tu paquete de %d clase(s) venció el %s. Adquiere uno nuevo para seguir reservando.', $classes, $expiredAt)
                    : 'Tu paquete ha vencido. Adquiere uno nuevo para seguir reservando.';

                $this->notificationDispatcher->dispatch(
                    'transaction_expired',
                    $user,
                    'Paquete vencido',
                    $body,
                    ['resource_key' => 'tx_expired_' . $transaction->getId()],
                    Notification::PRIORITY_HIGH,
                );
            }
        } catch (\Throwable) {}
    }
}
