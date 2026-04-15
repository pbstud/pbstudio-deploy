<?php

declare(strict_types=1);

namespace App\Command;

use App\Event\TransactionExpiredEvent;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[AsCommand(
    name: 'app:transaction:checkexpiration',
    description: 'Cierre de las transacciones que ya expirarón.',
)]
class TransactionCheckExpirationCommand extends AbstractCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRepository $transactionRepository,
        private readonly EventDispatcherInterface $dispatcher,

    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->msg('Consultando transacciones...');

        $transactions = $this->transactionRepository->getExpired();
        $this->msgInfo(sprintf('Total de transacciones a expirar: %d', count($transactions)));
        $currentDate = new \DateTimeImmutable();

        foreach ($transactions as $transaction) {
            try {
                $this->msgInfo(sprintf(
                    ' - Expirando transacción: %d, email: %s',
                    $transaction->getId(),
                    $transaction->getUser()->getEmail(),
                ));

                $transaction
                    ->setIsExpired(true)
                    ->setExpiredAt($currentDate)
                ;

                $this->em->persist($transaction);
                $this->em->flush();

                $this->dispatcher->dispatch(new TransactionExpiredEvent($transaction));
            } catch (\Exception $e) {
                $this->msgError(sprintf(' - Error al procesar: %s', $e->getMessage()));
            }
        }

        $this->msg('Proceso finalizado.');

        return Command::SUCCESS;
    }
}
