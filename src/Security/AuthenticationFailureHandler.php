<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\HttpUtils;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

readonly class AuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(private UrlGeneratorInterface $urlGenerator, private HttpUtils $httpUtils)
    {
    }

    /** Number of consecutive failures before a client-side throttle is applied. */
    private const MAX_ATTEMPTS = 5;

    /** Throttle duration in seconds. */
    private const THROTTLE_SECONDS = 30;

    /** Seconds of inactivity after which the failed-attempt counter resets. */
    private const ATTEMPT_WINDOW_SECONDS = 60;

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $isModal    = $request->isXmlHttpRequest() && $request->request->has('_modaltarget');
        $isServerThrottle = $exception instanceof TooManyLoginAttemptsAuthenticationException;
        $throttledUntil   = null;

        if (!$request->attributes->getBoolean('_stateless')) {
            $session = $request->getSession();

            if ($isServerThrottle) {
                // Symfony's built-in rate limiter fired — honour it.
                $throttledUntil = time() + self::THROTTLE_SECONDS;
                $session->set('_login_retry_after', $throttledUntil);
                $session->set('_login_failed_attempts', 0);
                $session->remove('_login_failed_attempts_at');
            } else {
                // Reset the counter if the last failure was more than ATTEMPT_WINDOW_SECONDS ago.
                $lastFailedAt = (int) $session->get('_login_failed_attempts_at', 0);
                if ($lastFailedAt > 0 && (time() - $lastFailedAt) > self::ATTEMPT_WINDOW_SECONDS) {
                    $session->set('_login_failed_attempts', 0);
                }

                // Increment the per-session attempt counter.
                $attempts = (int) $session->get('_login_failed_attempts', 0) + 1;
                $session->set('_login_failed_attempts', $attempts);
                $session->set('_login_failed_attempts_at', time());
                $session->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

                if ($attempts >= self::MAX_ATTEMPTS) {
                    // Client-enforced throttle kicks in.
                    $throttledUntil = time() + self::THROTTLE_SECONDS;
                    $session->set('_login_retry_after', $throttledUntil);
                    $session->set('_login_failed_attempts', 0);
                    $session->remove('_login_failed_attempts_at');
                } else {
                    $session->remove('_login_retry_after');
                }
            }
        }

        $isThrottled = $throttledUntil !== null;

        if ($isModal) {
            if ($isThrottled) {
                return new JsonResponse([
                    'throttled'   => true,
                    'retry_after' => $throttledUntil,
                ]);
            }

            $attempts = $request->hasSession()
                ? (int) $request->getSession()->get('_login_failed_attempts', 0)
                : 0;

            return new JsonResponse([
                'error'           => 'Datos de acceso inválidos.',
                'failed_attempts' => $attempts,
            ]);
        }

        return $this->httpUtils->createRedirectResponse($request, $this->urlGenerator->generate('app_login'));
    }
}
