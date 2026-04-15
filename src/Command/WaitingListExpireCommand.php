<?php

declare(strict_types=1);

namespace App\Command;

use App\Event\WaitingListExpiredEvent;
use App\Repository\WaitingListRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:waiting-list:expire',
    description: 'Expirar lista de espera',
)]
class WaitingListExpireCommand extends AbstractCommand
{
    public function __construct(
        private readonly WaitingListRepository $waitingListRepository,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->msg('Consultando lista de espera a expirar...');
        $waitingLists = $this->waitingListRepository->getAvailableForExpire();
        $this->msgInfo(sprintf('Total de lista de espera a expirar: %d', count($waitingLists)));

        foreach ($waitingLists as $waitingList) {
            $this->msgInfo(sprintf(
                ' - Expirando Clase: %d, Usuario: %d',
                $waitingList->getSession()->getId(),
                $waitingList->getUser()->getId()
            ));

            $waitingList
                ->setIsAvailable(false)
                ->setError('Expirada 12 horas antes de inicio de clase.')
            ;

            $this->em->persist($waitingList);
            $this->em->flush();

            $this->eventDispatcher->dispatch(new WaitingListExpiredEvent($waitingList));
        }

        $this->msg('Proceso finalizado.');

        return Command::SUCCESS;
    }
}
