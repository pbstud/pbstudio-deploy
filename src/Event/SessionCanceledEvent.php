<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Session;
use Symfony\Contracts\EventDispatcher\Event;

class SessionCanceledEvent extends Event
{
    public function __construct(
        private readonly Session $session,
    ) {
    }

    public function getSession(): Session
    {
        return $this->session;
    }
}
