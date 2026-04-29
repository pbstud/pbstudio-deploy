<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\GiftCardRepository;
use App\Repository\TransactionRepository;
use App\Util\PackageSessionType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/checkout')]
class CheckoutController extends AbstractController
{
    public function __construct(
        private readonly TransactionRepository $transactionRepository,
        private readonly GiftCardRepository $giftCardRepository,
    ) {
    }

    #[Route('/success', name: 'checkout_success')]
    public function success(Request $request): Response
    {
        $session = $request->getSession();

        /** @var User|null $currentUser */
        $currentUser = $this->getUser();
        if (null === $currentUser) {
            return $this->redirectToRoute('homepage');
        }

        $transaction = null;

        if ($session->has('transaction')) {
            $transactionId = (int) $session->get('transaction');
            $session->remove('transaction');

            $transaction = $this->transactionRepository->find($transactionId);
            if (null !== $transaction && $transaction->getUser()?->getId() !== $currentUser->getId()) {
                $transaction = null;
            }
        }

        if (null === $transaction || !$transaction->isPaid()) {
            $lastCompleted = $this->transactionRepository->getLastCompletedByUser($currentUser, 1);
            $transaction = $lastCompleted[0] ?? null;
        }

        if (null === $transaction || !$transaction->isPaid()) {
            return $this->redirectToRoute('profile');
        }

        $package = $transaction->getPackage();
        $packageName = $package?->getAltText();
        if (empty($packageName)) {
            $packageName = $transaction->isPackageIsUnlimited()
                ? 'Paquete ilimitado'
                : sprintf('Paquete de %d clases', (int) $transaction->getPackageTotalClasses());
        }

        $packageType = match ($transaction->getPackageType()) {
            PackageSessionType::TYPE_GROUP => 'Grupal',
            PackageSessionType::TYPE_INDIVIDUAL => 'Individual',
            default => 'General',
        };

        return $this->render('checkout/success.html.twig', [
            'transaction' => $transaction,
            'packageName' => $packageName,
            'packageType' => $packageType,
            'giftCard'    => $this->giftCardRepository->findOneByPurchaseTransactionId((int) $transaction->getId()),
        ]);
    }
}
