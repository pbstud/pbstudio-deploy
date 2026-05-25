<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Staff;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

class RouteAccessVoter extends Voter
{
    public const ALLOWED_ROUTE_ACCESS = 'ALLOWED_ROUTE_ACCESS';
    private const ROUTE_PERMISSION_ALIASES = [
        // Calificaciones se maneja como permiso unico de modulo.
        'backend_stats_ratings_detail' => 'backend_stats_ratings',
        // Logros usa permiso unico de modulo para acciones CRUD.
        'backend_achievement_new' => 'backend_achievement',
        'backend_achievement_edit' => 'backend_achievement',
        'backend_achievement_delete' => 'backend_achievement',
    ];

    public function __construct(
        private RequestStack $requestStack,
        private LoggerInterface $logger,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ALLOWED_ROUTE_ACCESS === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        $this->logger->debug('[RouteAccessVoter] voteOnAttribute invocado', [
            'attribute' => $attribute,
            'subject'   => is_string($subject) ? $subject : '(no-string)',
            'user'      => $user instanceof UserInterface ? $user->getUserIdentifier() : 'anónimo',
        ]);

        // if the user is anonymous, do not grant access
        if (!$user instanceof UserInterface) {
            $this->logger->warning('[RouteAccessVoter] Usuario anónimo — acceso denegado');

            return false;
        }

        $roles = $user->getRoles();

        $this->logger->debug('[RouteAccessVoter] Roles del usuario', [
            'user'  => $user->getUserIdentifier(),
            'roles' => $roles,
        ]);

        if (in_array('ROLE_ADMIN', $roles)) {
            $this->logger->debug('[RouteAccessVoter] Usuario tiene ROLE_ADMIN — acceso concedido');

            return true;
        }

        if (!is_string($subject)) {
            $subject = $this->requestStack->getCurrentRequest()->attributes->get('_route');
            $this->logger->debug('[RouteAccessVoter] Subject resuelto desde la ruta', ['_route' => $subject]);
        }

        $permissions = ($user instanceof Staff) ? $user->getPermissions() : [];

        $effectiveSubject = $this->resolveEffectiveSubject($subject);
        $result = in_array($effectiveSubject, $permissions, true);

        $this->logger->debug('[RouteAccessVoter] Verificación de permiso de ruta', [
            'ruta'             => $subject,
            'ruta_efectiva'    => $effectiveSubject,
            'permisos_usuario' => $permissions,
            'acceso_concedido' => $result,
        ]);

        return $result;
    }

    private function resolveEffectiveSubject(string $subject): string
    {
        if (isset(self::ROUTE_PERMISSION_ALIASES[$subject])) {
            return self::ROUTE_PERMISSION_ALIASES[$subject];
        }

        // Regla operativa: si puede ver listado, puede exportar ese mismo modulo.
        if (str_ends_with($subject, '_export')) {
            return substr($subject, 0, -strlen('_export'));
        }

        return $subject;
    }
}
