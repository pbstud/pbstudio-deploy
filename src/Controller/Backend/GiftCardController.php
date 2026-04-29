<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\GiftCard;
use App\Entity\Staff;
use App\Repository\GiftCardHistoryRepository;
use App\Repository\GiftCardRepository;
use App\Service\GiftCardService;
use App\Util\GiftCardOriginDescription;
use App\Util\GiftCardStatusDescription;
use App\Util\GiftCardStatusResolver;
use App\Util\PackageSessionType;
use Knp\Component\Pager\PaginatorInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ALLOWED_ROUTE_ACCESS')]
#[Route('/backend/gift-card')]
class GiftCardController extends AbstractController
{
    #[Route('/', name: 'backend_gift_card', methods: ['GET'])]
    public function index(
        Request $request,
        GiftCardRepository $giftCardRepository,
        PaginatorInterface $paginator,
    ): Response {
        $filters = [
            'search' => (string) $request->query->get('search', ''),
            'status' => (string) $request->query->get('status', ''),
            'origin' => (string) $request->query->get('origin', ''),
            'date_start' => (string) $request->query->get('date_start', ''),
            'date_end' => (string) $request->query->get('date_end', ''),
        ];

        $filters['date_start'] = $this->normalizeDateFilter($filters['date_start']);
        $filters['date_end'] = $this->normalizeDateFilter($filters['date_end']);

        $giftCards = $giftCardRepository->findForBackendList($filters);

        if ($request->query->has('export')) {
            return $this->export($giftCards->getQuery()->getResult());
        }

        $pagination = $paginator->paginate(
            $giftCards,
            $request->query->getInt('page', 1),
            20,
            ['distinct' => false],
        );

        $urlExport = $this->generateUrl(
            'backend_gift_card',
            $filters + ['export' => true]
        );

        return $this->render('backend/gift_card/index.html.twig', [
            'pagination' => $pagination,
            'filters' => $filters,
            'url_export' => $urlExport,
            'statusChoices' => GiftCard::statusChoices(),
            'originChoices' => GiftCard::originChoices(),
        ]);
    }

    #[Route('/{id}', name: 'backend_gift_card_show', methods: ['GET'])]
    public function show(
        GiftCard $giftCard,
        GiftCardHistoryRepository $giftCardHistoryRepository,
    ): Response {
        return $this->render('backend/gift_card/show.html.twig', [
            'giftCard' => $giftCard,
            'history' => $giftCardHistoryRepository->findByGiftCard($giftCard->getId()),
        ]);
    }

