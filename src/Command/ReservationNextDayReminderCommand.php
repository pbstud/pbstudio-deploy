<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Reservation;
use App\Repository\ReservationRepository;
use App\Service\Mailer\ReservationMailer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:reservation:send-next-day-reminders',
    description: 'Envia un solo correo diario por usuario con todas sus reservaciones del dia siguiente.',
)]
class ReservationNextDayReminderCommand extends AbstractCommand
{
    public function __construct(
        private readonly ReservationRepository $reservationRepository,
        private readonly ReservationMailer $reservationMailer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $targetDate = (new \DateTimeImmutable('tomorrow'))->setTime(0, 0, 0);

        $this->msg(sprintf('Consultando reservaciones para %s...', $targetDate->format('Y-m-d')));
        $reservations = $this->reservationRepository->getAvailableForDate($targetDate);
        $this->msgInfo(sprintf('Reservaciones encontradas: %d', count($reservations)));

        $groupedByUser = [];

        /** @var Reservation $reservation */
        foreach ($reservations as $reservation) {
            $user = $reservation->getUser();
            if (null === $user || empty($user->getEmail())) {
                continue;
            }

            $userId = (int) $user->getId();
            if (!isset($groupedByUser[$userId])) {
                $groupedByUser[$userId] = [
                    'user' => $user,
                    'reservations' => [],
                ];
            }

            $groupedByUser[$userId]['reservations'][] = $reservation;
        }

        $emailsSent = 0;

        foreach ($groupedByUser as $entry) {
            /** @var Reservation[] $userReservations */
            $userReservations = $entry['reservations'];

            usort(
                $userReservations,
                static fn (Reservation $a, Reservation $b): int => $a->getSession()->getTimeStart() <=> $b->getSession()->getTimeStart()
            );

            $this->reservationMailer->sendNextDayDigestEmail($entry['user'], $userReservations, $targetDate);
            ++$emailsSent;
        }

        $this->msgInfo(sprintf('Correos enviados: %d', $emailsSent));
        $this->msg('Proceso finalizado.');

        return Command::SUCCESS;
    }
}
