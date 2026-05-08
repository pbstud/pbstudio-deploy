<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\Package;
use App\Entity\Staff;
use App\Entity\Transaction;
use App\Entity\GiftCard;
use App\Entity\User;
use App\Event\TransactionFailedEvent;
use App\Event\TransactionSuccessEvent;
use App\Form\Backend\TransactionType;
use App\Model\TransactionModel;
use App\Repository\BranchOfficeRepository;
use App\Repository\GiftCardRepository;
use App\Repository\PackageRepository;
use App\Repository\TransactionFreezeLogRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Service\Conekta\ConektaService;
use App\Service\CouponService;
use App\Service\GiftCardService;
use App\Service\Mailer\TransactionMailer;
use App\Service\TransactionService;
use App\Util\ChargeMethodDescription;
use App\Util\PackageSessionType;
use App\Util\TransactionStatusDescription;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ALLOWED_ROUTE_ACCESS')]
#[Route('/backend/transaction')]
class TransactionController extends AbstractController
{
    #[Route('/', name: 'backend_transaction', methods: ['GET'])]
    public function index(
        Request $request,
        PaginatorInterface $paginator,
        TransactionRepository $transactionRepository,
        BranchOfficeRepository $branchOfficeRepository,
        PackageRepository $packageRepository,
    ) {
        // Filters
        $currentDate = new \DateTime();

        $filters = [];
        $filters['filter_search'] = $request->query->get('filter_search');
        $filters['filter_status'] = $request->query->get('filter_status');
        $filters['filter_date_start'] = $request->query->get('filter_date_start', $currentDate->modify('FIRST DAY OF THIS MONTH')->format('d/m/Y'));
        $filters['filter_date_end'] = $request->query->get('filter_date_end', $currentDate->modify('LAST DAY OF THIS MONTH')->format('d/m/Y'));
        $filters['filter_branchOffice'] = $request->query->get('filter_branchOffice');
        $filters['filter_package'] = $request->query->get('filter_package');
        $filters['filter_charge_method'] = $request->query->get('filter_charge_method');
        $filters['filter_discount'] = $request->query->get('filter_discount');
        $filters['filter_gift_purchase'] = $request->query->get('filter_gift_purchase');

        $transactions = $transactionRepository->findForBackendList($filters);

        // Export
        if ($request->query->has('export')) {
            return $this->export($transactions->getQuery()->getResult());
        }

        $pagination = $paginator->paginate(
            $transactions,
            $request->query->getInt('page', 1),
            Transaction::NUMBER_OF_ITEMS,
            ['distinct' => false],
        );

        $urlExport = $this->generateUrl(
            'backend_transaction',
            $filters + ['export' => true]
        );

        $branchOffices = $branchOfficeRepository->findAll();
        $packages = $packageRepository->getAllActive();
        $packagesByType = [
            PackageSessionType::TYPE_INDIVIDUAL => [],
            PackageSessionType::TYPE_GROUP => [],
        ];

        foreach ($packages as $package) {
            if (!$package->isIsActive()) {
                continue;
            }

            $type = $package->getType();
            if (PackageSessionType::TYPE_INDIVIDUAL === $type || PackageSessionType::TYPE_GROUP === $type) {
                $packagesByType[$type][] = $package;
            }
        }

        $filterDiscount = [
            Transaction::PERCENTAGE_DISCOUNT,
            Transaction::WITHOUT_DISCOUNT,
            Transaction::SPECIAL_PRICE,
        ];

        $filterGiftPurchase = [
            Transaction::WITH_GIFT_PURCHASE,
            Transaction::WITHOUT_GIFT_PURCHASE,
        ];

        $chargeMethods = Transaction::chargeMethodChoices();
        unset($chargeMethods[Transaction::CHARGE_METHOD_GIFT]);

        return $this->render('backend/transaction/index.html.twig', $filters + [
            'url_export' => $urlExport,
            'branchOffices' => $branchOffices,
            'pagination' => $pagination,
            'packagesByType' => $packagesByType,
            'chargeMethods' => $chargeMethods,
            'statusChoices' => Transaction::statusChoices(),
            'total' => $transactionRepository->getSumFilterList($filters),
            'filterDiscount' => $filterDiscount,
            'filterGiftPurchase' => $filterGiftPurchase,
        ]);
    }

