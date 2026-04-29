<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Transaction;
use App\Event\TransactionSuccessEvent;
use App\Entity\Notification;
use App\Repository\GiftCardRepository;
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
     */
    public function __construct(
        private TransactionMailer $mailer,
        private CouponService $couponService,
        private NotificationDispatcher $notificationDispatcher,
        private GiftCardRepository $giftCardRepository,
    ) {
    }

    public function __invoke(TransactionSuccessEvent $event): void
    {
        if ($event->getTransaction()->getCoupon()) {
            $this->couponService->addHistory($event->getTransaction());
        }

        $transaction = $event->getTransaction();
        $redeemedGiftCard = $this->giftCardRepository->findOneByRedemptionTransaction($transaction);
        $isGiftCardRedemption = null !== $redeemedGiftCard;
        $isGiftCardPurchase = Transaction::CHARGE_METHOD_GIFT === $transaction->getChargeMethod() && !$isGiftCardRedemption;

        // Send appropriate email(s)
        if ($isGiftCardRedemption) {
            // 1) Recipient gets package activation/success email.
            $this->mailer->sendConfirmationEmail($event->getPackage(), $event->getTransaction(), $event->getUser());

            // 2) Purchaser gets notification that gift was redeemed.
            $purchaser = $redeemedGiftCard?->getPurchaserUser();
            if (null !== $purchaser && $purchaser->getId() !== $event->getUser()->getId()) {
                $this->mailer->sendGiftCardRedemptionConfirmationEmail($event->getPackage(), $event->getTransaction(), $purchaser);
            }
        } else {
            // Regular purchase and gift purchase: only purchase success email.
            $this->mailer->sendConfirmationEmail($event->getPackage(), $event->getTransaction(), $event->getUser());
        }

        try {
            if (!$transaction->isHaveSessionsAvailable()) {
                return;
            }

            $package     = $event->getPackage();
            $user        = $event->getUser();
            $packageLabel = $package->getAltText() ?? ($package->getTotalClasses() ? "{$package->getTotalClasses()} clases" : 'paquete');
            
            if ($isGiftCardPurchase) {
                // Gift card purchase notification (to the buyer)
                $this->notificationDispatcher->dispatch(
                    'payment_confirmed',
                    $user,
                    '¡Tu regalo está listo!',
                    sprintf('Compra registrada. Tu tarjeta de regalo para el paquete "%s" está lista para compartir.', $packageLabel),
                    ['resource_key' => 'tx_confirmed_' . $transaction->getId()],
                    Notification::PRIORITY_HIGH,
                );
            } elseif ($isGiftCardRedemption) {
                // Gift card redemption notification (to the recipient)
                $this->notificationDispatcher->dispatch(
                    'payment_confirmed',
                    $user,
                    '¡Código canjeado!',
                    sprintf('¡Felicidades! Tu paquete "%s" ha sido activado. Ya puedes reservar tus clases.', $packageLabel),
                    ['resource_key' => 'tx_confirmed_' . $transaction->getId()],
                    Notification::PRIORITY_HIGH,
                );
            } else {
                // Regular package purchase notification
                $this->notificationDispatcher->dispatch(
                    'payment_confirmed',
                    $user,
                    '¡Pago confirmado!',
                    sprintf('Tu paquete "%s" fue activado exitosamente. ¡Ya puedes reservar tus clases!', $packageLabel),
                    ['resource_key' => 'tx_confirmed_' . $transaction->getId()],
                    Notification::PRIORITY_HIGH,
                );
            }
        } catch (\Throwable) {}
    }
}
