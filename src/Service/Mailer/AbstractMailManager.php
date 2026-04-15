<?php

declare(strict_types=1);

namespace App\Service\Mailer;

use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Mail Manager.
 */
abstract class AbstractMailManager
{
    /**
     * Abstract Mail Manager constructor.
     */
    public function __construct(
        protected MailerInterface $mailer,
        protected LoggerInterface $logger,
        protected string $defaultFromAddress = 'contacto@pbstudio.mx',
        protected string $defaultFromName = 'P&B Studio',
        protected string $forceToAddress = '',
        protected string $mailerTestTo = '',
    ) {
    }

    /**
     * @param string              $templateName
     * @param array               $context
     * @param string              $toEmail
     * @param string|Address|null $fromEmail
     * @param array               $attachments
     *
     * @throws TransportExceptionInterface
     */
    protected function sendMessage(string $templateName, array $context, string $toEmail, $fromEmail = null, array $attachments = []): void
    {
        $fromEmail = $fromEmail ?: new Address($this->defaultFromAddress, $this->defaultFromName);

        // For non-production checks and controlled testing, allow redirecting deliveries.
        if ('' !== trim($this->forceToAddress)) {
            $toEmail = trim($this->forceToAddress);
        } elseif ('' !== trim($this->mailerTestTo)) {
            $toEmail = trim($this->mailerTestTo);
        }

        $email = new TemplatedEmail();

        $email
            ->subject($context['subject'])
            ->from($fromEmail)
            ->to($toEmail)

            ->htmlTemplate($templateName)
            ->context($context)
        ;

        if (0 < count($attachments)) {
            foreach ($attachments as $attachment) {
                $email->attachFromPath($attachment);
            }
        }

        $this->mailer->send($email);
    }
}
