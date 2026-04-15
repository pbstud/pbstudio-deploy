<?php

declare(strict_types=1);

namespace App\Service\Mailer;

use App\Entity\Package;
use App\Entity\Transaction;
use App\Entity\User;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Transaction Mailer.
 */
class TransactionMailer extends AbstractMailManager
{
    /**
     * Envia un mail de confirmación al usuario.
     *
     * @param Package     $package
     * @param Transaction $transaction
     * @param User        $user
     */
    public function sendConfirmationEmail(Package $package, Transaction $transaction, User $user): void
    {
        $template = 'mail/transaction_confirmation.html.twig';

        $context = [
            'subject' => 'Tu pago ha sido procesado correctamente',
            'package' => $package,
            'transaction' => $transaction,
            'user' => $user,
        ];

        try {
            $this->sendMessage($template, $context, $user->getEmail());
        } catch (TransportExceptionInterface $e) {
            // Registrar error.
        }
    }

    /**
     * Envia un mail de notificación al expirar una transacción.
     *
     * @param Transaction $transaction
     */
    public function sendExpirationNotificationEmail(Transaction $transaction): void
    {
        $template = 'mail/transaction_expired.html.twig';

        /** @var User $user */
        $user = $transaction->getUser();

        $context = [
            'subject' => 'Tu transacción ha expirado',
            'transaction' => $transaction,
            'user' => $user,
        ];

        try {
            $this->sendMessage($template, $context, $user->getEmail());
        } catch (TransportExceptionInterface $e) {
            // Registrar error.
        }
    }

    public function sendPackageExpiration24hEmail(Transaction $transaction): void
    {
        $template = 'mail/transaction_expiration_24h.html.twig';

        /** @var User $user */
        $user = $transaction->getUser();

        $context = [
            'subject' => 'Tu paquete expira en 24 horas',
            'transaction' => $transaction,
            'package' => $transaction->getPackage(),
            'user' => $user,
        ];

        try {
            $this->sendMessage($template, $context, $user->getEmail());
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Error al enviar correo de expiración de paquete a 24h: '.$e->getMessage());
        }
    }

    public function sendFailedPaymentEmail(Transaction $transaction): void
    {
        $template = 'mail/transaction_failed.html.twig';

        /** @var User $user */
        $user = $transaction->getUser();

        $context = [
            'subject' => 'No pudimos procesar tu pago',
            'transaction' => $transaction,
            'user' => $user,
        ];

        try {
            $this->sendMessage($template, $context, (string) $user->getEmail());
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Error al enviar correo de pago fallido: '.$e->getMessage());
        }
    }
}
