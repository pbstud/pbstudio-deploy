<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\WaitingList;
use Symfony\Contracts\EventDispatcher\Event;

class WaitingListSuccessEvent extends Event
{
    public function __construct(private readonly WaitingList $waitingList)
    {
    }

    public function getWaitingList(): WaitingList
    {
        return $this->waitingList;
    }
}
