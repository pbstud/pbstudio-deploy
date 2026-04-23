<?php

declare(strict_types=1);

namespace App\Service\Reservation;

use App\Entity\Notification;
use App\Entity\Reservation;
use App\Entity\Session;
use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\WaitingList;
use App\Event\ReservationChangedEvent;
use App\Event\ReservationCanceledEvent;
use App\Event\ReservationSuccessEvent;
use App\Repository\ReservationRepository;
use App\Repository\TransactionRepository;
use App\Service\Notification\NotificationDispatcher;
use App\Service\SessionTimeCancel\TimeToCancel;
use App\Service\WaitingList\WaitingListCancellationWindowService;
use App\Util\PackageSessionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NoResultException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Reservation Service.
 */
readonly class ReservationService
{
    public const CONSUMPTION_SOURCE_AUTO = 'auto';
    public const CONSUMPTION_SOURCE_UNLIMITED = 'unlimited';
    public const CONSUMPTION_SOURCE_PACKAGE = 'package';
    public const CONSUMPTION_SOURCE_TRANSACTION = 'transaction';

    public function __construct(
        private EntityManagerInterface $em,
        private EventDispatcherInterface $dispatcher,
        private TimeToCancel $sessionTimeToCancel,
        private WaitingListCancellationWindowService $waitingListCancellationWindowService,
        private AuthorizationCheckerInterface $authorizationChecker,
        private TransactionRepository $transactionRepository,
        private ReservationRepository $reservationRepository,
        private LoggerInterface $logger,
        private NotificationDispatcher $notificationDispatcher,
    ) {
    }

    /**
     * @param User             $user
     * @param Session          $session
     * @param int              $placeNumber
     * @param WaitingList|null $waitingList
     *
     * @return Reservation
     *
     * @throws ReservationException
     */
    public function reservate(
        User $user,
        Session $session,
        int $placeNumber,
        ?WaitingList $waitingList = null,
        string $consumptionSource = self::CONSUMPTION_SOURCE_AUTO,
    ): Reservation {

        $consumptionSource = $this->normalizeConsumptionSource($consumptionSource);

        if (1 > $placeNumber || $session->getExerciseRoomCapacity() < $placeNumber) {
            throw new ReservationException('El lugar seleccionado no es válido.');
        }

        // Validación: ¿El asiento ya está ocupado en esta sesión?
        $asientoOcupado = $this->reservationRepository->findOneBy([
            'session' => $session,
            'placeNumber' => $placeNumber,
            'isAvailable' => true,
        ]);
        if ($asientoOcupado) {
            // Si el asiento está ocupado por el mismo usuario, es duplicidad
            if ($asientoOcupado->getUser() && $asientoOcupado->getUser()->getId() === $user->getId()) {
                throw new ReservationException('Ya tienes reservada este asiento en esta sesión.');
            } else {
                throw new ReservationException('El asiento seleccionado ya está ocupado por otro usuario en esta sesión.');
            }
        }

        $availableCapacity = max(0, (int) $session->getAvailableCapacity());
        $activeReservationsForSession = $this->reservationRepository->getTotalReservationsForSession($session);
        if ($activeReservationsForSession >= $availableCapacity) {
            throw new ReservationException('La reservación no puede ser registrada porque la clase esta completa.');
        }

        $hasUserReservations = false;
        $isFullUnlimited = false;
        $hasEligibleUnlimitedForSession = false;

        $forcedPackageByUnlimitedInSession = false;

        try {
            $unlimitedContext = $this->getUnlimitedConsumptionContext($user, $session);
            $hasEligibleUnlimitedForSession = $unlimitedContext['hasEligibleUnlimitedForSession'];
            $hasUserReservations = $unlimitedContext['hasUserReservations'];
            $isFullUnlimited = $unlimitedContext['isFullUnlimited'];

            if (self::CONSUMPTION_SOURCE_UNLIMITED === $consumptionSource && $hasUserReservations) {
                // Si ya uso cupo ilimitado en esta sesion, la siguiente reserva debe consumirse de paquetes por clase.
                $consumptionSource = self::CONSUMPTION_SOURCE_PACKAGE;
                $forcedPackageByUnlimitedInSession = true;
            }

            if (self::CONSUMPTION_SOURCE_UNLIMITED === $consumptionSource && $isFullUnlimited) {
                throw new ReservationException('Tu paquete ilimitado ya alcanzo el limite diario. Puedes reservar con paquetes por clase activos.');
            }

            // Fuente especifica por ID de transaccion (ej. 'transaction:42')
            if (1 === preg_match('/^transaction:(\d+)$/', $consumptionSource, $matches)) {
                $txnId = (int) $matches[1];
                $specificTransaction = $this->transactionRepository->findTransactionByIdForUser($txnId, $user);
                if (null === $specificTransaction) {
                    throw new ReservationException('El paquete seleccionado no es válido o no está disponible.');
                }
                if ($specificTransaction->getExpirationAt() < $session->getDateStart()) {
                    throw new ReservationException('El paquete seleccionado ya venció para la fecha de esta clase.');
                }
                $transaction = $specificTransaction;
            } else {
                $excludeUnlimited = self::CONSUMPTION_SOURCE_PACKAGE === $consumptionSource
                    || (self::CONSUMPTION_SOURCE_AUTO === $consumptionSource && ($hasUserReservations || $isFullUnlimited));

                $onlyUnlimited = self::CONSUMPTION_SOURCE_UNLIMITED === $consumptionSource;

                $transaction = $this->findTransactionForSession(
                    $user,
                    $session,
                    $excludeUnlimited,
                    $onlyUnlimited ? true : null
                );
            }
        } catch (NoResultException $e) {
            if ($forcedPackageByUnlimitedInSession) {
                $msg = 'Ya usaste tu cupo ilimitado en esta sesion. Necesitas un paquete por clase activo y compatible para esta reserva.';
            } elseif (self::CONSUMPTION_SOURCE_PACKAGE === $consumptionSource) {
                $msg = 'No tienes paquetes por clase activos y compatibles para esta clase.';
            } elseif (self::CONSUMPTION_SOURCE_UNLIMITED === $consumptionSource) {
                $msg = 'No tienes un paquete ilimitado activo y compatible para esta clase.';
            } elseif ($isFullUnlimited) {
                $msg = '¡No cuentas con clases disponibles! Sólo puedes reservar 2 clases por día con paquetes ilimitados.';
            } elseif ($hasUserReservations) {
                $msg = '¡No cuentas con clases disponibles! Sólo puedes reservar 1 lugar en la misma clase con paquetes ilimitados.';
            } else {
                $msg = 'No cuentas con clases disponibles o la fecha de la reservación es superior a la expiración de tu transacción.';
            }

            throw new ReservationException($msg);
        }

        /** @var Transaction $transaction */

        if (!$transaction->isPackageIsUnlimited()) {
            // Verifica si hay que deshabilitar la transacción porque se ocuparon todas las clases.
            $totalReservationsForTransaction = $this->reservationRepository->getTotalReservationsForTransaction($transaction) + 1;

            if ($totalReservationsForTransaction >= $transaction->getPackageTotalClasses()) {
                $transaction->setHaveSessionsAvailable(false);

                $this->em->persist($transaction);

                if ($totalReservationsForTransaction > $transaction->getPackageTotalClasses()) {
                    $this->em->flush();

                    throw new ReservationException('No cuentas con clases disponibles o la fecha de la reservación es superior a la expiración de tu transacción.');
                }
            }
        }

        $reservation = new Reservation();
        $reservation
            ->setUser($user)
            ->setTransaction($transaction)
            ->setSession($session)
            ->setPlaceNumber($placeNumber)
        ;

        $this->em->persist($reservation);
        $this->em->flush();

        $this->syncSessionAvailabilityStatus($session);
        $this->em->flush();

        try {
            $sessionDate    = $session->getDateStart()?->format('d/m/Y') ?? '';
            $disciplineName = $session->getDiscipline()?->getName() ?? 'clase';
            if (null !== $waitingList) {
                $this->notificationDispatcher->dispatch(
                    'waiting_list_promoted',
                    $user,
                    '¡Conseguiste un lugar!',
                    sprintf('Se liberó un lugar en %s el %s y lo reservamos para ti desde tu lista de espera.', $disciplineName, $sessionDate),
                    ['resource_key' => 'wl_promoted_' . $reservation->getId()],
                    Notification::PRIORITY_HIGH,
                );
            } else {
                $this->notificationDispatcher->dispatch(
                    'reservation_confirmed',
                    $user,
                    '¡Reservación confirmada!',
                    sprintf('Tu lugar en %s el %s fue reservado exitosamente.', $disciplineName, $sessionDate),
                    ['resource_key' => 'reservation_' . $reservation->getId()],
                    Notification::PRIORITY_HIGH,
                );
            }
        } catch (\Throwable) {}

        $event = new ReservationSuccessEvent($reservation);
        if ($waitingList instanceof WaitingList) {
            $event->setWaitingList($waitingList);
        }

        $this->dispatcher->dispatch($event);

        return $reservation;
    }

    /**
    * Provee opciones de consumo para que UI pueda habilitar la eleccion entre ilimitado y paquetes por clase.
     *
    * @return array{canUseUnlimited: bool, canUsePackage: bool, hasBothSources: bool, unlimitedBlockedReason: ?string, forcedPackageNotice: ?string, unlimitedDailyLimitNotice: ?string}
     */
    public function getReservationConsumptionOptions(User $user, Session $session): array
    {
        $unlimitedContext = $this->getUnlimitedConsumptionContext($user, $session);
        $hasUserReservations = $unlimitedContext['hasUserReservations'];
        $hasEligibleUnlimitedForSession = $unlimitedContext['hasEligibleUnlimitedForSession'];
        $isFullUnlimited = $unlimitedContext['isFullUnlimited'];

        $canUseUnlimited = false;
        $unlimitedBlockedReason = null;

        if (!$hasEligibleUnlimitedForSession) {
            $unlimitedBlockedReason = null;
        } elseif ($hasUserReservations) {
            $unlimitedBlockedReason = 'Ya tienes una reserva ilimitada en esta sesion.';
        } elseif ($isFullUnlimited) {
            $unlimitedBlockedReason = 'Tu paquete ilimitado ya alcanzo el limite diario.';
        } else {
            try {
                $this->findTransactionForSession($user, $session, false, true);
                $canUseUnlimited = true;
            } catch (NoResultException) {
                $unlimitedBlockedReason = 'No tienes paquete ilimitado elegible para esta clase.';
            }
        }

        $canUsePackage = false;
        $regularTransactions = [];
        try {
            $this->findTransactionForSession($user, $session, true, null);
            $canUsePackage = true;
            // Cargar todas las transacciones regulares elegibles para el selector
            $packageType = $session->isIndividual() ? PackageSessionType::TYPE_INDIVIDUAL : PackageSessionType::TYPE_GROUP;
            $regularTransactions = $this->transactionRepository->findAllTransactionsAvailableByUserAndExpirationAt(
                $user,
                $session->getDateStart(),
                $packageType,
                false,
                true,
            );
        } catch (NoResultException) {
        }

        $forcedPackageNotice = null;
        if ($hasEligibleUnlimitedForSession && $hasUserReservations && $canUsePackage) {
            $forcedPackageNotice = 'Ya usaste cupo ilimitado en esta sesion. Esta nueva reserva se consumira de tus paquetes por clase activos.';
        }

        $unlimitedDailyLimitNotice = null;
        if ($hasEligibleUnlimitedForSession && $isFullUnlimited && $canUsePackage) {
            $unlimitedDailyLimitNotice = 'Por hoy ya consumiste tus 2 cupos de ilimitado. Esta reserva se consumira de tus paquetes activos por clase.';
        }

        // Construir lista de opciones para el selector de paquetes
        $availableTransactions = [];
        if ($canUseUnlimited) {
            $availableTransactions[] = [
                'value' => self::CONSUMPTION_SOURCE_UNLIMITED,
                'label' => 'Paquete ilimitado',
                'isUnlimited' => true,
            ];
        }
        foreach ($regularTransactions as $txn) {
            $availableTransactions[] = [
                'value' => 'transaction:' . $txn->getId(),
                'label' => ($txn->getPackageTotalClasses() ?? '?') . ' clases — Vence ' . ($txn->getExpirationAt()?->format('d/m/Y') ?? 'N/A'),
                'isUnlimited' => false,
            ];
        }

        return [
            'canUseUnlimited' => $canUseUnlimited,
            'canUsePackage' => $canUsePackage,
            'hasBothSources' => $canUseUnlimited && $canUsePackage,
            'availableTransactions' => $availableTransactions,
            'unlimitedBlockedReason' => $unlimitedBlockedReason,
            'forcedPackageNotice' => $forcedPackageNotice,
            'unlimitedDailyLimitNotice' => $unlimitedDailyLimitNotice,
        ];
    }

    /**
     * @return array{hasUserReservations: bool, hasEligibleUnlimitedForSession: bool, isFullUnlimited: bool}
     */
    public function getUnlimitedConsumptionContext(User $user, Session $session): array
    {
        $hasUserReservations = $this->reservationRepository->hasUnlimitedReservationsByUserSession($user, $session);

        $hasEligibleUnlimitedForSession = false;
        try {
            $this->findTransactionForSession($user, $session, false, true);
            $hasEligibleUnlimitedForSession = true;
        } catch (NoResultException) {
        }

        $sessionPackageType = $session->isIndividual()
            ? PackageSessionType::TYPE_INDIVIDUAL
            : PackageSessionType::TYPE_GROUP;

        $totalUnlimited = $hasEligibleUnlimitedForSession
            ? $this->reservationRepository->getUnlimitedReservationsByUserAndPackageType($user, $session->getDateStart(), $sessionPackageType)
            : 0;

        return [
            'hasUserReservations' => $hasUserReservations,
            'hasEligibleUnlimitedForSession' => $hasEligibleUnlimitedForSession,
            'isFullUnlimited' => $hasEligibleUnlimitedForSession && $totalUnlimited >= Reservation::MAX_UNLIMITED_RESERVATIONS,
        ];
    }

    /**
     * @param Reservation $reservation
     *
     * @throws ReservationException
     */
    public function cancel(
        Reservation $reservation,
        string $source = ReservationCanceledEvent::SOURCE_SYSTEM,
    ): void
    {
        if (!$reservation->isIsAvailable()) {
            throw new ReservationException('La reservación ya ha sido cancelada.');
        }

        /** @var Session $session */
        $session = $reservation->getSession();

        /** @var Transaction $transaction */
        $transaction = $reservation->getTransaction();
        if ($transaction->isIsExpired()) {
            throw new ReservationException('No puedes cancelar la clase porque el paquete ha expirado.');
        }

        if ($this->authorizationChecker->isGranted('ROLE_USER')) {
            if ($reservation->getChangedAt() !== null) {
                throw new ReservationException('Esta reservación ya fue cambiada y no permite cancelación.');
            }

            if (Session::STATUS_CLOSED === $session->getStatus()) {
                throw new ReservationException('La clase ya ha sido tomada y no se puede cancelar.');
            }

            $isWithinWaitingListGraceWindow = $this->waitingListCancellationWindowService->canCancelWithoutPenalty($reservation);
            if (!$isWithinWaitingListGraceWindow && !$this->canCancel($reservation)) {
                $timeToCancel = $session->isIndividual() ?
                    $this->sessionTimeToCancel->getTimeToCancelIndividual() :
                    $this->sessionTimeToCancel->getTimeToCancelGroup()
                ;

                throw new ReservationException(sprintf('Recuerda cancelar máximo %s horas antes de la clase reservada.', $timeToCancel->toHours()));
            }
        }

        $reservation
            ->setIsAvailable(false)
            ->setCancellationAt(new \DateTime())
        ;

        $transaction->setHaveSessionsAvailable(true);

        $this->em->flush();

        $this->syncSessionAvailabilityStatus($session);
        $this->em->flush();

        try {
            $sessionDate    = $session->getDateStart()?->format('d/m/Y') ?? '';
            $disciplineName = $session->getDiscipline()?->getName() ?? 'clase';
            $reservationUser = $reservation->getUser();
            if (null !== $reservationUser) {
                [$notifTitle, $notifBody] = match ($source) {
                    ReservationCanceledEvent::SOURCE_USER => [
                        'Reservación cancelada',
                        sprintf('Cancelaste tu lugar en %s el %s. Tu crédito fue reintegrado.', $disciplineName, $sessionDate),
                    ],
                    ReservationCanceledEvent::SOURCE_STAFF => [
                        'Tu reservación fue cancelada',
                        sprintf('Tu lugar en %s el %s fue cancelado por nuestro equipo. Si tienes dudas, contáctanos.', $disciplineName, $sessionDate),
                    ],
                    default => [
                        'Reservación cancelada',
                        sprintf('Tu lugar en %s el %s fue cancelado. Tu crédito ha sido reintegrado.', $disciplineName, $sessionDate),
                    ],
                };
                $this->notificationDispatcher->dispatch(
                    'reservation_cancelled',
                    $reservationUser,
                    $notifTitle,
                    $notifBody,
                    ['resource_key' => 'reservation_cancel_' . $reservation->getId()],
                    Notification::PRIORITY_MEDIUM,
                );
            }
        } catch (\Throwable) {}

        $event = new ReservationCanceledEvent($reservation, $source);
        $this->dispatcher->dispatch($event);
    }

    public function canCancel(Reservation $reservation): bool
    {
        if ($reservation->getChangedAt() !== null) {
            return false;
        }

        $session = $reservation->getSession();
        $secondsToStart = $this->getSecondsToStart($session);

        if ($secondsToStart <= 0) {
            return false;
        }

        $timeToCancel = $session->isIndividual() ?
            $this->sessionTimeToCancel->getTimeToCancelIndividual() :
            $this->sessionTimeToCancel->getTimeToCancelGroup()
        ;

        return $secondsToStart > $timeToCancel->toSeconds();
    }

    public function canChange(Reservation $reservation): bool
    {
        if ($reservation->getChangedAt()) {
            return false;
        }

        $secondsToStart = $this->getSecondsToStart($reservation->getSession());

        if ($secondsToStart <= 0) {
            return false;
        }

        return Session::TIME_CHANGE_FROM_SECONDS >= $secondsToStart
            && Session::TIME_CHANGE_TO_SECONDS <= $secondsToStart;
    }

    public function change(
        Reservation $reservation,
        Session $session,
        int $placeNumber,
    ): Reservation {

        // Validate target session is within the original package validity period
        $transaction = $reservation->getTransaction();
        if ($transaction !== null && $transaction->getExpirationAt() !== null) {
            $targetDate = $session->getDateStart();
            if ($targetDate !== null && $targetDate > $transaction->getExpirationAt()) {
                throw new ReservationException(
                    sprintf(
                        'La sesión seleccionada está fuera del período de validez de tu paquete (vence el %s).',
                        $transaction->getExpirationAt()->format('d/m/Y')
                    )
                );
            }
        }

        if (1 > $placeNumber || $session->getExerciseRoomCapacity() < $placeNumber) {
            throw new ReservationException('El lugar seleccionado no es válido.');
        }

        // Validación: ¿El asiento destino está ocupado por otro usuario?
        $asientoOcupado = $this->reservationRepository->findOneBy([
            'session' => $session,
            'placeNumber' => $placeNumber,
            'isAvailable' => true,
        ]);
        if ($asientoOcupado && $asientoOcupado->getUser()->getId() !== $reservation->getUser()->getId()) {
            throw new ReservationException('El asiento destino ya está ocupado por otro usuario en esta sesión.');
        }

        // Validación: ¿El usuario ya tiene ese asiento reservado en la sesión destino?
        $misAsientos = $this->reservationRepository->findOneBy([
            'session' => $session,
            'placeNumber' => $placeNumber,
            'user' => $reservation->getUser(),
            'isAvailable' => true,
        ]);
        if ($misAsientos && $misAsientos->getId() !== $reservation->getId()) {
            throw new ReservationException('Ya tienes reservado este asiento en la sesión destino.');
        }

        $targetSessionReservations = $this->reservationRepository->getTotalReservationsForSession($session);
        $targetAvailableCapacity = max(0, (int) $session->getAvailableCapacity());

        $sourceSession = $reservation->getSession();
        $sourcePlace = (int) ($reservation->getPlaceNumber() ?? 0);
        $isSameSession = $sourceSession?->getId() === $session->getId();

        if ($targetSessionReservations > $targetAvailableCapacity || (!$isSameSession && $targetSessionReservations >= $targetAvailableCapacity)) {
            throw new ReservationException('La reservación no puede ser registrada porque la clase esta completa.');
        }

        $reservation
            ->setSession($session)
            ->setPlaceNumber($placeNumber)
            ->setChangedAt(new \DateTime())
        ;

        $this->em->persist($reservation);
        $this->em->flush();

        $this->syncSessionAvailabilityStatus($session);
        if ($sourceSession !== null && $sourceSession->getId() !== $session->getId()) {
            $this->syncSessionAvailabilityStatus($sourceSession);
        }
        $this->em->flush();

        if ($sourceSession !== null && $sourcePlace > 0) {
            $event = new ReservationChangedEvent($reservation, $sourceSession, $sourcePlace, $session, $placeNumber);
            $this->dispatcher->dispatch($event);
        }

        return $reservation;
    }

    private function syncSessionAvailabilityStatus(Session $session): void
    {
        $status = $session->getStatus();
        if (!in_array($status, [Session::STATUS_OPEN, Session::STATUS_FULL], true)) {
            return;
        }

        $availableCapacity = max(0, (int) $session->getAvailableCapacity());
        $activeReservations = $this->reservationRepository->getTotalReservationsForSession($session);

        if ($activeReservations >= $availableCapacity) {
            $session->setStatus(Session::STATUS_FULL);

            return;
        }

        $session->setStatus(Session::STATUS_OPEN);
    }

    private function getSecondsToStart(Session $session): int
    {
        $dateStartValue = $session->getDateStart();
        $timeStartValue = $session->getTimeStart();

        if (!$dateStartValue || !$timeStartValue) {
            throw new \RuntimeException('La sesión no tiene fecha u hora de inicio configurada.');
        }

        $currentDate = new \DateTimeImmutable();
        $dateStart = \DateTimeImmutable::createFromInterface($dateStartValue);
        $dateStart = $dateStart->setTime(
            (int) $timeStartValue->format('H'),
            (int) $timeStartValue->format('i')
        );

        return $dateStart->getTimestamp() - $currentDate->getTimestamp();
    }

    private function normalizeConsumptionSource(string $consumptionSource): string
    {
        $normalized = strtolower(trim($consumptionSource));

        if (in_array($normalized, [
            self::CONSUMPTION_SOURCE_AUTO,
            self::CONSUMPTION_SOURCE_UNLIMITED,
            self::CONSUMPTION_SOURCE_PACKAGE,
        ], true)) {
            return $normalized;
        }

        // Formato 'transaction:{id}' — fuente especifica por ID de transaccion
        if (1 === preg_match('/^transaction:\d+$/', $normalized)) {
            return $normalized;
        }

        return self::CONSUMPTION_SOURCE_AUTO;
    }

    private function findTransactionForSession(
        User $user,
        Session $session,
        bool $excludeUnlimited,
        ?bool $onlyUnlimited,
    ): Transaction {
        if ($session->isIndividual()) {
            return $this->transactionRepository->findFirstTransactionIndividualAvailableByUserAndExpirationAt(
                $user,
                $session->getDateStart(),
                $excludeUnlimited,
                $onlyUnlimited
            );
        }

        return $this->transactionRepository->findFirstTransactionGroupAvailableByUserAndExpirationAt(
            $user,
            $session->getDateStart(),
            $excludeUnlimited,
            $onlyUnlimited
        );
    }
}
