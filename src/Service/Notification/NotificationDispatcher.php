<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;

final class NotificationDispatcher implements NotificationDispatcherInterface
{
    public function __construct(
        private readonly NotificationRepository $repository,
    ) {}

    public function dispatch(
        string $type,
        User $user,
        string $title,
        string $body,
        array $payload = [],
        string $priority = Notification::PRIORITY_MEDIUM,
    ): ?Notification {
        if (!in_array($priority, [
            Notification::PRIORITY_LOW,
            Notification::PRIORITY_MEDIUM,
            Notification::PRIORITY_HIGH,
        ], true)) {
            $priority = Notification::PRIORITY_MEDIUM;
        }

        $resourceKey = $payload['resource_key'] ?? null;

        if (null !== $resourceKey && $this->repository->existsRecentDuplicate($user, $type, $resourceKey)) {
            return null;
        }

        $notification = new Notification();
        $notification->setUser($user);
        $notification->setType($type);
        $notification->setTitle($title);
        $notification->setBody($body);
        $notification->setPayload($payload ?: null);
        $notification->setPriority($priority);

        $this->repository->save($notification, flush: true);

        return $notification;
    }
}
