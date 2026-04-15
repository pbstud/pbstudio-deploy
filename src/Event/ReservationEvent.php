<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Reservation;
use App\Entity\WaitingList;
use Symfony\Contracts\EventDispatcher\Event;

class ReservationEvent extends Event
{
    private ?WaitingList $waitingList = null;

    public function __construct(private readonly Reservation $reservation)
    {
    }

    public function getReservation(): Reservation
    {
        return $this->reservation;
    }

    public function getWaitingList(): ?WaitingList
    {
        return $this->waitingList;
    }

    public function setWaitingList(?WaitingList $waitingList): void
    {
        $this->waitingList = $waitingList;
    }
}
