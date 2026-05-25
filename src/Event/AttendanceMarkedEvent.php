<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Reservation;
use App\Entity\User;

/**
 * Dispatched when a Reservation is marked as attended (attended: false → true).
 * NOT dispatched when un-marking attendance.
 *
 * ─── TRIGGER MAP ────────────────────────────────────────────────────────────
 *
 *  ✅ PRODUCCIÓN — el único punto real:
 *     ReservationController::attended()   (POST /backend/reservation/{id}/attended)
 *     → solo cuando wasAttended=false y nowAttended=true
 *     → se evalúan logros para el usuario dueño de esa reserva
 *
 *  ⛔ IGNORADOS — no deben disparar evaluación:
 *     ReservationMarkAttendanceCommand    → herramienta de seed/dev, UPDATE masivo por SQL
 *     SeedDashboardStep3ReservationsCommand → seed de datos, no producción
 *
 *  ℹ️  SessionAutoClosingCommand / SessionController / SessionDayController
 *     solo cambian el status de la Session, NO marcan asistencias individuales.
 *
 * ────────────────────────────────────────────────────────────────────────────
 */
final class AttendanceMarkedEvent
{
    public function __construct(private readonly Reservation $reservation)
    {
    }

    public function getReservation(): Reservation
    {
        return $this->reservation;
    }

    public function getUser(): ?User
    {
        return $this->reservation->getUser();
    }
}
