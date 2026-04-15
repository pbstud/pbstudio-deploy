<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Notification;
use App\Entity\User;

interface NotificationDispatcherInterface
{
    public function dispatch(
        string $type,
        User $user,
        string $title,
        string $body,
        array $payload = [],
        string $priority = Notification::PRIORITY_MEDIUM,
    ): ?Notification;
}
