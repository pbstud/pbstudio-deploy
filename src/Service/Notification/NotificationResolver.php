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
     * Resuelve las targetUrl de un array de notificaciones en una sola pasada,
     * precargando las reservaciones necesarias para evitar el problema N+1.
     *
     * @param Notification[] $notifications
     * @return array<int, array{targetUrl: string, fallback: bool, message: string|null}> indexado por notification.id
     */
    public function resolveMany(array $notifications, User $user): array
    {
        // Recolectar todos los reservation_id de las notificaciones rating_pending
        $reservationIds = [];
        foreach ($notifications as $n) {
            if ($n->getType() === 'rating_pending') {
                $rid = $n->getPayload()['reservation_id'] ?? null;
                if ($rid !== null) {
                    $reservationIds[(int) $rid] = true;
                }
            }
        }

        // Una sola query para todas las reservaciones necesarias
        $reservationMap = [];
        if ($reservationIds) {
            $reservations = $this->reservationRepository->findBy([
                'id'   => array_keys($reservationIds),
                'user' => $user,
            ]);
            foreach ($reservations as $r) {
                $reservationMap[$r->getId()] = $r;
            }
        }

        $result = [];
        foreach ($notifications as $n) {
            $result[$n->getId()] = $this->resolveWithMap($n, $reservationMap);
        }

        return $result;
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

            // Logros
            'achievement_unlocked',
            'achievement_unlocked_special' => $this->fallbackTo(
                route: 'profile',
                routeParams: ['_fragment' => 'logros'],
            ),

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
     * Versión interna de resolve() que usa un mapa precargado de reservaciones.
     *
     * @param array<int, object> $reservationMap
     * @return array{targetUrl: string, fallback: bool, message: string|null}
     */
    private function resolveWithMap(Notification $notification, array $reservationMap): array
    {
        if ($notification->getType() !== 'rating_pending') {
            // Para todos los tipos que no necesitan la BD, resolve() es puro
            return $this->resolveType($notification);
        }

        $payload = $notification->getPayload();
        $reservationId = (int) ($payload['reservation_id'] ?? 0);
        $reservation = $reservationMap[$reservationId] ?? null;

        if ($reservation !== null) {
            return $this->fallbackTo(
                route: 'session_used_rate',
                routeParams: ['id' => $reservation->getId()],
            );
        }

        return $this->fallbackTo(
            route: 'sessions_used',
            fallback: true,
            message: 'No se encontro la clase para calificar. Mostrando el historial de clases.',
        );
    }

    /**
     * Resolución pura por tipo (sin acceso a BD).
     *
     * @return array{targetUrl: string, fallback: bool, message: string|null}
     */
    private function resolveType(Notification $notification): array
    {
        return match ($notification->getType()) {
            'reservation_confirmed'    => $this->fallbackTo(route: 'reserved_sessions'),
            'reservation_cancelled'    => $this->informational(),
            'waiting_list_promoted'    => $this->fallbackTo(route: 'reserved_sessions'),
            'waiting_list_confirmed'   => $this->fallbackTo(route: 'profile_waiting_list'),
            'waiting_list_denied'      => $this->informational(),
            'waiting_list_expired'     => $this->informational(),
            'session_reminder'         => $this->fallbackTo(route: 'reserved_sessions'),
            'session_updated'          => $this->fallbackTo(route: 'reserved_sessions'),
            'payment_confirmed'        => $this->fallbackTo(route: 'sessions_available'),
            'transaction_expired'      => $this->fallbackTo(route: 'transaction'),
            'transaction_expiring_soon' => $this->informational(),
            'achievement_unlocked',
            'achievement_unlocked_special' => $this->fallbackTo(
                route: 'profile',
                routeParams: ['_fragment' => 'logros'],
            ),
            'welcome' => $this->fallbackTo(
                route: 'page',
                routeParams: ['slug' => 'quienes-somos'],
            ),
            'password_changed' => $this->fallbackTo(
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
