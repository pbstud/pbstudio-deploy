<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\Package;
use App\Entity\Transaction;
use App\Entity\User;
use App\Event\TransactionFailedEvent;
use App\Event\TransactionSuccessEvent;
use App\Form\Backend\TransactionType;
use App\Model\TransactionModel;
use App\Repository\BranchOfficeRepository;
use App\Repository\PackageRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Service\Conekta\ConektaService;
use App\Service\CouponService;
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

        $filterDiscount = [
            Transaction::WITH_DISCOUNT,
            Transaction::WITHOUT_DISCOUNT,
        ];

        return $this->render('backend/transaction/index.html.twig', $filters + [
            'url_export' => $urlExport,
            'branchOffices' => $branchOffices,
            'pagination' => $pagination,
            'packages' => $packages,
            'chargeMethods' => Transaction::chargeMethodChoices(),
            'statusChoices' => Transaction::statusChoices(),
            'total' => $transactionRepository->getSumFilterList($filters),
            'filterDiscount' => $filterDiscount,
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
    ): Response {
        $transactionModel = new TransactionModel();
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
    public function show(Transaction $transaction): Response
    {
        return $this->render('backend/transaction/show.html.twig', [
            'transaction' => $transaction,
        ]);
    }

    #[Route('/{id}/cancel', name: 'backend_transaction_cancel', methods: ['POST'])]
    public function cancel(
        Request $request,
        Transaction $transaction,
        ConektaService $conektaService,
        EntityManagerInterface $em,
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

        try {
            if ('payment.card' === $transaction->getChargeMethod()) {
                $response = $conektaService->chargeRefund($transaction);

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
                $transaction
                    ->setStatus(Transaction::STATUS_CANCEL)
                    ->setRefundedAt(new \DateTime())
                ;

                $em->flush();

                $response['success'] = [
                    'message' => isset($response['warning'])
                        ? 'Transacción cancelada localmente en sandbox.'
                        : 'La transacción ha sido cancelada.',
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
            $sheet->setColumnWidth(18, 12); // Tarjetahabiente
            $sheet->setColumnWidth(12, 13); // Tarjeta
            $sheet->setColumnWidth(18, 14); // Código de autorización
            $sheet->setColumnWidth(15, 15); // Conekta ID
            $sheet->setColumnWidth(20, 16); // Conekta error
            $sheet->setColumnWidth(15, 17); // Sucursal
            $sheet->setColumnWidth(15, 18); // Estado
            $sheet->setColumnWidth(12, 19); // Expirada
            $sheet->setColumnWidth(20, 20); // Fecha de Expiración
            $sheet->setColumnWidth(20, 21); // Fecha de creación

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
}
