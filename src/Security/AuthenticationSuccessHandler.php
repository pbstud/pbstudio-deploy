<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

readonly class AuthenticationSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(private UrlGeneratorInterface $urlGenerator)
    {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): ?Response
    {
        $url = $this->urlGenerator->generate('profile');

        if ($request->request->has('_modaltarget')) {
            $modalTarget = (string) $request->request->get('_modaltarget');

            // _modaltarget can be a placeholder flag (e.g. "1") when request comes from modal-only context.
            // Only treat it as redirect target when it looks like an encoded path.
            if ('' !== $modalTarget && '1' !== $modalTarget && str_contains($modalTarget, '__')) {
                $url = str_replace('__', '/', $modalTarget);
            }
        }

        return new JsonResponse([
            'targetUrl' => $url,
        ]);
    }
}
