<?php

declare(strict_types=1);

namespace App\Service\Mailer;

use App\Entity\Session;
use App\Entity\User;
use App\Entity\WaitingList;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Waiting List Mailer.
 */
class WaitingListMailer extends AbstractMailManager
{
    /**
     * Envia un mail de confirmación al usuario.
     *
     * @param WaitingList $waitingList
     */
    public function sendRegistrationConfirmationEmail(WaitingList $waitingList): void
    {
        $user = $waitingList->getUser();

        /** @var Session $session */
        $session = $waitingList->getSession();
        $exerciseRoom = $session->getExerciseRoom();
        $discipline = $session->getDiscipline();
        $instructor = $session->getInstructor();

        $context = [
            'subject' => 'Clase en lista de espera',
            'user' => $user,
            'waitingList' => $waitingList,
            'session' => $session,
            'exerciseRoom' => $exerciseRoom,
            'discipline' => $discipline,
            'instructor' => $instructor,
        ];

        try {
            $this->sendMessage('mail/waiting_list_register.html.twig', $context, $user->getEmail());
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Error sending waiting list registration email: '.$e->getMessage());
        }
    }

    public function sendRemovalEmail(WaitingList $waitingList): void
    {
        $this->send(
            'mail/waiting_list_removed.html.twig',
            'Has salido de la lista de espera',
            $waitingList
        );
    }

    public function sendExpirationEmail(WaitingList $waitingList): void
    {
        $this->send(
            'mail/waiting_list_expired.html.twig',
            'Tu lugar en la lista de espera ha expirado',
            $waitingList
        );
    }

    public function sendSessionCanceledEmail(WaitingList $waitingList): void
    {
        $this->send(
            'mail/waiting_list_session_canceled.html.twig',
            'La clase en lista de espera fue cancelada',
            $waitingList
        );
    }

    public function sendSessionUpdatedEmail(WaitingList $waitingList, array $changes): void
    {
        $user = $waitingList->getUser();
        $session = $waitingList->getSession();

        if (!$user instanceof User || !$session instanceof Session) {
            return;
        }

        $exerciseRoom = $session->getExerciseRoom();
        $discipline = $session->getDiscipline();
        $instructor = $session->getInstructor();

        $context = [
            'subject' => 'La clase en lista de espera tuvo cambios',
            'user' => $user,
            'waitingList' => $waitingList,
            'session' => $session,
            'exerciseRoom' => $exerciseRoom,
            'discipline' => $discipline,
            'instructor' => $instructor,
            'changes' => $changes,
        ];

        try {
            $this->sendMessage('mail/waiting_list_session_updated.html.twig', $context, $user->getEmail());
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Error sending waiting list session-updated email: '.$e->getMessage());
        }
    }

    public function sendPromotionDeniedEmail(WaitingList $waitingList, string $reason): void
    {
        $user = $waitingList->getUser();
        $session = $waitingList->getSession();

        if (!$user instanceof User || !$session instanceof Session) {
            return;
        }

        $exerciseRoom = $session->getExerciseRoom();
        $discipline = $session->getDiscipline();
        $instructor = $session->getInstructor();

        $context = [
            'subject' => 'No fue posible asignarte un lugar desde lista de espera',
            'user' => $user,
            'waitingList' => $waitingList,
            'session' => $session,
            'exerciseRoom' => $exerciseRoom,
            'discipline' => $discipline,
            'instructor' => $instructor,
            'reason' => $reason,
        ];

        try {
            $this->sendMessage('mail/waiting_list_denied.html.twig', $context, $user->getEmail());
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Error sending waiting list denied email: '.$e->getMessage());
        }
    }

    private function send(string $template, string $subject, WaitingList $waitingList): void
    {
        $user = $waitingList->getUser();
        $session = $waitingList->getSession();

        if (!$user instanceof User || !$session instanceof Session) {
            return;
        }

        $exerciseRoom = $session->getExerciseRoom();
        $discipline = $session->getDiscipline();
        $instructor = $session->getInstructor();

        $context = [
            'subject' => $subject,
            'user' => $user,
            'waitingList' => $waitingList,
            'session' => $session,
            'exerciseRoom' => $exerciseRoom,
            'discipline' => $discipline,
            'instructor' => $instructor,
        ];

        try {
            $this->sendMessage($template, $context, $user->getEmail());
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Error sending waiting list email: '.$e->getMessage());
        }
    }
}
