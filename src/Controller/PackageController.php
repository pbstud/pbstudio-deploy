<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Package;
use App\Entity\Transaction;
use App\Entity\User;
use App\Event\TransactionFailedEvent;
use App\Event\TransactionSuccessEvent;
use App\Repository\BranchOfficeRepository;
use App\Repository\PackageRepository;
use App\Repository\TransactionRepository;
use App\Service\Conekta\ConektaService;
use App\Service\CouponService;
use App\Service\GiftCardService;
use App\Service\TransactionService;
use App\Util\PackageSessionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class PackageController extends AbstractController
{
    public function __construct(
        private readonly PackageRepository $packageRepository,
        private readonly TransactionRepository $transactionRepository,
    ) {
    }

    #[Route('/paquetes', name: 'package_index', methods: ['GET'])]
    public function index(Request $request, BranchOfficeRepository $branchOfficeRepository): Response
    {
        return $this->render('package/index.html.twig', [
            'branchOffices' => $branchOfficeRepository->getPublic(),
            'isGiftMode' => $request->query->getBoolean('gift'),
        ]);
    }

    #[Route('/paquete/{id}/comprar', name: 'package_checkout', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function checkout(
        Request $request,
        Package $package,
        TransactionService $transactionService,
        EntityManagerInterface $em,
        ConektaService $conekta,
        CouponService $couponService,
        GiftCardService $giftCardService,
        EventDispatcherInterface $dispatcher,
    ): Response {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('package_index');
        }

        $restrictNewUser = $package->isNewUser() && $this->hasUserTransactions();

        if (!$restrictNewUser && $request->isMethod('POST') && $request->request->has('conekta_card_token')) {
            $json = [];
            $isGiftPurchase = $request->request->getBoolean('is_gift_purchase');

            // Validar formato del token (solo lo genera Conekta; si llega malformado es manipulación)
            $token = (string) $request->request->get('conekta_card_token', '');
            if (!preg_match('/^[a-zA-Z0-9_\-]{10,60}$/', $token)) {
                return $this->json(['error' => 'Token de pago inválido.']);
            }

            // Validar cupón si viene (solo alfanumérico + guiones)
            $couponCode = (string) $request->request->get('coupon', '');
            if ($couponCode !== '' && !preg_match('/^[a-zA-Z0-9\-]{1,50}$/', $couponCode)) {
                return $this->json(['error' => 'El código de cupón contiene caracteres no permitidos.']);
            }

            $em->beginTransaction();
            try {
                $transaction = $transactionService->create($package, Transaction::CHARGE_METHOD_CARD);
                $em->persist($transaction);

                $couponService->apply($transaction, $couponCode ?: null);

                $em->flush();
                $em->commit();
            } catch (\Exception $exception) {
                $em->rollback();
                $json['error'] = $exception->getMessage();

                return $this->json($json);
            }

            $transaction = $conekta->chargeCard($transaction, $token);

            if ($transaction->isPaid()) {
                if ($isGiftPurchase) {
                    $transaction
                        ->setHaveSessionsAvailable(false)
                        ->setExpirationAt(null)
                    ;

                    $em->persist($transaction);
                    $em->flush();

                    $giftCardService->createFromPurchaseTransaction($transaction, 'frontend');
                }

                $event = new TransactionSuccessEvent($transaction);
                $dispatcher->dispatch($event);

                $request->getSession()->set('transaction', $transaction->getId());
                $json['redirect'] = $this->generateUrl('checkout_success');
            } else {
                $dispatcher->dispatch(new TransactionFailedEvent($transaction));
                $json['error'] = $transaction->getErrorMessage() ?: 'Error en transacción';
            }

            return $this->json($json);
        }

        $modalTarget = $this->generateUrl('package_checkout', [
            'id' => $package->getId(),
        ]);
        $modalTarget = str_replace('/', '__', $modalTarget);

        $isGiftMode = $request->query->getBoolean('gift');
        $template = $this->isGranted('ROLE_USER') ? 'checkout' : 'checkout_login';

        return $this->render(sprintf('package/%s.html.twig', $template), [
            'package'           => $package,
            'conektaPublicKey'  => $conekta->getPublicKey(),
            'restrictNewUser'   => $restrictNewUser,
            'modalTarget'       => $modalTarget,
            'isGiftMode'        => $isGiftMode,
        ]);
    }

    public function groupPackages(Request $request): Response
    {
        $isGiftMode = (bool) ($request->attributes->get('gift') ?? $request->query->getBoolean('gift'));

        $filters = [
            'type' => PackageSessionType::TYPE_GROUP,
            'isActive' => true,
            'public' => true,
        ];

        if ($this->hasUserTransactions()) {
            $filters['newUser'] = false;
        }

        $packages = $this->packageRepository->findBy($filters, [
            'newUser' => 'DESC',
            'amount' => 'ASC',
        ]);

        $packages = $this->sortPackagesUnlimitedLast($packages);

        return $this->render('package/_group_packages.html.twig', [
            'group_packages' => $packages,
            'isGiftMode' => $isGiftMode,
        ]);
    }

    public function individualPackages(Request $request): Response
    {
        $isGiftMode = (bool) ($request->attributes->get('gift') ?? $request->query->getBoolean('gift'));

        $filters = [
            'type' => PackageSessionType::TYPE_INDIVIDUAL,
            'isActive' => true,
            'public' => true,
        ];

        if ($this->hasUserTransactions()) {
            $filters['newUser'] = false;
        }

        $packages = $this->packageRepository->findBy($filters, [
            'newUser' => 'DESC',
            'amount' => 'ASC',
        ]);

        $packages = $this->sortPackagesUnlimitedLast($packages);

        return $this->render('package/_individual_packages.html.twig', [
            'individual_packages' => $packages,
            'isGiftMode' => $isGiftMode,
        ]);
    }

    /**
     * Sort packages so unlimited ones appear last, preserving relative order within each group.
     *
     * @param Package[] $packages
     * @return Package[]
     */
    private function sortPackagesUnlimitedLast(array $packages): array
    {
        $regular   = array_values(array_filter($packages, fn ($p) => !$p->isIsUnlimited()));
        $unlimited = array_values(array_filter($packages, fn ($p) =>  $p->isIsUnlimited()));

        return array_merge($regular, $unlimited);
    }

    /**
     * Has user transactions.
     *
     * @return bool
     */
    private function hasUserTransactions(): bool
    {
        if (!$this->isGranted('ROLE_USER')) {
            return false;
        }

        /** @var User $user */
        $user = $this->getUser();

        return $this->transactionRepository->hasTransactionsByUser($user);
    }
}