    #[Route('/new', name: 'backend_transaction_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        TransactionService $transactionService,
        CouponService $couponService,
        ConektaService $conekta,
        EventDispatcherInterface $dispatcher,
        EntityManagerInterface $em,
        GiftCardService $giftCardService,
        GiftCardRepository $giftCardRepository,
        TransactionMailer $transactionMailer,
    ): Response {
        $transactionModel = new TransactionModel();
        $giftCardCode = null;
        $giftCardShareText = null;

        $giftCardId = $request->query->getInt('gift_card_id', 0);
        if ($giftCardId > 0) {
            $giftCard = $giftCardRepository->find($giftCardId);
            if (null !== $giftCard) {
                $giftCardCode = $giftCard->getCode();
                $giftCardShareText = sprintf('Tu codigo de tarjeta de regalo de P&B Studio es: %s', $giftCard->getCode());
            }
        }

        $form = $this->createForm(TransactionType::class, $transactionModel);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var Package $package */
            $package = $transactionModel->getPackage();

            try {
                $em->beginTransaction();
                try {
                    $transaction = $transactionService->create(
                        $package,
                        $transactionModel->getChargeMethod(),
                        $transactionModel->getUser(),
                        $transactionModel->getBranchOffice(),
                        $transactionModel->getDiscount()
                    );
                    $em->persist($transaction);

                    if (Transaction::CHARGE_METHOD_FREE !== $transactionModel->getChargeMethod()) {
                        $couponService->apply($transaction, $transactionModel->getCoupon());
                    }

                    $em->flush();
                    $em->commit();
                } catch (\Exception $exception) {
                    $em->rollback();

                    throw $exception;
                }

                if (Transaction::CHARGE_METHOD_CARD === $transactionModel->getChargeMethod()) {
                    $transaction = $conekta->chargeCard($transaction, $request->request->get('conekta_card_token'));

                    if (Transaction::STATUS_PAID !== $transaction->getStatus()) {
                        $dispatcher->dispatch(new TransactionFailedEvent($transaction));
                        throw new \Exception($transaction->getErrorMessage());
                    }
                } else {
                    $expirationAt = (new \DateTime())
                        ->add(new \DateInterval(sprintf('P%sD', $package->getDaysExpiry())))
                    ;

                    $transaction
                        ->setChargeAuthCode($transactionModel->getChargeAuthCode())
                        ->setCardLast4($transactionModel->getCardLast4())
                        ->setStatus(Transaction::STATUS_PAID)
                        ->setExpirationAt($expirationAt)
                        ->setHaveSessionsAvailable(true)
                    ;

                    $em->persist($transaction);
                    $em->flush();
                }

                if ($transactionModel->isGiftPurchase()) {
                    $transaction
                        ->setHaveSessionsAvailable(false)
                        ->setExpirationAt(null)
                    ;

                    $em->persist($transaction);
                    $em->flush();

                    $giftCard = $giftCardService->createFromPurchaseTransaction($transaction, 'backend');
                    $transactionMailer->sendGiftCardCodeEmail($giftCard, $transaction->getUser());

                    $event = new TransactionSuccessEvent($transaction);
                    $dispatcher->dispatch($event);

                    $this->addFlash('success', 'La Transacción ha sido creada.');

                    return $this->redirectToRoute('backend_transaction_new', [
                        'gift_card_id' => $giftCard->getId(),
                    ]);
                }

                $event = new TransactionSuccessEvent($transaction);
                $dispatcher->dispatch($event);

                $this->addFlash('success', 'La Transacción ha sido creada.');

                return $this->redirectToRoute('backend_transaction_new');
            } catch (\Exception $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('backend/transaction/new.html.twig', [
            'conekta_public_key' => $conekta->getPublicKey(),
            'form' => $form->createView(),
            'transactionModel' => $transactionModel,
            'giftCardCode' => $giftCardCode,
            'giftCardShareText' => $giftCardShareText,
        ]);
    }

    #[Route('/user-search', name: 'backend_transaction_user_search', methods: ['GET'])]
    public function userSearch(Request $request, UserRepository $userRepository): Response
    {
        $term = preg_replace('/\s+/', ' ', trim((string) $request->query->get('q', ''))) ?? '';

        if ('' === $term || (mb_strlen($term) < 2 && !ctype_digit($term))) {
            return $this->json([
                'items' => [],
            ]);
        }

        $limit = max(1, min(50, $request->query->getInt('limit', 20)));
        $rows = $userRepository->searchEnabledForSelect($term, $limit);

        $items = array_map(static function (array $row): array {
            $fullName = trim(sprintf('%s %s', $row['name'] ?? '', $row['lastname'] ?? ''));

            return [
                'id' => (string) $row['id'],
                'email' => (string) $row['email'],
                'text' => '' !== $fullName ? $fullName : 'Sin nombre',
            ];
        }, $rows);

        return $this->json([
            'items' => $items,
        ]);
    }

    #[Route('/{id}', name: 'backend_transaction_show', methods: ['GET'])]
    public function show(Transaction $transaction, TransactionFreezeLogRepository $freezeLogRepository): Response
    {
        return $this->render('backend/transaction/show.html.twig', [
            'transaction' => $transaction,
            'freezeLogs' => $freezeLogRepository->findByTransaction($transaction->getId()),
        ]);
    }

    #[Route('/{id}/freeze', name: 'backend_transaction_freeze', methods: ['POST'])]
    public function freeze(Request $request, Transaction $transaction, TransactionService $transactionService): Response
    {
        if (!$this->isCsrfTokenValid('backend_transaction_freeze_' . $transaction->getId(), (string) $request->request->get('_token'))) {
            return $this->json([
                'error' => [
                    'message' => 'Token CSRF inválido.',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        $staff = $this->getUser();
        if (!$staff instanceof Staff) {
            return $this->json([
                'error' => [
                    'message' => 'Solo el personal administrativo puede congelar transacciones.',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        $reason = (string) $request->request->get('reason', '');

        try {
            $transactionService->freeze($transaction, $staff, $reason);

            return $this->json([
                'success' => [
                    'message' => 'La transacción fue congelada correctamente.',
                ],
            ]);
        } catch (\InvalidArgumentException | \LogicException $e) {
            return $this->json([
                'error' => [
                    'message' => $e->getMessage(),
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => [
                    'message' => 'No se pudo congelar la transacción: ' . $e->getMessage(),
                ],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}/unfreeze', name: 'backend_transaction_unfreeze', methods: ['POST'])]
    public function unfreeze(Request $request, Transaction $transaction, TransactionService $transactionService): Response
    {
        if (!$this->isCsrfTokenValid('backend_transaction_unfreeze_' . $transaction->getId(), (string) $request->request->get('_token'))) {
            return $this->json([
                'error' => [
                    'message' => 'Token CSRF inválido.',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        $staff = $this->getUser();
        if (!$staff instanceof Staff) {
            return $this->json([
                'error' => [
                    'message' => 'Solo el personal administrativo puede descongelar transacciones.',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        $reason = (string) $request->request->get('reason', '');

        try {
            $transactionService->unfreeze($transaction, $staff, $reason);

            return $this->json([
                'success' => [
                    'message' => 'La transacción fue descongelada correctamente.',
                ],
            ]);
        } catch (\InvalidArgumentException | \LogicException $e) {
            return $this->json([
                'error' => [
                    'message' => $e->getMessage(),
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => [
                    'message' => 'No se pudo descongelar la transacción: ' . $e->getMessage(),
                ],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}/cancel', name: 'backend_transaction_cancel', methods: ['POST'])]
    public function cancel(
        Request $request,
        Transaction $transaction,
        ConektaService $conektaService,
        EntityManagerInterface $em,
        GiftCardRepository $giftCardRepository,
    ): Response {
        if (!$this->isCsrfTokenValid('backend_transaction_cancel_'.$transaction->getId(), (string) $request->request->get('_token'))) {
            return $this->json([
                'error' => [
                    'message' => 'Token CSRF inválido.',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        if (Transaction::STATUS_PAID !== $transaction->getStatus()) {
            return $this->json([
                'error' => [
                    'message' => 'Solo se pueden cancelar transacciones en estado pagado.',
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $response = [];
        $canCancelLocally = true;
        $transactionsToCancel = [$transaction];

        $giftCard = $giftCardRepository->findOneByRedemptionTransaction($transaction);
        if (null === $giftCard) {
            $giftCard = $giftCardRepository->findOneByPurchaseTransactionId((int) $transaction->getId());
        }

        if (null !== $giftCard) {
            $purchaseTransaction = $giftCard->getPurchaseTransaction();
            $redemptionTransaction = $giftCard->getRedemptionTransaction();

            if (null !== $purchaseTransaction && $purchaseTransaction->getId() !== $transaction->getId()) {
                $transactionsToCancel[] = $purchaseTransaction;
            }

            if (null !== $redemptionTransaction && $redemptionTransaction->getId() !== $transaction->getId()) {
                $transactionsToCancel[] = $redemptionTransaction;
            }
        }

        try {
            $transactionForRefund = null;
            foreach ($transactionsToCancel as $candidateTransaction) {
                if (
                    Transaction::STATUS_PAID === $candidateTransaction->getStatus()
                    && Transaction::CHARGE_METHOD_CARD === $candidateTransaction->getChargeMethod()
                ) {
                    $transactionForRefund = $candidateTransaction;
                    break;
                }
            }

            if (null !== $transactionForRefund) {
                $response = $conektaService->chargeRefund($transactionForRefund);

                if (isset($response['error'])) {
                    if ($conektaService->isSandboxModeEnabled()) {
                        $response['warning'] = [
                            'message' => 'Conekta Sandbox no procesó el reembolso; se aplicó cancelación administrativa local.',
                        ];
                    } else {
                        $canCancelLocally = false;
                    }
                }
            }

            if ($canCancelLocally) {
                $cancelledCount = 0;
                foreach ($transactionsToCancel as $candidateTransaction) {
                    if (Transaction::STATUS_PAID !== $candidateTransaction->getStatus()) {
                        continue;
                    }

                    $candidateTransaction
                        ->setStatus(Transaction::STATUS_CANCEL)
                        ->setRefundedAt(new \DateTime())
                    ;

                    ++$cancelledCount;
                }

                $em->flush();

                // Actualizar la entidad gift card con cancelledAt cuando se cancela via transaccion
                // (aplica independientemente del estado previo de la gift card).
                if (null !== $giftCard && GiftCard::STATUS_CANCELLED !== $giftCard->getStatus()) {
                    $giftCard
                        ->setStatus(GiftCard::STATUS_CANCELLED)
                        ->setCancelledAt(new \DateTimeImmutable())
                    ;
                    $em->persist($giftCard);
                    $em->flush();
                }

                $response['success'] = [
                    'message' => isset($response['warning'])
                        ? sprintf('Transacción(es) cancelada(s) localmente en sandbox (%d).', $cancelledCount)
                        : sprintf('Se cancelaron %d transacción(es) vinculada(s).', $cancelledCount),
                ];
            } else {
                return $this->json($response, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } catch (\Throwable $e) {
            return $this->json([
                'error' => [
                    'message' => 'No se pudo cancelar la transacción: '.$e->getMessage(),
                ],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json($response);
    }

    #[Route('/{id}/edit-expiration', name: 'backend_transaction_edit_expiration', methods: ['POST'])]
    public function editExpiration(Request $request, Transaction $transaction, EntityManagerInterface $em): Response
    {
        try {
            $expirationDate = \DateTime::createFromFormat('d/m/Y', $request->request->get('expiration_date'));

            if (!$expirationDate) {
                throw new \Exception('Fecha inválida');
            }

            $expirationDate = $expirationDate->setTime(0, 0);
            $today = new \DateTime('today');

            if ($expirationDate < $today) {
                throw new \Exception('La fecha no puede ser menor a hoy.');
            }

            $transaction->setExpirationAt($expirationDate);
            $em->flush();

            $this->addFlash('success', 'La fecha de expiración ha sido actualizada.');
        } catch (\Exception $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('backend_transaction_show', [
            'id' => $transaction->getId(),
        ]);
    }

    private function export(array $transactions): Response
    {
        $filename = sprintf('Transacciones_%s.xlsx', date('Y-m-d_H-i'));
        $tmpFile = sys_get_temp_dir() . '/' . uniqid('transactions_export_', true) . '.xlsx';

        try {
            $writer = new Writer();
            $writer->openToFile($tmpFile);

            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Transacciones');

            // Header row
            $writer->addRow(Row::fromValues([
                'ID',
                'Usuario',
                'Email',
                'Paquete',
                'Modalidad',
                'Monto',
                'Precio Especial',
                'Cupón Descuento',
                'Descuento Adicional',
                'Total',
                'Método de pago',
                'Flujo regalo',
                'Tarjetahabiente',
                'Tarjeta',
                'Código de autorización',
                'Conekta ID',
                'Conekta error',
                'Sucursal',
                'Estado',
                'Expirada',
                'Fecha de Expiración',
                'Fecha de creación',
            ]));

            // Data rows
            /** @var Transaction $transaction */
            foreach ($transactions as $transaction) {
                /** @var User $user */
                $user = $transaction->getUser();

                $writer->addRow(Row::fromValues([
                    $transaction->getId(),
                    $user->getName(),
                    $user->getEmail(),
                    sprintf('%s clase(s)', $transaction->getPackageTotalClasses()),
                    PackageSessionType::getDescription($transaction->getPackageType()),
                    $transaction->getPackageAmount(),
                    $transaction->getPackageSpecialPrice(),
                    $transaction->getCouponDiscount().'%',
                    $transaction->getDiscount().'%',
                    $transaction->getTotal(),
                    ChargeMethodDescription::getDescription($transaction->getChargeMethod()),
                    $this->getGiftFlowLabel($transaction),
                    $transaction->getCardName(),
                    $transaction->getCardLast4(),
                    $transaction->getChargeAuthCode(),
                    $transaction->getChargeId(),
                    $transaction->getErrorMessage(),
                    $transaction->getBranchOffice(),
                    TransactionStatusDescription::getDescription($transaction->getStatus()),
                    $transaction->isIsExpired() ? 'Si' : 'No',
                    $transaction->getExpirationAt() ? $transaction->getExpirationAt()->format('d/m/Y H:i:s') : null,
                    $transaction->getCreatedAt()->format('d/m/Y H:i:s'),
                ]));
            }

            // Set column widths
            $sheet->setColumnWidth(8, 1); // ID
            $sheet->setColumnWidth(15, 2); // Usuario
            $sheet->setColumnWidth(20, 3); // Email
            $sheet->setColumnWidth(15, 4); // Paquete
            $sheet->setColumnWidth(12, 5); // Modalidad
            $sheet->setColumnWidth(10, 6); // Monto
            $sheet->setColumnWidth(15, 7); // Precio Especial
            $sheet->setColumnWidth(12, 8); // Cupón Descuento
            $sheet->setColumnWidth(15, 9); // Descuento Adicional
            $sheet->setColumnWidth(10, 10); // Total
            $sheet->setColumnWidth(18, 11); // Método de pago
            $sheet->setColumnWidth(16, 12); // Flujo regalo
            $sheet->setColumnWidth(18, 13); // Tarjetahabiente
            $sheet->setColumnWidth(12, 14); // Tarjeta
            $sheet->setColumnWidth(18, 15); // Código de autorización
            $sheet->setColumnWidth(15, 16); // Conekta ID
            $sheet->setColumnWidth(20, 17); // Conekta error
            $sheet->setColumnWidth(15, 18); // Sucursal
            $sheet->setColumnWidth(15, 19); // Estado
            $sheet->setColumnWidth(12, 20); // Expirada
            $sheet->setColumnWidth(20, 21); // Fecha de Expiración
            $sheet->setColumnWidth(20, 22); // Fecha de creación

            $writer->close();

            $response = new BinaryFileResponse($tmpFile);
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
            $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

            return $response;
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Error generando archivo: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function getGiftFlowLabel(Transaction $transaction): string
    {
        if (Transaction::CHARGE_METHOD_GIFT === $transaction->getChargeMethod()) {
            return 'Canje regalo';
        }

        if (
            Transaction::STATUS_PAID === $transaction->getStatus()
            && !$transaction->isHaveSessionsAvailable()
            && \in_array($transaction->getChargeMethod(), [
                Transaction::CHARGE_METHOD_CARD,
                Transaction::CHARGE_METHOD_CASH,
                Transaction::CHARGE_METHOD_POS,
            ], true)
        ) {
            return 'Compra regalo';
        }

        return '-';
    }
}

