<?php

declare(strict_types=1);

namespace App\Service\Mailer;

use App\Entity\Reservation;
use App\Entity\Session;
use App\Entity\User;
use App\Event\ReservationCanceledEvent;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Reservation Mailer.
 */
class ReservationMailer extends AbstractMailManager
{
    /**
     * Envia un mail de confirmación al usuario.
     *
     * @param Reservation $reservation
     */
    public function sendConfirmationEmail(Reservation $reservation): void
    {
        $this->send(
            'mail/reservation/confirmation.html.twig',
            'Tu reservación ha sido registrada correctamente',
            $reservation
        );
    }

    /**
     * Envia un mail de confirmación al usuario cuando la reservacion
     * proviene de la lista de espera.
     *
     * @param Reservation $reservation
     */
    public function sendWaitingListConfirmationEmail(Reservation $reservation): void
    {
        $sentAt = new \DateTimeImmutable();
        $graceDeadline = $sentAt->modify('+1 hour');

        $this->send(
            'mail/reservation/waitinglist-confirmation.html.twig',
            'Tu lugar fue asignado (lista de espera): confirma en las próximas 1 hora',
            $reservation,
            [
                'sentAt' => $sentAt,
                'graceDeadline' => $graceDeadline,
            ]
        );
    }

    /**
     * Envia un mail de confirmación al cancelar una reservación.
     *
     * @param Reservation $reservation
     */
    public function sendCancellationMail(
        Reservation $reservation,
        string $source = ReservationCanceledEvent::SOURCE_SYSTEM,
    ): void
    {
        /** @var Session $session */
        $session = $reservation->getSession();
        $exerciseRoom = $session->getExerciseRoom();
        $discipline = $session->getDiscipline();
        $instructor = $session->getInstructor();

        /** @var User $user */
        $user = $reservation->getUser();

        $context = [
            'subject' => $this->getCancellationSubject($source),
            'user' => $user,
            'reservation' => $reservation,
            'session' => $session,
            'exerciseRoom' => $exerciseRoom,
            'discipline' => $discipline,
            'instructor' => $instructor,
            'cancellationSource' => $source,
            'isStaffOperationalCancellation' => $this->isStaffOperationalCancellation($source),
            'cancellationTypeLabel' => $this->getCancellationTypeLabel($source),
            'cancellationHeadline' => $this->getCancellationHeadline($source),
            'cancellationReason' => $this->getCancellationReasonLabel($source),
        ];

        try {
            $this->sendMessage('mail/reservation/cancellation.html.twig', $context, $user->getEmail());
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Error sending email: '.$e->getMessage());
        }
    }

    public function sendSessionCanceledEmail(Reservation $reservation): void
    {
        $this->send(
            'mail/reservation/session-canceled.html.twig',
            'La clase que reservaste ha sido cancelada',
            $reservation
        );
    }

    public function sendSessionUpdatedEmail(Reservation $reservation, array $changes, array $changeDetails = []): void
    {
        /** @var Session $session */
        $session = $reservation->getSession();
        $exerciseRoom = $session->getExerciseRoom();
        $discipline = $session->getDiscipline();
        $instructor = $session->getInstructor();

        /** @var User $user */
        $user = $reservation->getUser();

        $context = [
            'subject' => 'Tu clase reservada tuvo cambios',
            'user' => $user,
            'reservation' => $reservation,
            'session' => $session,
            'exerciseRoom' => $exerciseRoom,
            'discipline' => $discipline,
            'instructor' => $instructor,
            'changes' => $changes,
            'changeDetails' => $changeDetails,
        ];

        try {
            $this->sendMessage('mail/reservation/session-updated.html.twig', $context, $user->getEmail());
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Error sending session-updated email: '.$e->getMessage());
        }
    }

