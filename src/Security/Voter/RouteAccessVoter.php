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

        $effectiveSubject = $subject;
        $result = in_array($effectiveSubject, $permissions, true);

        $this->logger->debug('[RouteAccessVoter] Verificación de permiso de ruta', [
            'ruta'             => $subject,
            'ruta_efectiva'    => $effectiveSubject,
            'permisos_usuario' => $permissions,
            'acceso_concedido' => $result,
        ]);

        return $result;
    }
}
