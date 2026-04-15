<?php

declare(strict_types=1);

namespace App\Service\Mailer;

use App\Event\SecurityCredentialChangedEvent;
use App\Entity\User;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * User Mailer.
 */
class UserMailer extends AbstractMailManager
{
    /**
     * @param User $user
     */
    public function sendWelcomeEmail(User $user): void
    {
        $template = 'mail/welcome.html.twig';

        $context = [
            'subject' => 'Bienvenido a P&B Studio',
            'user' => $user,
        ];

        try {
            $this->sendMessage($template, $context, $user->getEmail());
            $this->logger->info('Correo de bienvenida enviado', [
                'userId' => $user->getId(),
                'email' => $user->getEmail(),
            ]);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Error al enviar correo de bienvenida', [
                'error' => $e->getMessage(),
                'userId' => $user->getId(),
                'email' => $user->getEmail(),
            ]);
        }
    }

    public function sendCredentialChangedAlertEmail(SecurityCredentialChangedEvent $event): void
    {
        $user = $event->getUser();

        $credentialLabel = match ($event->getType()) {
            SecurityCredentialChangedEvent::TYPE_EMAIL => 'Correo electrónico',
            SecurityCredentialChangedEvent::TYPE_PASSWORD => 'Contraseña',
            default => 'Credencial',
        };

        $context = [
            'subject' => 'Alerta de seguridad: cambio de credenciales',
            'user' => $user,
            'credentialLabel' => $credentialLabel,
            'oldValue' => $event->getOldValue(),
            'newValue' => $event->getNewValue(),
            'ip' => $event->getIp(),
            'userAgent' => $event->getUserAgent(),
        ];

        try {
            $this->sendMessage('mail/security_credential_changed.html.twig', $context, (string) $user->getEmail());
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Error al enviar alerta de cambio de credenciales', [
                'error' => $e->getMessage(),
                'userId' => $user->getId(),
                'email' => $user->getEmail(),
                'type' => $event->getType(),
            ]);
        }
    }
}
