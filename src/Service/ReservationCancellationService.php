<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Reservation;
use App\Entity\Session;
use App\Entity\SessionAudit;
use App\Event\ReservationCanceledEvent;
use App\Service\Mailer\ReservationMailer;
use App\Service\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Servicio para cancelar reservaciones y devolver créditos cuando
 * un admin deshabilita asientos con reservaciones existentes.
 */
class ReservationCancellationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly ReservationMailer $reservationMailer,
        private readonly NotificationDispatcher $notificationDispatcher,
    ) {
    }

    /**
     * Cancela UNA reservación y devuelve crédito a la transacción.
     *
     * @param Reservation $reservation La reservación a cancelar
     *
     * @return bool true si se canceló exitosamente, false si ya estaba cancelada
     *
     * @throws \LogicException Si la reservación no tiene Transaction asociada
     */
    public function cancelAndRefund(Reservation $reservation): bool
    {
        // Validar que la reservación está activa
        if (!$reservation->isIsAvailable()) {
            $this->logger->warning('Intento de cancelar reservación ya cancelada', [
                'reservation_id' => $reservation->getId(),
            ]);

            return false;
        }

        // Validar que tiene Transaction
        $transaction = $reservation->getTransaction();
        if ($transaction === null) {
            throw new \LogicException(sprintf(
                'La reservación ID %d no tiene Transaction asociada',
                $reservation->getId()
            ));
        }

        // Marcar reservación como cancelada
        $reservation->setIsAvailable(false);
        $reservation->setCancellationAt(new \DateTime());

        $this->reservationMailer->sendCancellationMail($reservation, ReservationCanceledEvent::SOURCE_CLASS_CHANGE);

        $user = $reservation->getUser();
        if ($user !== null) {
            $session = $reservation->getSession();
            $disciplineName = $session?->getDiscipline()?->getName() ?? 'la clase';
            $sessionDate    = $session?->getDateStart()?->format('d/m/Y') ?? '';
            try {
                $this->notificationDispatcher->dispatch(
                    'reservation_cancelled',
                    $user,
                    'Reservación cancelada',
                    sprintf('Tu lugar en %s del %s fue cancelado por un cambio en la clase. Tu crédito ha sido reintegrado.', $disciplineName, $sessionDate),
                    ['resource_key' => sprintf('reservation_cancelled_%d', (int) $reservation->getId())],
                    Notification::PRIORITY_HIGH,
                );
            } catch (\Throwable) {}
        }

        // Devolver crédito SOLO si el paquete NO es ilimitado
        if (!$transaction->isPackageIsUnlimited()) {
            $transaction->setHaveSessionsAvailable(true);

            $this->logger->info('Crédito devuelto a Transaction', [
                'transaction_id' => $transaction->getId(),
                'reservation_id' => $reservation->getId(),
                'user_id' => $reservation->getUser()?->getId(),
            ]);
        } else {
            $this->logger->info('Reservación cancelada (paquete ilimitado, sin devolución crédito)', [
                'transaction_id' => $transaction->getId(),
                'reservation_id' => $reservation->getId(),
            ]);
        }

        return true;
    }

    /**
     * Cancela múltiples reservaciones, devuelve créditos y crea auditoría.
     *
     * @param Reservation[] $reservations    Reservaciones a cancelar
     * @param Session       $session         Sesión donde ocurre el cambio
     * @param UserInterface $adminUser       Usuario admin que realiza el cambio
     * @param array         $disabledPlaces  Array de números de asientos afectados (o vacío para capacity_changed)
     * @param string        $reason          Motivo del cambio (10-500 chars)
     * @param string        $auditType       Tipo de auditoría: 'place_disabled' | 'capacity_changed'
     *
     * @return array ['success' => int, 'failed' => int]
     */
    public function cancelMultipleAndAudit(
        array $reservations,
        Session $session,
        UserInterface $adminUser,
        array $disabledPlaces,
        string $reason,
        string $auditType = 'place_disabled'
    ): array {
        // Validar datos de entrada
        if (!$this->validateData($session, $reservations)) {
            $this->logger->error('Datos inválidos para cancelación múltiple', [
                'session_id' => $session->getId(),
                'reservations_count' => count($reservations),
            ]);

            return ['success' => 0, 'failed' => count($reservations)];
        }

        // Validar motivo
        $reasonLength = mb_strlen(trim($reason));
        if ($reasonLength < 10 || $reasonLength > 500) {
            $this->logger->error('Motivo inválido (debe tener 10-500 caracteres)', [
                'reason_length' => $reasonLength,
            ]);

            throw new \InvalidArgumentException('El motivo debe tener entre 10 y 500 caracteres');
        }

        $successCount = 0;
        $failedCount = 0;
        $affectedUsers = [];

        // Cancelar cada reservación
        foreach ($reservations as $reservation) {
            try {
                if ($this->cancelAndRefund($reservation)) {
                    ++$successCount;

                    // Recolectar datos del usuario afectado
                    $user = $reservation->getUser();
                    if ($user !== null) {
                        $affectedUsers[] = [
                            'id' => $user->getId(),
                            'name' => $user->getName().' '.$user->getLastname(),
                            'email' => $user->getEmail(),
                            'place' => $reservation->getPlaceNumber(),
                        ];
                    }
                } else {
                    ++$failedCount;
                }
            } catch (\Exception $e) {
                ++$failedCount;
                $this->logger->error('Error cancelando reservación', [
                    'reservation_id' => $reservation->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Crear registro de auditoría
        if ($successCount > 0) {
            $audit = new SessionAudit();
            $audit->setSession($session);
            $audit->setAdminUserIdentifier($adminUser->getUserIdentifier());
            $audit->setAuditType($auditType);
            $audit->setReason($reason);
            $audit->setDisabledPlaces($disabledPlaces);
            $audit->setAffectedUsers($affectedUsers);
            $audit->setAffectedReservationsCount($successCount);

            $this->em->persist($audit);

            $this->logger->info('Auditoría de deshabilitación creada', [
                'session_id' => $session->getId(),
                'admin_user_identifier' => $adminUser->getUserIdentifier(),
                'affected_count' => $successCount,
                'disabled_places' => $disabledPlaces,
            ]);
        }

        // Flush todos los cambios juntos
        $this->em->flush();

        return [
            'success' => $successCount,
            'failed' => $failedCount,
        ];
    }

    /**
     * Valida que los datos son correctos antes de procesar.
     *
     * @param Session       $session      Sesión a validar
     * @param Reservation[] $reservations Reservaciones a validar
     *
     * @return bool true si los datos son válidos
     */
    public function validateData(Session $session, array $reservations): bool
    {
        // Validar que la sesión tiene ID
        if ($session->getId() === null) {
            $this->logger->error('Session sin ID');

            return false;
        }

        // Validar cada reservación
        foreach ($reservations as $reservation) {
            // Validar que es una instancia de Reservation
            if (!$reservation instanceof Reservation) {
                $this->logger->error('Elemento no es una Reservation', [
                    'type' => get_class($reservation),
                ]);

                return false;
            }

            // Validar que pertenece a esta sesión
            if ($reservation->getSession()?->getId() !== $session->getId()) {
                $this->logger->error('Reservación no pertenece a esta sesión', [
                    'reservation_id' => $reservation->getId(),
                    'reservation_session_id' => $reservation->getSession()?->getId(),
                    'expected_session_id' => $session->getId(),
                ]);

                return false;
            }

            // Validar que tiene usuario asignado
            if ($reservation->getUser() === null) {
                $this->logger->error('Reservación sin usuario asignado', [
                    'reservation_id' => $reservation->getId(),
                ]);

                return false;
            }
        }

        return true;
    }

    /**
     * Registra auditoría cuando un USUARIO cancela su propia reservación.
     * Este método NO cancela la reserva, solo registra el evento en session_audit.
     * La cancelación debe hacerse previamente con ReservationService->cancel().
     *
     * @param Reservation  $reservation Reservación que fue cancelada
     * @param string|null  $reason      Motivo opcional del usuario
     *
     * @return void
     */
    public function auditUserCancellation(Reservation $reservation, ?string $reason = null): void
    {
        $user = $reservation->getUser();
        $session = $reservation->getSession();

        if ($user === null || $session === null) {
            $this->logger->error('No se puede crear auditoría: reservación sin usuario o sesión', [
                'reservation_id' => $reservation->getId(),
            ]);

            return;
        }

        // Crear registro de auditoría
        $audit = new SessionAudit();
        $audit->setSession($session);
        $audit->setUserIdentifier($user->getUserIdentifier());
        $audit->setAuditType('user_cancelled');
        $audit->setReason($reason); // null si usuario no dio motivo
        $audit->setAffectedUsers([
            [
                'id' => $user->getId(),
                'name' => $user->getName().' '.$user->getLastname(),
                'email' => $user->getEmail(),
                'place' => $reservation->getPlaceNumber(),
            ],
        ]);
        $audit->setAffectedReservationsCount(1);

        $this->em->persist($audit);
        $this->em->flush();

        $this->logger->info('Auditoría de cancelación por usuario creada', [
            'session_id' => $session->getId(),
            'user_identifier' => $user->getUserIdentifier(),
            'reservation_id' => $reservation->getId(),
            'has_reason' => $reason !== null,
        ]);
    }

    /**
     * Registra auditoría cuando un USUARIO cambia su reservación a otra sesión.
     * Este método NO cambia la reserva, solo registra el evento en session_audit.
     * El cambio debe hacerse previamente con ReservationService->change().
     *
     * @param Reservation  $reservation     Reservación que fue modificada
     * @param Session      $previousSession Sesión anterior (de donde salió)
     * @param int          $previousPlace   Asiento previo en sesión origen
     * @param string|null  $reason          Motivo opcional del usuario
     *
     * @return void
     */
    public function auditUserChange(
        Reservation $reservation,
        Session $previousSession,
        int $previousPlace,
        ?string $reason = null
    ): void
    {
        $user = $reservation->getUser();
        $newSession = $reservation->getSession();
        $newPlace = $reservation->getPlaceNumber();
        $reservationId = $reservation->getId();
        $previousSessionId = $previousSession->getId();
        $newSessionId = $newSession?->getId();

        if (
            $user === null ||
            $newSession === null ||
            $newPlace === null ||
            $reservationId === null ||
            $previousSessionId === null ||
            $newSessionId === null
        ) {
            $this->logger->error('No se puede crear auditoría: reservación sin usuario o sesión', [
                'reservation_id' => $reservation->getId(),
            ]);

            return;
        }

        $changeFlowId = $this->generateChangeFlowId();

        $baseAffectedUser = [
            [
                'id' => $user->getId(),
                'name' => $user->getName().' '.$user->getLastname(),
                'email' => $user->getEmail(),
                'from_place' => $previousPlace,
                'to_place' => $newPlace,
                'from_session_id' => $previousSessionId,
                'to_session_id' => $newSessionId,
                'change_flow_id' => $changeFlowId,
            ],
        ];

        // Evento de salida (sesión origen)
        $auditFrom = new SessionAudit();
        $auditFrom->setSession($previousSession);
        $auditFrom->setUserIdentifier($user->getUserIdentifier());
        $auditFrom->setAuditType('user_changed_from');
        $auditFrom->setReason($reason);
        $auditFrom->setAffectedUsers($baseAffectedUser);
        $auditFrom->setAffectedReservationsCount(1);
        $auditFrom->setChangeFlowId($changeFlowId);
        $auditFrom->setReservationId($reservationId);
        $auditFrom->setFromSessionId($previousSessionId);
        $auditFrom->setToSessionId($newSessionId);
        $auditFrom->setFromPlace($previousPlace);
        $auditFrom->setToPlace($newPlace);

        // Evento de entrada (sesión destino)
        $auditTo = new SessionAudit();
        $auditTo->setSession($newSession);
        $auditTo->setUserIdentifier($user->getUserIdentifier());
        $auditTo->setAuditType('user_changed_to');
        $auditTo->setReason($reason);
        $auditTo->setAffectedUsers($baseAffectedUser);
        $auditTo->setAffectedReservationsCount(1);
        $auditTo->setChangeFlowId($changeFlowId);
        $auditTo->setReservationId($reservationId);
        $auditTo->setFromSessionId($previousSessionId);
        $auditTo->setToSessionId($newSessionId);
        $auditTo->setFromPlace($previousPlace);
        $auditTo->setToPlace($newPlace);

        $this->em->persist($auditFrom);
        $this->em->persist($auditTo);
        $this->em->flush();

        $this->logger->info('Auditoría bidireccional de cambio por usuario creada', [
            'change_flow_id' => $changeFlowId,
            'previous_session_id' => $previousSessionId,
            'new_session_id' => $newSessionId,
            'user_identifier' => $user->getUserIdentifier(),
            'reservation_id' => $reservationId,
            'has_reason' => $reason !== null,
        ]);
    }

    private function generateChangeFlowId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
