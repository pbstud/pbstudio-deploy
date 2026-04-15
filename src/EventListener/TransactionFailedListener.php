<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Event\TransactionFailedEvent;
use App\Service\Mailer\TransactionMailer;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class TransactionFailedListener
{
    public function __construct(private TransactionMailer $mailer)
    {
    }

    public function __invoke(TransactionFailedEvent $event): void
    {
        $this->mailer->sendFailedPaymentEmail($event->getTransaction());
    }
}
