<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Reservation;

class ReservationCanceledEvent extends ReservationEvent
{
	public const SOURCE_USER = 'user';
	public const SOURCE_STAFF = 'staff';
	public const SOURCE_CLASS_CHANGE = 'class_change';
	public const SOURCE_SYSTEM = 'system';

	public function __construct(Reservation $reservation, private readonly string $source = self::SOURCE_SYSTEM)
	{
		parent::__construct($reservation);
	}

	public function getSource(): string
	{
		return $this->source;
	}
}
