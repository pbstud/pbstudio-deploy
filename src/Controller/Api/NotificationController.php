<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\Notification\NotificationResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/notifications', name: 'api_notifications_')]
class NotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly NotificationResolver $resolver,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        /** @var User $user */
        $user  = $this->getUser();
        $page  = max(1, (int) $request->query->get('page', 1));
        $limit = min(50, max(1, (int) $request->query->get('limit', 20)));

        $items = $this->notificationRepository->findByUser($user, $page, $limit);

        $data = array_map(fn ($n) => [
            'id'        => $n->getId(),
            'type'      => $n->getType(),
            'title'     => $n->getTitle(),
            'body'      => $n->getBody(),
            'priority'  => $n->getPriority(),
            'readAt'    => $n->getReadAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $n->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'targetUrl' => $this->resolver->resolve($n, $user)['targetUrl'],
        ], $items);

        return $this->json(['data' => $data, 'page' => $page, 'limit' => $limit]);
    }

    #[Route('/unread-count', name: 'unread_count', methods: ['GET'])]
    public function unreadCount(): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'count' => $this->notificationRepository->countUnread($user),
        ]);
    }

    #[Route('/{id}/read', name: 'mark_read', methods: ['PATCH'])]
    public function markRead(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        /** @var User $user */
        $user = $this->getUser();

        try {
            $notification = $this->notificationRepository->markAsRead($id, $user);

            return $this->json([
                'id'     => $notification->getId(),
                'readAt' => $notification->getReadAt()?->format(\DateTimeInterface::ATOM),
            ]);
        } catch (\DomainException) {
            return $this->json(['error' => 'Not found or access denied.'], 403);
        }
    }

    #[Route('/{id}/unread', name: 'mark_unread', methods: ['PATCH'])]
    public function markUnread(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        /** @var User $user */
        $user = $this->getUser();

        $this->notificationRepository->markAsUnread($id, $user);

        return $this->json(['ok' => true]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        /** @var User $user */
        $user    = $this->getUser();
        $deleted = $this->notificationRepository->deleteById($id, $user);

        if (0 === $deleted) {
            return $this->json(['error' => 'Not found or access denied.'], 404);
        }

        return $this->json(['ok' => true]);
    }

    #[Route('/bulk', name: 'bulk', methods: ['POST'])]
    public function bulk(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        $data   = json_decode($request->getContent(), true);
        $ids    = $data['ids']    ?? [];
        $action = $data['action'] ?? '';

        if (!is_array($ids) || [] === $ids) {
            return $this->json(['error' => 'ids must be a non-empty array.'], 400);
        }

        if (!in_array($action, ['read', 'unread', 'delete'], true)) {
            return $this->json(['error' => 'action must be one of: read, unread, delete.'], 400);
        }

        /** @var User $user */
        $user     = $this->getUser();
        $affected = $this->notificationRepository->bulkAction($ids, $user, $action);

        return $this->json(['ok' => true, 'affected' => $affected]);
    }
}
