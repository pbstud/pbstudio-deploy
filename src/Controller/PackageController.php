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
use App\Service\TransactionService;
use App\Util\PackageSessionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
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
    public function index(BranchOfficeRepository $branchOfficeRepository): Response
    {
        return $this->render('package/index.html.twig', [
            'branchOffices' => $branchOfficeRepository->getPublic(),
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
        EventDispatcherInterface $dispatcher,
    ): Response {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('package_index');
        }

        $restrictNewUser = $package->isNewUser() && $this->hasUserTransactions();

        if (!$restrictNewUser && $request->isMethod('POST') && $request->request->has('conekta_card_token')) {
            $json = [];
            $em->beginTransaction();
            try {
                $coupon = $request->request->get('coupon');
                $transaction = $transactionService->create($package, Transaction::CHARGE_METHOD_CARD);
                $em->persist($transaction);

                $couponService->apply($transaction, $coupon);

                $em->flush();
                $em->commit();
            } catch (\Exception $exception) {
                $em->rollback();
                $json['error'] = $exception->getMessage();

                return $this->json($json);
            }

            $transaction = $conekta->chargeCard($transaction, $request->request->get('conekta_card_token'));

            if ($transaction->isPaid()) {
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

        $template = $this->isGranted('ROLE_USER') ? 'checkout' : 'checkout_login';

        return $this->render(sprintf('package/%s.html.twig', $template), [
            'package' => $package,
            'conektaPublicKey' => $conekta->getPublicKey(),
            'restrictNewUser' => $restrictNewUser,
            'modalTarget' => $modalTarget,
        ]);
    }

    public function groupPackages(): Response
    {
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

        return $this->render('package/_group_packages.html.twig', [
            'group_packages' => $packages,
        ]);
    }

    public function individualPackages(): Response
    {
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

        return $this->render('package/_individual_packages.html.twig', [
            'individual_packages' => $packages,
        ]);
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
