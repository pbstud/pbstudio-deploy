<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\Reservation;
use App\Entity\Session;
use Doctrine\ORM\EntityManagerInterface;
use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ALLOWED_ROUTE_ACCESS')]
#[Route('/backend/reservation')]
class ReservationController extends AbstractController
{
    #[Route('/{id}/attended', name: 'backend_reservation_attended', methods: ['POST'])]
    public function attended(
        Request $request,
        Reservation $reservation,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('backend_reservation_attended_'.$reservation->getId(), (string) $request->request->get('_token'))) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid CSRF token.',
            ], Response::HTTP_FORBIDDEN);
        }

        $reservation->setAttended($request->request->getBoolean('attended'));
        $em->flush();

        return $this->json([
            'success' => true,
        ]);
    }

    #[Route('/export', name: 'backend_reservations_export', methods: ['GET'])]
    public function export(
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        // Get filters from request
        $filters = [
            'filter_status' => trim((string) $request->query->get('filter_status', '')),
            'filter_session_date_start' => trim((string) $request->query->get('filter_session_date_start', '')),
            'filter_session_date_end' => trim((string) $request->query->get('filter_session_date_end', '')),
            'filter_branch_office' => trim((string) $request->query->get('filter_branch_office', '')),
            'filter_exercise_room' => trim((string) $request->query->get('filter_exercise_room', '')),
        ];

        $qb = $em->createQueryBuilder();
        $qb
            ->select('r.id AS reservationId')
            ->addSelect('r.placeNumber')
            ->addSelect('r.attended')
            ->addSelect('r.isAvailable')
            ->addSelect('r.cancellationAt')
            ->addSelect('r.createdAt AS reservedAt')
            ->addSelect('s.id AS sessionId')
            ->addSelect('s.dateStart')
            ->addSelect('s.timeStart')
            ->addSelect('er.name AS exerciseRoomName')
            ->addSelect('u.id AS userId')
            ->addSelect('u.name AS userName')
            ->addSelect('sp.firstname')
            ->addSelect('sp.paternalSurname')
            ->addSelect('sp.maternalSurname')
            ->from('App:Reservation', 'r')
            ->innerJoin('r.session', 's')
            ->innerJoin('r.user', 'u')
            ->leftJoin('s.exerciseRoom', 'er')
            ->leftJoin('s.instructor', 'st')
            ->leftJoin('st.profile', 'sp')
            ->orderBy('s.dateStart', 'DESC')
            ->addOrderBy('s.timeStart', 'DESC')
            ->addOrderBy('r.placeNumber', 'ASC');

        // Apply filters
        if (!empty($filters['filter_status'])) {
            $isAvailable = 'available' === $filters['filter_status'];
            $qb->andWhere('r.isAvailable = :isAvailable')
               ->setParameter('isAvailable', $isAvailable);
        }

        if (!empty($filters['filter_session_date_start'])) {
            try {
                $dateStart = \DateTime::createFromFormat('d/m/Y', $filters['filter_session_date_start']);
                if ($dateStart instanceof \DateTime) {
                    $dateStart = \DateTimeImmutable::createFromInterface($dateStart)->setTime(0, 0, 0);
                    $qb->andWhere('s.dateStart >= :dateStart')
                       ->setParameter('dateStart', $dateStart);
                }
            } catch (\Exception) {
                // Ignore invalid date format
            }
        }

        if (!empty($filters['filter_session_date_end'])) {
            try {
                $dateEnd = \DateTime::createFromFormat('d/m/Y', $filters['filter_session_date_end']);
                if ($dateEnd instanceof \DateTime) {
                    $dateEnd = \DateTimeImmutable::createFromInterface($dateEnd)->setTime(23, 59, 59);
                    $qb->andWhere('s.dateStart <= :dateEnd')
                       ->setParameter('dateEnd', $dateEnd);
                }
            } catch (\Exception) {
                // Ignore invalid date format
            }
        }

        if (!empty($filters['filter_branch_office'])) {
            $qb->andWhere('s.branchOffice = :branchOffice')
               ->setParameter('branchOffice', (int) $filters['filter_branch_office']);
        }

        if (!empty($filters['filter_exercise_room'])) {
            $qb->andWhere('er.id = :exerciseRoom')
               ->setParameter('exerciseRoom', (int) $filters['filter_exercise_room']);
        }

        $rows = $qb->getQuery()->getArrayResult();

        $filename = sprintf('Reservaciones_%s.xlsx', date('Y-m-d_H-i'));
        $tmpFile = sys_get_temp_dir() . '/' . uniqid('reservations_export_', true) . '.xlsx';

        try {
            $writer = new Writer();
            $writer->openToFile($tmpFile);

            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Reservaciones');

            // Header row
            $writer->addRow(Row::fromValues([
                'Fecha de la sesión',
                'Hora de la sesión',
                'Salón',
                'Usuario',
                'Asiento',
                'Reservado en',
                'Asistencia',
                'Disponible',
                'Cancelado en',
            ]));

            // Data rows
            foreach ($rows as $row) {
                /** @var \DateTimeInterface|null $dateStart */
                $dateStart = $row['dateStart'];
                /** @var \DateTimeInterface|null $timeStart */
                $timeStart = $row['timeStart'];
                /** @var \DateTimeInterface|null $reservedAt */
                $reservedAt = $row['reservedAt'];
                /** @var \DateTimeInterface|null $cancellationAt */
                $cancellationAt = $row['cancellationAt'];

                $userName = $row['userName'];
                if ($row['firstname'] || $row['paternalSurname'] || $row['maternalSurname']) {
                    $userName = trim(sprintf('%s %s %s', $row['firstname'] ?? '', $row['paternalSurname'] ?? '', $row['maternalSurname'] ?? ''));
                }

                $writer->addRow(Row::fromValues([
                    $dateStart?->format('Y-m-d'),
                    $timeStart?->format('H:i'),
                    $row['exerciseRoomName'] ?? 'N/A',
                    $userName,
                    $row['placeNumber'],
                    $reservedAt?->format('Y-m-d H:i'),
                    $row['attended'] ? 'Sí' : 'No',
                    $row['isAvailable'] ? 'Sí' : 'No',
                    $cancellationAt?->format('Y-m-d H:i'),
                ]));
            }

            // Set column widths
            $sheet->setColumnWidth(15, 1); // Fecha de la sesión
            $sheet->setColumnWidth(12, 2); // Hora de la sesión
            $sheet->setColumnWidth(16, 3); // Salón
            $sheet->setColumnWidth(20, 4); // Usuario
            $sheet->setColumnWidth(8, 5); // Asiento
            $sheet->setColumnWidth(18, 6); // Reservado en
            $sheet->setColumnWidth(12, 7); // Asistencia
            $sheet->setColumnWidth(12, 8); // Disponible
            $sheet->setColumnWidth(18, 9); // Cancelado en

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
