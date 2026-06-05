<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\Notification\NotificationResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class NotificationsController extends AbstractController
{
    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly NotificationResolver $resolver,
    ) {}

    #[Route('/notificaciones', name: 'notifications', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        /** @var User $user */
        $user = $this->getUser();
        $notifications = $this->notificationRepository->findAllByUser($user);
        $resolvedUrls = $this->resolver->resolveMany($notifications, $user);

        $targetUrls = [];
        foreach ($notifications as $n) {
            $targetUrls[$n->getId()] = $resolvedUrls[$n->getId()]['targetUrl'] ?? null;
        }

        return $this->render('notifications/index.html.twig', [
            'notifications' => $notifications,
            'target_urls'   => $targetUrls,
            'unread_count'  => $this->notificationRepository->countUnread($user),
            'mark_read_url' => $this->generateUrl('api_notifications_mark_read', ['id' => '__ID__']),
        ]);
    }

    #[Route('/notificaciones/marcar-todas', name: 'notifications_mark_all_read', methods: ['POST'])]
    public function markAllRead(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        if (!$this->isCsrfTokenValid('notifications_mark_all', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        /** @var User $user */
        $user    = $this->getUser();
        $marked  = $this->notificationRepository->markAllAsRead($user);

        if ($marked > 0) {
            $this->addFlash('success', sprintf('%d notificación(es) marcada(s) como leída(s).', $marked));
        }

        return $this->redirectToRoute('notifications');
    }
}
