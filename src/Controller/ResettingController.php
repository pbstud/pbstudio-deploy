<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Event\SecurityCredentialChangedEvent;
use App\Form\ResettingFormType;
use App\Repository\UserRepository;
use App\Service\Mailer\ResettingMailer;
use App\Service\Notification\NotificationDispatcher;
use App\Entity\Notification;
use App\Util\TokenGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Resetting Controller.
 *
 * @author JCHR <car.chr@gmail.com>
 */
#[Route('/restablecer')]
class ResettingController extends AbstractController
{
    private const RESEND_COOLDOWN_SECONDS = 30;

    #[Route('/solicitud', name: 'resetting_request', methods: ['GET'])]
    public function request(Request $request): Response
    {
        $isModalTarget = $request->get('_modaltarget') || $request->isXmlHttpRequest();
        $template = $isModalTarget ? 'request_modal' : 'request';

        return $this->render(sprintf('resetting/%s.html.twig', $template));
    }

    #[Route('/send-email', name: 'resetting_send_email', methods: ['POST'])]
    public function sendEmail(
        Request $request,
        UserRepository $userRepository,
        ResettingMailer $resettingMailer,
        EntityManagerInterface $em,
    ): Response {
        $username = (string) $request->request->get('username');

        /** @var User $user */
        $user = $userRepository->findByEmail($username);

        $result = $this->validateNonExistUser($request, $user);

        if (!$result) {
            $result = $this->handleActivePasswordRequest($request, $user);
        }

        if (!$result) {
            $result = $this->sendNotification($request, $user, $resettingMailer, $em);
        }

        if (is_array($result)) {
            $result = $this->json($result);
        }

        return $result;
    }

    #[Route('/check-email', name: 'resetting_check_email', methods: ['GET'])]
    public function checkEmail(Request $request, UserRepository $userRepository): Response
    {
        $retryTtl = $this->getParameter('resetting_retry_ttl');

        $isModalTarget = $request->get('_modaltarget') || $request->isXmlHttpRequest();
        $template = $isModalTarget ? 'check_email_modal' : 'check_email';

        $username = (string) $request->query->get('username', '');
        $resent = (bool) $request->query->get('resent', false);
        $justSent = (bool) $request->query->get('just_sent', false);

        $resendCooldownSeconds = self::RESEND_COOLDOWN_SECONDS;
        $resendCooldownRemaining = 0;

        if ($username) {
            /** @var User|null $user */
            $user = $userRepository->findByEmail($username);
            $resendCooldownRemaining = $this->getResendCooldownRemaining($user);

            if ($justSent) {
                $resendCooldownRemaining = max($resendCooldownRemaining, self::RESEND_COOLDOWN_SECONDS);
            }
        }

        return $this->render(sprintf('resetting/%s.html.twig', $template), [
            'tokenLifetime' => ceil($retryTtl / 3600),
            'username' => $username,
            'resent' => $resent,
            'just_sent' => $justSent,
            'resend_cooldown_seconds' => $resendCooldownSeconds,
            'resend_cooldown_remaining' => $resendCooldownRemaining,
        ]);
    }

    #[Route('/reenviar-email', name: 'resetting_resend_email', methods: ['POST'])]
    public function resendEmail(
        Request $request,
        UserRepository $userRepository,
        ResettingMailer $resettingMailer,
        EntityManagerInterface $em,
    ): Response {
        $username = (string) $request->request->get('username');

        /** @var User $user */
        $user = $userRepository->findByEmail($username);

        $result = $this->validateNonExistUser($request, $user);

        if (!$result) {
            $result = $this->validateResendCooldown($request, $user);
        }

        if (!$result) {
            $result = $this->sendNotification($request, $user, $resettingMailer, $em, true);
        }

        if (is_array($result)) {
            $result = $this->json($result);
        }

        return $result;
    }

