<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Notification;
use App\Repository\TransactionRepository;
use App\Service\Mailer\TransactionMailer;
use App\Service\Notification\NotificationDispatcher;
use App\Repository\NotificationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:transaction:send-expiration-24h-reminders',
    description: 'Envia avisos de paquete proximo a expirar exactamente en la ventana de 24 horas.',
)]
class TransactionExpiration24hReminderCommand extends AbstractCommand
{
    public function __construct(
        private readonly TransactionRepository $transactionRepository,
        private readonly TransactionMailer $transactionMailer,
        private readonly NotificationDispatcher $notificationDispatcher,
        private readonly NotificationRepository $notificationRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Ventana diaria: si el cron corre una vez al dia, este filtro avisa sobre expiraciones del dia siguiente.
        $start = (new \DateTimeImmutable('tomorrow'))->setTime(0, 0, 0);
        $end = (new \DateTimeImmutable('tomorrow'))->setTime(23, 59, 59);

        $this->msg(sprintf('Consultando paquetes por expirar entre %s y %s...', $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')));

        $transactions = $this->transactionRepository->getExpiringInWindow($start, $end);
        $this->msgInfo(sprintf('Transacciones a notificar: %d', count($transactions)));

        $emailsSent = 0;
        foreach ($transactions as $transaction) {
            $user = $transaction->getUser();
            if (null === $user || empty($user->getEmail())) {
                continue;
            }

            $this->transactionMailer->sendPackageExpiration24hEmail($transaction);
            ++$emailsSent;

                $resourceKey = sprintf('tx_expiring_%d', (int) $transaction->getId());
            if (!$this->notificationRepository->existsByResourceKey($user, 'transaction_expiring_soon', $resourceKey)) {
                $packageLabel = $transaction->getPackage()?->getAltText()
                    ?? sprintf('%d clases', (int) $transaction->getPackageTotalClasses());
                try {
                    $this->notificationDispatcher->dispatch(
                        'transaction_expiring_soon',
                        $user,
                        'Tu paquete vence mañana',
                        sprintf('Tu paquete "%s" expira mañana. ¡Úsalo antes de que caduque!', $packageLabel),
                        ['resource_key' => $resourceKey],
                        Notification::PRIORITY_MEDIUM,
                    );
                } catch (\Throwable) {}
            }
        }

        $this->msgInfo(sprintf('Correos enviados: %d', $emailsSent));
        $this->msg('Proceso finalizado.');

        return Command::SUCCESS;
    }
}
