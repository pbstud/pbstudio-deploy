<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Event\TransactionSuccessEvent;
use App\Service\GiftCardService;
use App\Service\TransactionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/gift-card')]
#[IsGranted('ROLE_USER')]
class GiftCardController extends AbstractController
{
    #[Route('/redeem', name: 'gift_card_redeem', methods: ['POST'])]
    public function redeem(
        Request $request,
        GiftCardService $giftCardService,
        TransactionService $transactionService,
        EventDispatcherInterface $dispatcher,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        $isAjax = $request->isXmlHttpRequest();

        if (!$user instanceof User) {
            if (!$isAjax) {
                throw $this->createAccessDeniedException('Usuario no autenticado.');
            }

            return $this->json([
                'error' => [
                    'message' => 'Usuario no autenticado.',
                ],
            ], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $code = (string) $request->request->get('code', '');
        $csrfToken = (string) $request->request->get('_token', '');

        if (!$this->isCsrfTokenValid('gift_card_redeem', $csrfToken)) {
            if ($isAjax) {
                return $this->json([
                    'error' => [
                        'message' => 'Token CSRF invalido.',
                    ],
                ], JsonResponse::HTTP_FORBIDDEN);
            }

            $this->addFlash('danger', 'No se pudo validar la solicitud de canje.');

            return $this->redirectToRoute('profile');
        }

        try {
            $giftCard = $giftCardService->redeemByCodeForUser($code, $user, $transactionService);
            $transaction = $giftCard->getRedemptionTransaction();

            if (null !== $transaction) {
                $dispatcher->dispatch(new TransactionSuccessEvent($transaction));
            }

            if (!$isAjax) {
                $this->addFlash('success', 'Gift card canjeada correctamente.');

                return $this->redirectToRoute('profile');
            }

            $packageType = (string) $giftCard->getPackageTypeSnapshot();
            $packageTypeLabel = match ($packageType) {
                'individual' => 'Individual',
                'group' => 'Grupal',
                default => ucfirst($packageType),
            };

            return $this->json([
                'success' => [
                    'message' => 'Gift card canjeada correctamente.',
                ],
                'giftCard' => [
                    'id' => $giftCard->getId(),
                    'code' => $giftCard->getCode(),
                    'status' => $giftCard->getStatus(),
                    'package' => [
                        'name' => (string) $giftCard->getPackageNameSnapshot(),
                        'type' => $packageType,
                        'typeLabel' => $packageTypeLabel,
                        'totalClasses' => (int) $giftCard->getPackageTotalClassesSnapshot(),
                        'daysExpiry' => (int) $giftCard->getPackageDaysExpirySnapshot(),
                        'valueAmount' => number_format((float) $giftCard->getAmountSnapshot(), 0),
                        'currency' => (string) $giftCard->getCurrencySnapshot(),
                    ],
                ],
            ]);
        } catch (\InvalidArgumentException | \LogicException $e) {
            if (!$isAjax) {
                $this->addFlash('danger', $e->getMessage());

                return $this->redirectToRoute('profile');
            }

            return $this->json([
                'error' => [
                    'message' => $e->getMessage(),
                ],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            if (!$isAjax) {
                $this->addFlash('danger', 'No se pudo canjear la gift card.');

                return $this->redirectToRoute('profile');
            }

            return $this->json([
                'error' => [
                    'message' => 'No se pudo canjear la gift card: ' . $e->getMessage(),
                ],
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
