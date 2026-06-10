<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Notification;
use App\Entity\Session;
use App\Repository\NotificationRepository;
use App\Repository\ReservationRepository;
use App\Repository\SessionRepository;
use App\Service\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:session:autoclosing',
    description: 'Cierre de las clases que ya pasaron su hora de inicio.',
)]
class SessionAutoClosingCommand extends AbstractCommand
{
    public function __construct(
        private readonly SessionRepository $sessionRepository,
        private readonly ReservationRepository $reservationRepository,
        private readonly NotificationRepository $notificationRepository,
        private readonly NotificationDispatcher $notificationDispatcher,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->msg('Consultando sesiones no cerradas...');
        $sessions = $this->sessionRepository->getNotClosed();
        $this->msgInfo(sprintf('Total de sesiones a cerrar: %d', count($sessions)));

        foreach ($sessions as $session) {
            $this->msgInfo(sprintf(' - Cerrar sesión: %d', $session->getId()));
            $session->setStatus(Session::STATUS_CLOSED);
            $this->em->persist($session);
            $this->em->flush();
            $this->dispatchRatingPendingNotifications($session);
        }

        $this->dispatchUpcomingSessionReminders();

        $this->msg('Proceso finalizado.');

        return Command::SUCCESS;
    }

    private function dispatchRatingPendingNotifications(Session $session): void
    {
        $reservations = $this->reservationRepository->getReservationsBySession($session);
        $notifiedUsers = [];

        foreach ($reservations as $reservation) {
            $user = $reservation->getUser();
            $userId = $user ? (int) $user->getId() : null;

            if (null !== $reservation->getRatingExercise()) {
                continue;
            }

            if (null === $user) {
                continue;
            }

            if (isset($notifiedUsers[$userId])) {
                continue;
            }

            $alreadySentToday = $this->notificationRepository->existsByTypeCreatedToday($user, 'rating_pending');

            if ($alreadySentToday) {
                continue;
            }

            $resourceKey = sprintf('rating_pending_general_u%d', $userId);

            try {
                $result = $this->notificationDispatcher->dispatch(
                    'rating_pending',
                    $user,
                    'Tienes clases por calificar',
                    'Ya finalizaste una o mas clases. Entra a tu perfil y completa tus calificaciones pendientes.',
                    [
                        'resource_key' => $resourceKey,
                    ],
                    Notification::PRIORITY_LOW,
                );

                if (null !== $result) {
                    $notifiedUsers[$userId] = true;
                }
            } catch (\Throwable $e) {
                $this->msgError(sprintf(
                    '   [notif]   ERROR al despachar: %s en %s:%d',
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                ));
            }
        }
    }

    private function dispatchUpcomingSessionReminders(): void
    {
        $now  = new \DateTimeImmutable();
        $from = $now; // desde ahora
        $to   = $now->modify('+135 minutes'); // hasta 2h15m

        $sessions = $this->sessionRepository->getSessionsStartingBetween($from, $to);
        $this->msgInfo(sprintf('[reminder]  Sesiones con inicio en ~2h: %d', count($sessions)));

        foreach ($sessions as $session) {
            $reservations = $this->reservationRepository->getReservationsBySession($session);

            foreach ($reservations as $reservation) {
                $user = $reservation->getUser();
                if (null === $user) {
                    continue;
                }

                $resourceKey = sprintf('session_reminder_%d_u%d', (int) $session->getId(), (int) $user->getId());

                if ($this->notificationRepository->existsByResourceKey($user, 'session_reminder', $resourceKey)) {
                    $this->msgInfo(sprintf('[reminder]  SKIP duplicado: sesión %d, usuario %d, resource_key=%s',
                        (int) $session->getId(), (int) $user->getId(), $resourceKey));
                    continue;
                }

                try {
                    $disciplineName = $session->getDiscipline()?->getName() ?? 'clase';
                    $sessionTime    = $session->getTimeStart()?->format('H:i') ?? '';
                    $this->notificationDispatcher->dispatch(
                        'session_reminder',
                        $user,
                        '¡Tienes una clase próxima!',
                        sprintf('Tu clase de %s comienza a las %s. ¡Prepárate!', $disciplineName, $sessionTime),
                        ['resource_key' => $resourceKey],
                        Notification::PRIORITY_MEDIUM,
                    );
                } catch (\Throwable $e) {
                    $this->msgError(sprintf('[reminder]  ERROR al despachar para sesión %d, usuario %d: %s', (int) $session->getId(), (int) $user->getId(), $e->getMessage()));
                }
            }
        }
    }
}
