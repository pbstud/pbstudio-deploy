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
        private readonly \Psr\Log\LoggerInterface $logger,
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
            $this->logger->warning('[NotificationDispatcher] Duplicado reciente detectado', [
                'user_id'       => $user->getId(),
                'type'          => $type,
                'resource_key'  => $resourceKey,
                'window'        => '300s',
            ]);
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

        $this->logger->info('[NotificationDispatcher] Notificación despachada', [
            'user_id'       => $user->getId(),
            'type'          => $type,
            'resource_key'  => $resourceKey,
            'notif_id'      => $notification->getId(),
        ]);

        return $notification;
    }
}
