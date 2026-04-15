<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Reservation;
use App\Entity\Session;
use Symfony\Contracts\EventDispatcher\Event;

class ReservationChangedEvent extends Event
{
    public function __construct(
        private readonly Reservation $reservation,
        private readonly Session $sourceSession,
        private readonly int $sourcePlace,
        private readonly Session $targetSession,
        private readonly int $targetPlace,
    ) {
    }

    public function getReservation(): Reservation
    {
        return $this->reservation;
    }

    public function getSourceSession(): Session
    {
        return $this->sourceSession;
    }

    public function getSourcePlace(): int
    {
        return $this->sourcePlace;
    }

    public function getTargetSession(): Session
    {
        return $this->targetSession;
    }

    public function getTargetPlace(): int
    {
        return $this->targetPlace;
    }
}