    #[Route('/{id}/resend', name: 'backend_gift_card_resend', methods: ['POST'])]
    public function resend(
        Request $request,
        GiftCard $giftCard,
        GiftCardService $giftCardService,
    ): Response {
        if (!$this->isCsrfTokenValid('backend_gift_card_resend_' . $giftCard->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF invalido.');

            return $this->redirectToRoute('backend_gift_card_show', ['id' => $giftCard->getId()]);
        }

        $staff = $this->getUser();
        if (!$staff instanceof Staff) {
            $this->addFlash('danger', 'Solo staff puede ejecutar acciones administrativas.');

            return $this->redirectToRoute('backend_gift_card_show', ['id' => $giftCard->getId()]);
        }

        try {
            $giftCardService->registerResend($giftCard, null, $staff);
            $this->addFlash('success', 'Se registro el reenvio de la gift card en el historial.');
        } catch (\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('backend_gift_card_show', ['id' => $giftCard->getId()]);
    }

    #[Route('/{id}/cancel', name: 'backend_gift_card_cancel', methods: ['POST'])]
    public function cancel(
        Request $request,
        GiftCard $giftCard,
        GiftCardService $giftCardService,
    ): Response {
        if (!$this->isCsrfTokenValid('backend_gift_card_cancel_' . $giftCard->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF invalido.');

            return $this->redirectToRoute('backend_gift_card_show', ['id' => $giftCard->getId()]);
        }

        $staff = $this->getUser();
        if (!$staff instanceof Staff) {
            $this->addFlash('danger', 'Solo staff puede ejecutar acciones administrativas.');

            return $this->redirectToRoute('backend_gift_card_show', ['id' => $giftCard->getId()]);
        }

        $reason = trim((string) $request->request->get('reason', 'Cancelada desde backend'));

        try {
            $giftCardService->cancel($giftCard, $reason, null, $staff);
            $this->addFlash('success', 'Gift card cancelada correctamente.');
        } catch (\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('backend_gift_card_show', ['id' => $giftCard->getId()]);
    }

    private function normalizeDateFilter(string $rawDate): string
    {
        $normalized = preg_replace('/[^0-9]+/', '/', trim($rawDate));
        if (null === $normalized || '' === trim($normalized, '/')) {
            return '';
        }

        $parts = explode('/', trim($normalized, '/'));
        if (3 !== \count($parts)) {
            return trim($rawDate);
        }

        [$day, $month, $year] = $parts;
        if (!ctype_digit($day) || !ctype_digit($month) || !ctype_digit($year)) {
            return trim($rawDate);
        }

        $dayInt = (int) $day;
        $monthInt = (int) $month;
        $yearInt = (int) $year;

        if (!checkdate($monthInt, $dayInt, $yearInt)) {
            return trim($rawDate);
        }

        return sprintf('%02d/%02d/%04d', $dayInt, $monthInt, $yearInt);
    }

    private function export(array $giftCards): Response
    {
        $filename = sprintf('TarjetasRegalo_%s.xlsx', date('Y-m-d_H-i'));
        $tmpFile = sys_get_temp_dir() . '/' . uniqid('gift_cards_export_', true) . '.xlsx';

        try {
            $writer = new Writer();
            $writer->openToFile($tmpFile);

            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Tarjetas regalo');

            $writer->addRow(Row::fromValues([
                'ID',
                'Codigo',
                'Comprador',
                'Email comprador',
                'Receptor',
                'Email receptor',
                'Monto',
                'Paquete',
                'Modalidad',
                'Clases',
                'Estado',
                'Canal',
                'Compra',
                'Canje',
            ]));

            /** @var GiftCard $giftCard */
            foreach ($giftCards as $giftCard) {
                $purchaser = $giftCard->getPurchaserUser();
                $recipient = $giftCard->getRecipientUser();

                $writer->addRow(Row::fromValues([
                    $giftCard->getId(),
                    $giftCard->getCode(),
                    $purchaser ? (string) $purchaser : null,
                    $purchaser ? $purchaser->getEmail() : null,
                    $recipient ? (string) $recipient : null,
                    $recipient ? $recipient->getEmail() : null,
                    $giftCard->getAmountSnapshot(),
                    $giftCard->getPackageNameSnapshot(),
                    PackageSessionType::getDescription((string) $giftCard->getPackageTypeSnapshot()),
                    $giftCard->getPackageTotalClassesSnapshot(),
                    GiftCardStatusResolver::getDescription($giftCard),
                    GiftCardOriginDescription::getDescription((string) $giftCard->getOriginChannel()),
                    $giftCard->getPurchasedAt() ? $giftCard->getPurchasedAt()->format('d/m/Y H:i:s') : null,
                    $giftCard->getRedeemedAt() ? $giftCard->getRedeemedAt()->format('d/m/Y H:i:s') : null,
                ]));
            }

            $sheet->setColumnWidth(8, 1);
            $sheet->setColumnWidth(20, 2);
            $sheet->setColumnWidth(24, 3);
            $sheet->setColumnWidth(28, 4);
            $sheet->setColumnWidth(24, 5);
            $sheet->setColumnWidth(28, 6);
            $sheet->setColumnWidth(12, 7);
            $sheet->setColumnWidth(22, 8);
            $sheet->setColumnWidth(14, 9);
            $sheet->setColumnWidth(10, 10);
            $sheet->setColumnWidth(14, 11);
            $sheet->setColumnWidth(12, 12);
            $sheet->setColumnWidth(20, 13);
            $sheet->setColumnWidth(20, 14);

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
