<?php

declare(strict_types=1);

namespace App\Service\WaitingList;

use App\Entity\Notification;
use App\Entity\Session;
use App\Entity\User;
use App\Entity\WaitingList;
use App\Event\WaitingListSuccessEvent;
use App\Repository\ReservationRepository;
use App\Repository\TransactionRepository;
use App\Repository\WaitingListRepository;
use App\Service\Mailer\WaitingListMailer;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Reservation\ReservationException;
use App\Service\Reservation\ReservationService;
use App\Service\SessionTimeCancel\TimeToCancel;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NoResultException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Waiting List Service.
 */
readonly class WaitingListService
{
    /**
     * WaitingListService constructor.
     */
    public function __construct(
        private EntityManagerInterface $em,
        private EventDispatcherInterface $dispatcher,
        private TimeToCancel $sessionTimeToCancel,
        private ReservationService $reservationService,
        private WaitingListRepository $waitingListRepository,
        private TransactionRepository $transactionRepository,
        private ReservationRepository $reservationRepository,
        private WaitingListMailer $waitingListMailer,
        private LoggerInterface $logger,
        private NotificationDispatcher $notificationDispatcher,
    ) {
    }

    /**
     * @param User    $user
     * @param Session $session
     *
     * @throws WaitingListException
     */
    public function add(User $user, Session $session): void
    {
        try {
            $waitingList = $this->waitingListRepository->findOneBy([
                'user' => $user,
                'session' => $session,
            ]);

            if ($waitingList instanceof WaitingList) {
                if ($waitingList->isIsAvailable()) {
                    throw new WaitingListException('Ya te encuentras en la lista de espera de esta clase.');
                }

                // Si existe registro previo inactivo, primero valida reglas de negocio y luego reactiva.
                $this->validate($user, $session);

                $waitingList
                    ->setIsAvailable(true)
                    ->setError(null)
                ;
            } else {
                $this->validate($user, $session);

                $waitingList = new WaitingList();
                $waitingList
                    ->setUser($user)
                    ->setSession($session)
                ;
            }

            $this->em->persist($waitingList);
            $this->em->flush();

            try {
                $sessionDate    = $session->getDateStart()?->format('d/m/Y') ?? '';
                $disciplineName = $session->getDiscipline()?->getName() ?? 'clase';
                $this->notificationDispatcher->dispatch(
                    'waiting_list_confirmed',
                    $user,
                    'En lista de espera',
                    sprintf('Te registramos en la lista de espera de %s el %s. Te avisaremos si se libera un lugar.', $disciplineName, $sessionDate),
                    ['resource_key' => sprintf('wl_%d_%d', (int) $user->getId(), (int) $session->getId())],
                    Notification::PRIORITY_MEDIUM,
                );
            } catch (\Throwable) {}

            $event = new WaitingListSuccessEvent($waitingList);
            $this->dispatcher->dispatch($event);
        } catch (WaitingListException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException) {
            // Concurrencia/reintentos: si otra solicitud creó/reactivó primero, respondemos de forma idempotente.
            throw new WaitingListException('Ya te encuentras en la lista de espera de esta clase.');
        } catch (\Exception $e) {
            throw new WaitingListException('Ha ocurrido un error en el registro.');
        }
    }

    public function checkAndReserve(Session $session, int $placeNumber): void
    {
        $waitingLists = $this->waitingListRepository->getAvailableBySession($session);

        foreach ($waitingLists as $waitingList) {
            $waitingList->setIsAvailable(false);

            try {
                $user = $waitingList->getUser();
                $this->reservationService->reservate($user, $session, $placeNumber, $waitingList);
                $this->em->flush();

                $this->logger->info('Waiting list promotion successful', [
                    'session_id' => $session->getId(),
                    'user_id' => $user?->getId(),
                    'place_number' => $placeNumber,
                ]);

                break;
            } catch (ReservationException $e) {
                $reason = $e->getMessage();

                $waitingList->setError($reason);

                $this->em->flush();

                $this->waitingListMailer->sendPromotionDeniedEmail($waitingList, $reason);

                $user = $waitingList->getUser();
                if ($user !== null) {
                    $disciplineName = $session->getDiscipline()?->getName() ?? 'la clase';
                    $sessionDate    = $session->getDateStart()?->format('d/m/Y') ?? '';
                    try {
                        $this->notificationDispatcher->dispatch(
                            'waiting_list_denied',
                            $user,
                            'No pudimos reservarte',
                            sprintf('No logramos asignarte en %s del %s: %s', $disciplineName, $sessionDate, $reason),
                            ['resource_key' => sprintf('wl_denied_%d_%d', (int) $user->getId(), (int) $session->getId())],
                            Notification::PRIORITY_HIGH,
                        );
                    } catch (\Throwable) {}
                }

                $this->logger->warning('Waiting list promotion denied', [
                    'session_id' => $session->getId(),
                    'user_id' => $waitingList->getUser()?->getId(),
                    'place_number' => $placeNumber,
                    'reason' => $reason,
                ]);
            }
        }
    }

    /**
     * Intenta promover usuarios de lista de espera a todos los asientos libres.
     *
     * @return int Total de promociones exitosas
     */
    public function promoteAvailablePlaces(Session $session): int
    {
        if (!in_array($session->getStatus(), [Session::STATUS_OPEN, Session::STATUS_FULL], true)) {
            return 0;
        }

        if ([] === $this->waitingListRepository->getAvailableBySession($session)) {
            return 0;
        }

        $capacity = (int) ($session->getExerciseRoomCapacity() ?? 0);
        if ($capacity <= 0) {
            return 0;
        }

        $notAvailable = array_fill_keys(
            array_map('intval', $session->getPlacesNotAvailable() ?? []),
            true
        );

        $reservedPlaces = [];
        foreach ($this->reservationRepository->getReservationsBySession($session) as $reservation) {
            $placeNumber = $reservation->getPlaceNumber();
            if (null !== $placeNumber) {
                $reservedPlaces[(int) $placeNumber] = true;
            }
        }

        $promoted = 0;
        for ($place = 1; $place <= $capacity; ++$place) {
            if (isset($notAvailable[$place]) || isset($reservedPlaces[$place])) {
                continue;
            }

            $this->checkAndReserve($session, $place);

            $isTaken = $this->reservationRepository->findOneBy([
                'session' => $session,
                'placeNumber' => $place,
                'isAvailable' => true,
            ]);

            if (null === $isTaken) {
                continue;
            }

            $reservedPlaces[$place] = true;
            ++$promoted;
        }

        return $promoted;
    }

    /**
     * Valida que se pueda registrar en la lista de espera.
     *
     * @param User    $user
     * @param Session $session
     *
     * @throws WaitingListException
     */
    public function validate(User $user, Session $session): void
    {
        if (Session::STATUS_FULL !== $session->getStatus()) {
            throw new WaitingListException('La clase aún cuenta con lugares disponibles.', 1000);
        }

        // Regla de negocio: la lista de espera cierra 2 horas antes de la clase.
        $currentDate = new \DateTime();

        $dateStartValue = $session->getDateStart();
        $timeStartValue = $session->getTimeStart();
        if (!$dateStartValue || !$timeStartValue) {
            throw new WaitingListException('La clase no tiene fecha u hora válida para lista de espera.');
        }

        $dateStart = \DateTimeImmutable::createFromInterface($dateStartValue)
            ->setTime((int) $timeStartValue->format('H'), (int) $timeStartValue->format('i'));

        $diffSeconds = $dateStart->getTimestamp() - $currentDate->getTimestamp();

        $waitingListCutoffSeconds = 2 * 60 * 60;
        if ($diffSeconds <= $waitingListCutoffSeconds) {
            throw new WaitingListException('El registro en la lista de espera cierra 2 horas antes de la clase.', 1010);
        }

        try {
            $unlimitedContext = $this->reservationService->getUnlimitedConsumptionContext($user, $session);
            $hasUserReservations = $unlimitedContext['hasUserReservations'];
            $hasEligibleUnlimitedForSession = $unlimitedContext['hasEligibleUnlimitedForSession'];
            $isFullUnlimited = $unlimitedContext['isFullUnlimited'];

            $excludeUnlimited = $hasEligibleUnlimitedForSession && ($hasUserReservations || $isFullUnlimited);

            if ($session->isIndividual()) {
                $this->transactionRepository->findFirstTransactionIndividualAvailableByUserAndExpirationAt(
                    $user,
                    $session->getDateStart(),
                    $excludeUnlimited
                );
            } else {
                $this->transactionRepository->findFirstTransactionGroupAvailableByUserAndExpirationAt(
                    $user,
                    $session->getDateStart(),
                    $excludeUnlimited
                );
            }
        } catch (NoResultException $e) {
            throw new WaitingListException('¡No cuentas con clases disponibles! Pero no te preocupes, adquiere más clases para poder realizar la reservación.', 1020);
        }
    }

    /**
     * Busca un usuario dentreo de la lista de espera de la clase.
     *
     * @param Session $session
     * @param User    $user
     *
     * @return WaitingList|null
     */
    public function findUser(Session $session, User $user): ?WaitingList
    {
        return $this->waitingListRepository->findOneBy([
            'user' => $user,
            'session' => $session,
            'isAvailable' => true,
        ]);
    }
}
