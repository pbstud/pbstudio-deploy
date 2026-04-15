<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Event\SecurityCredentialChangedEvent;
use App\Service\Mailer\UserMailer;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class SecurityCredentialChangedListener
{
    public function __construct(private UserMailer $userMailer)
    {
    }

    public function __invoke(SecurityCredentialChangedEvent $event): void
    {
        $this->userMailer->sendCredentialChangedAlertEmail($event);
    }
}
