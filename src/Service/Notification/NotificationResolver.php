<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\ReservationRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class NotificationResolver
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private ReservationRepository $reservationRepository,
    ) {
    }

    /**
     * @return array{targetUrl: string, fallback: bool, message: string|null}
     */
    public function resolve(Notification $notification, User $user): array
    {
        return match ($notification->getType()) {
            // Reservas
            'reservation_confirmed'    => $this->fallbackTo(route: 'reserved_sessions'),
            'reservation_cancelled'    => $this->informational(),
            'waiting_list_promoted'    => $this->fallbackTo(route: 'reserved_sessions'),
            'waiting_list_confirmed'   => $this->fallbackTo(route: 'profile_waiting_list'),
            'waiting_list_denied'      => $this->informational(),
            'waiting_list_expired'     => $this->informational(),

            // Clases
            'session_reminder'         => $this->fallbackTo(route: 'reserved_sessions'),
            'session_updated'          => $this->fallbackTo(route: 'reserved_sessions'),
            'rating_pending'           => $this->resolveRatingPending($notification, $user),

            // Pagos y paquetes
            'payment_confirmed'        => $this->fallbackTo(route: 'sessions_available'),
            'transaction_expired'      => $this->fallbackTo(route: 'transaction'),
            'transaction_expiring_soon' => $this->informational(),

            // Cuenta
            'welcome'                  => $this->fallbackTo(
                route: 'page',
                routeParams: ['slug' => 'quienes-somos'],
            ),
            'password_changed'         => $this->fallbackTo(
                route: 'profile',
                routeParams: ['_fragment' => 'perfil'],
            ),

            default => $this->fallbackTo(
                route: 'profile',
                fallback: true,
                message: 'No se pudo abrir el destino de la notificacion. Mostramos tu cuenta general.',
            ),
        };
    }

    /**
     * @return array{targetUrl: string, fallback: bool, message: string|null}
     */
    private function resolveRatingPending(Notification $notification, User $user): array
    {
        $payload = $notification->getPayload();
        $reservationId = $payload['reservation_id'] ?? null;

        if ($reservationId !== null) {
            $reservation = $this->reservationRepository->findOneBy([
                'id'   => $reservationId,
                'user' => $user,
            ]);

            if ($reservation !== null) {
                return $this->fallbackTo(
                    route: 'session_used_rate',
                    routeParams: ['id' => $reservation->getId()],
                );
            }
        }

        return $this->fallbackTo(
            route: 'sessions_used',
            fallback: true,
            message: 'No se encontro la clase para calificar. Mostrando el historial de clases.',
        );
    }

    /**
     * Notificacion puramente informativa: sin deep-link, sin mensaje de error.
     *
     * @return array{targetUrl: string, fallback: bool, message: string|null}
     */
    private function informational(): array
    {
        return [
            'targetUrl' => null,
            'fallback'  => false,
            'message'   => null,
        ];
    }

    /**
     * @param array<string, scalar> $routeParams
     *
     * @return array{targetUrl: string, fallback: bool, message: string|null}
     */
    private function fallbackTo(
        string $route,
        array $routeParams = [],
        bool $fallback = false,
        ?string $message = null,
    ): array {
        return [
            'targetUrl' => $this->urlGenerator->generate($route, $routeParams),
            'fallback' => $fallback,
            'message' => $message,
        ];
    }
}
