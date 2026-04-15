<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\HttpUtils;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

readonly class AuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(private UrlGeneratorInterface $urlGenerator, private HttpUtils $httpUtils)
    {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($request->isXmlHttpRequest() && $request->request->has('_modaltarget')) {
            return new JsonResponse([
                'error' => 'Datos de acceso inválidos.',
                'message' => $exception->getMessageKey(),
            ]);
        }

        if (!$request->attributes->getBoolean('_stateless')) {
            $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);
        }

        return $this->httpUtils->createRedirectResponse($request, $this->urlGenerator->generate('app_login'));
    }
}
