<?php

declare(strict_types=1);

namespace App\Service\Mailer;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Resetting Mailer.
 *
 * @author JCHR <car.chr@gmail.com>
 */
class ResettingMailer extends AbstractMailManager
{
    /**
     * ResettingMailer constructor.
     */
    public function __construct(
        MailerInterface $mailer,
        LoggerInterface $logger,
        private readonly UrlGeneratorInterface $urlGenerator
    ) {
        parent::__construct($mailer, $logger);
    }

    /**
     * @param User $user
     */
    public function sendResetting(User $user): void
    {
        $template = 'mail/password_resetting.html.twig';
        $url = $this->urlGenerator->generate('resetting_reset', [
            'token' => $user->getConfirmationToken(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $context = [
            'subject' => 'Restablecer Contraseña',
            'user' => $user,
            'confirmationUrl' => $url,
        ];

        try {
            $this->sendMessage($template, $context, $user->getEmail());
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Error al enviar correo de reseteo de contraseña', [
                'error' => $e->getMessage(),
                'userId' => $user->getId(),
                'email' => $user->getEmail(),
            ]);
        }
    }
}
