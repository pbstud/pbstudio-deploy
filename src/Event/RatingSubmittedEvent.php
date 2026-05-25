<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Reservation;
use App\Entity\User;

/**
 * Dispatched when a user successfully submits (or updates) a rating for a class.
 *
 * ─── TRIGGER MAP ────────────────────────────────────────────────────────────
 *
 *  ✅ PRODUCCIÓN — el único punto real:
 *     ProfileController::rateTakenSession()   (POST /mi-cuenta/clases-tomadas/{id}/calificar)
 *     → se dispara siempre que el CSRF sea válido, la clase sea calificable
 *       y al menos un campo de calificación sea no nulo.
 *
 * ────────────────────────────────────────────────────────────────────────────
 */
final class RatingSubmittedEvent
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
