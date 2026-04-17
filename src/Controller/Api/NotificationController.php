<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\Notification\NotificationResolver;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/notifications', name: 'api_notifications_')]
class NotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly NotificationResolver $resolver,
        private readonly Connection $connection,
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

    #[Route('/stream', name: 'stream', methods: ['GET'])]
    public function streamNotifications(Request $request): StreamedResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        /** @var User $user */
        $user   = $this->getUser();
        $userId = $user->getId();
        $conn   = $this->connection;

        // Libera el lock de sesion para no bloquear otras requests del mismo usuario
        // mientras el stream SSE permanece abierto.
        $session = $request->getSession();
        if ($session->isStarted()) {
            $session->save();
        }

        $response = new StreamedResponse(function () use ($conn, $userId): void {
            set_time_limit(0);
            ignore_user_abort(true);

            $lastCount = -1;
            $iterations = 0;
            $maxIterations = 360; // 1 hora máx (360 * 10s)

            while ($iterations < $maxIterations) {
                $iterations++;

                if (connection_aborted()) {
                    break;
                }

                try {
                    $count = (int) $conn->fetchOne(
                        'SELECT COUNT(id) FROM notification WHERE user_id = ? AND read_at IS NULL',
                        [$userId]
                    );
                } catch (\Throwable) {
                    break;
                }

                if ($count !== $lastCount) {
                    echo 'data: ' . json_encode(['count' => $count]) . "\n\n";
                    $lastCount = $count;
                } else {
                    // Heartbeat para mantener viva la conexion
                    echo ": heartbeat\n\n";
                }

                flush();
                sleep(10);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
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