    #[Route('/{token}', name: 'resetting_reset', methods: ['GET', 'POST'])]
    public function reset(
        Request $request,
        string $token,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
        Security $security,
        EventDispatcherInterface $dispatcher,
        NotificationDispatcher $notificationDispatcher,
    ): Response {
        $user = $userRepository->findByConfirmationToken($token);
        $retryTtl = $this->getParameter('resetting_retry_ttl');

        if (!$user || !$user->isPasswordRequestNonExpired($retryTtl)) {
            return $this->redirectToRoute('homepage');
        }

        $form = $this->createForm(ResettingFormType::class, null, [
            'validation_groups' => ['ResetPassword', 'Default'],
        ]);

        $form->setData($user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $hashedPassword = $passwordHasher->hashPassword($user, $user->getPlainPassword());

            $user
                ->setPassword($hashedPassword)
                ->setConfirmationToken(null)
                ->setPasswordRequestedAt(null)
            ;

            $em->persist($user);
            $em->flush();

            try {
                $notificationDispatcher->dispatch(
                    'password_changed',
                    $user,
                    'Contraseña actualizada',
                    'Tu contraseña fue cambiada exitosamente. Si no fuiste tú, contacta soporte de inmediato.',
                    ['resource_key' => 'pwd_changed_u' . $user->getId()],
                    Notification::PRIORITY_HIGH,
                );
            } catch (\Throwable) {}

            $dispatcher->dispatch(new SecurityCredentialChangedEvent(
                $user,
                SecurityCredentialChangedEvent::TYPE_PASSWORD,
                null,
                null,
                $request->getClientIp(),
                $request->headers->get('User-Agent'),
            ));

            $security->login($user, 'form_login', 'main');

            return $this->redirectToRoute('profile');
        }

        return $this->render('resetting/reset.html.twig', [
            'token' => $token,
            'form' => $form->createView(),
        ]);
    }

    private function handleActivePasswordRequest(Request $request, User $user = null): array|RedirectResponse|null
    {
        $result = null;
        $retryTtl = $this->getParameter('resetting_retry_ttl');

        if ($user && $user->isPasswordRequestNonExpired($retryTtl)) {
            $result = $this->buildCheckEmailResponse($request, $user->getEmail(), false, true);
        }

        return $result;
    }

    /**
     * @param Request   $request
     * @param User|null $user
     *
     * @return array|RedirectResponse|null
     */
    private function validateNonExistUser(Request $request, User $user = null)
    {
        $result = null;

        if (!$user) {
            $error = 'La dirección de correo electrónico no existe.';

            if ($request->request->has('_modaltarget')) {
                $result = ['error' => $error];
            } else {
                $this->addFlash('danger', $error);

                $result = $this->redirectToRoute('resetting_request');
            }
        }

        return $result;
    }

    private function sendNotification(
        Request $request,
        User $user,
        ResettingMailer $resettingMailer,
        EntityManagerInterface $em,
        bool $resent = false,
    ) {
        try {
            $user
                ->setConfirmationToken(TokenGenerator::generateToken())
                ->setPasswordRequestedAt(new \DateTime())
            ;

            $em->persist($user);
            $em->flush();

            $resettingMailer->sendResetting($user);
        } catch (\Exception $e) {
        }

        return $this->buildCheckEmailResponse($request, $user->getEmail(), $resent, !$resent);
    }

    private function buildCheckEmailResponse(Request $request, string $username, bool $resent = false, bool $justSent = false): array|RedirectResponse
    {
        $params = [
            'username' => $username,
        ];

        if ($resent) {
            $params['resent'] = 1;
        }

        if ($justSent) {
            $params['just_sent'] = 1;
        }

        if ($request->request->has('_modaltarget')) {
            $params['_modaltarget'] = 1;

            return [
                'targetUrl' => $this->generateUrl('resetting_check_email', $params),
            ];
        }

        return $this->redirectToRoute('resetting_check_email', $params);
    }

    private function validateResendCooldown(Request $request, User $user = null): array|RedirectResponse|null
    {
        $remaining = $this->getResendCooldownRemaining($user);

        if ($remaining <= 0) {
            return null;
        }

        $error = sprintf('Espera %d segundos antes de reenviar el correo.', $remaining);

        if ($request->request->has('_modaltarget')) {
            return ['error' => $error];
        }

        $this->addFlash('danger', $error);

        return $this->redirectToRoute('resetting_check_email', [
            'username' => $user?->getEmail(),
        ]);
    }

    private function getResendCooldownRemaining(User $user = null): int
    {
        if (!$user || !$user->getPasswordRequestedAt()) {
            return 0;
        }

        $requestedAt = $user->getPasswordRequestedAt();
        $elapsed = (new \DateTime())->getTimestamp() - $requestedAt->getTimestamp();
        $remaining = self::RESEND_COOLDOWN_SECONDS - $elapsed;

        return $remaining > 0 ? $remaining : 0;
    }
}
