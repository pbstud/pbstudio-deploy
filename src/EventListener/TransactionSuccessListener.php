<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Event\TransactionSuccessEvent;
use App\Entity\Notification;
use App\Service\CouponService;
use App\Service\Mailer\TransactionMailer;
use App\Service\Notification\NotificationDispatcher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Transaction Success Listener.
 */
#[AsEventListener]
final readonly class TransactionSuccessListener
{
    /**
     * Transaction Success Listener constructor.
     *
     * @param TransactionMailer $mailer
     * @param CouponService     $couponService
     */
    public function __construct(
        private TransactionMailer $mailer,
        private CouponService $couponService,
        private NotificationDispatcher $notificationDispatcher,
    ) {
    }

    public function __invoke(TransactionSuccessEvent $event): void
    {
        if ($event->getTransaction()->getCoupon()) {
            $this->couponService->addHistory($event->getTransaction());
        }

        $this->mailer->sendConfirmationEmail($event->getPackage(), $event->getTransaction(), $event->getuser());

        try {
            $transaction = $event->getTransaction();
            $package     = $event->getPackage();
            $user        = $event->getUser();
            $packageLabel = $package->getAltText() ?? ($package->getTotalClasses() ? "{$package->getTotalClasses()} clases" : 'paquete');
            $this->notificationDispatcher->dispatch(
                'payment_confirmed',
                $user,
                '¡Pago confirmado!',
                sprintf('Tu paquete "%s" fue activado exitosamente. ¡Ya puedes reservar tus clases!', $packageLabel),
                ['resource_key' => 'tx_confirmed_' . $transaction->getId()],
                Notification::PRIORITY_HIGH,
            );
        } catch (\Throwable) {}
    }
}