    public function sendChangedEmail(
        Reservation $reservation,
        Session $sourceSession,
        int $sourcePlace,
        Session $targetSession,
        int $targetPlace,
    ): void {
        /** @var User $user */
        $user = $reservation->getUser();

        $context = [
            'subject' => 'Tu cambio de reservación fue realizado',
            'user' => $user,
            'reservation' => $reservation,
            'sourceSession' => $sourceSession,
            'targetSession' => $targetSession,
            'sourceDiscipline' => $sourceSession->getDiscipline(),
            'targetDiscipline' => $targetSession->getDiscipline(),
            'sourceInstructor' => $sourceSession->getInstructor(),
            'targetInstructor' => $targetSession->getInstructor(),
            'sourcePlace' => $sourcePlace,
            'targetPlace' => $targetPlace,
        ];

        try {
            $this->sendMessage('mail/reservation/change.html.twig', $context, $user->getEmail());
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Error sending reservation-change email: '.$e->getMessage());
        }
    }

    /**
     * @param Reservation[] $reservations
     */
    public function sendNextDayDigestEmail(User $user, array $reservations, \DateTimeInterface $targetDate): void
    {
        $context = [
            'subject' => 'Tus clases de mañana en P&B Studio',
            'user' => $user,
            'reservations' => $reservations,
            'targetDate' => $targetDate,
        ];

        try {
            $this->sendMessage('mail/reservation/next-day-digest.html.twig', $context, (string) $user->getEmail());
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Error sending next-day reservation digest: '.$e->getMessage());
        }
    }

    /**
     * Send.
     *
     * @param string      $template
     * @param string      $subject
     * @param Reservation $reservation
     */
    private function send(string $template, string $subject, Reservation $reservation, array $extraContext = []): void
    {
        /** @var Session $session */
        $session = $reservation->getSession();
        $exerciseRoom = $session->getExerciseRoom();
        $discipline = $session->getDiscipline();
        $instructor = $session->getInstructor();

        /** @var User $user */
        $user = $reservation->getUser();

        $context = array_merge([
            'subject' => $subject,
            'user' => $user,
            'reservation' => $reservation,
            'session' => $session,
            'exerciseRoom' => $exerciseRoom,
            'discipline' => $discipline,
            'instructor' => $instructor,
        ], $extraContext);

        try {
            $this->sendMessage($template, $context, $user->getEmail());
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Error sending email: '.$e->getMessage());
        }
    }

    private function getCancellationReasonLabel(string $source): string
    {
        if ($this->isStaffOperationalCancellation($source)) {
            return 'Cancelación aplicada por motivos operativos del staff.';
        }

        return match ($source) {
            ReservationCanceledEvent::SOURCE_USER => 'Cancelación solicitada por ti.',
            default => 'Cancelación procesada por el sistema.',
        };
    }

    private function getCancellationTypeLabel(string $source): string
    {
        if ($this->isStaffOperationalCancellation($source)) {
            return 'Cancelación operativa del staff';
        }

        return match ($source) {
            ReservationCanceledEvent::SOURCE_USER => 'Cancelación por usuario',
            default => 'Cancelación automática del sistema',
        };
    }

    private function getCancellationHeadline(string $source): string
    {
        if ($this->isStaffOperationalCancellation($source)) {
            return 'Tu reservación fue cancelada por el equipo de staff por motivos operativos.';
        }

        return match ($source) {
            ReservationCanceledEvent::SOURCE_USER => 'Tu reservación se canceló correctamente.',
            default => 'Tu reservación fue cancelada por el sistema.',
        };
    }

    private function getCancellationSubject(string $source): string
    {
        if ($this->isStaffOperationalCancellation($source)) {
            return 'Aviso: el staff canceló tu reservación';
        }

        return match ($source) {
            ReservationCanceledEvent::SOURCE_USER => 'Confirmación: cancelaste tu reservación',
            default => 'Tu reservación ha sido cancelada',
        };
    }

    private function isStaffOperationalCancellation(string $source): bool
    {
        return in_array($source, [
            ReservationCanceledEvent::SOURCE_STAFF,
            ReservationCanceledEvent::SOURCE_CLASS_CHANGE,
        ], true);
    }
}
