<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\Session;
use App\Repository\BranchOfficeRepository;
use App\Repository\DisciplineRepository;
use App\Repository\ReservationRepository;
use App\Repository\SessionRepository;
use App\Repository\StaffRepository;
use App\Security\Voter\RouteAccessVoter;
use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/stats')]
#[IsGranted(RouteAccessVoter::ALLOWED_ROUTE_ACCESS)]
class RatingsController extends AbstractController
{
    public function __construct(
        private readonly ReservationRepository $reservationRepository,
        private readonly SessionRepository $sessionRepository,
        private readonly BranchOfficeRepository $branchOfficeRepository,
        private readonly StaffRepository $staffRepository,
        private readonly DisciplineRepository $disciplineRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/ratings', name: 'backend_stats_ratings', methods: ['GET'])]
    public function ratings(Request $request, PaginatorInterface $paginator): Response
    {
        $timingStart = microtime(true);
        $isClearAction = $request->query->getBoolean('clear');

        $hasExplicitRatingFilters = $request->query->has('rating_date_start')
            || $request->query->has('rating_date_end')
            || $request->query->has('rating_branch_office')
            || $request->query->has('rating_schedule')
            || $request->query->has('rating_instructor')
            || $request->query->has('rating_discipline')
            || $request->query->has('rating_average_type')
            || $request->query->has('rating_average_type_value');

        $ratingFilters = [
            'rating_date_start' => trim((string) $request->query->get('rating_date_start', '')),
            'rating_date_end' => trim((string) $request->query->get('rating_date_end', '')),
            'rating_branch_office' => trim((string) $request->query->get('rating_branch_office', '')),
            'rating_schedule' => trim((string) $request->query->get('rating_schedule', '')),
            'rating_instructor' => trim((string) $request->query->get('rating_instructor', '')),
            'rating_discipline' => trim((string) $request->query->get('rating_discipline', '')),
            'rating_average_type' => trim((string) $request->query->get('rating_average_type', '')),
            'rating_average_type_value' => trim((string) $request->query->get('rating_average_type_value', '')),
        ];

        if (!$hasExplicitRatingFilters && !$isClearAction) {
            $dateEnd = CarbonImmutable::today();
            $dateStart = $dateEnd->subMonth();

            $ratingFilters['rating_date_start'] = $dateStart->format('d/m/Y');
            $ratingFilters['rating_date_end'] = $dateEnd->format('d/m/Y');
        }

        $ratingFilters['rating_schedule'] = $this->normalizeScheduleFilter($ratingFilters['rating_schedule']) ?? '';

        $selectedAverageType = $ratingFilters['rating_average_type'];
        $requiredAverage = '' !== $ratingFilters['rating_average_type_value']
            ? (float) $ratingFilters['rating_average_type_value']
            : null;

        $ratingsQueryBuilder = $this->reservationRepository->getRatedSessionsReportQueryBuilder([
            'date_start' => $this->parseDateFilter($ratingFilters['rating_date_start']),
            'date_end' => $this->parseDateFilter($ratingFilters['rating_date_end']),
            'branch_office' => $ratingFilters['rating_branch_office'],
            'schedule' => $ratingFilters['rating_schedule'],
            'instructor' => $ratingFilters['rating_instructor'],
            'discipline' => $ratingFilters['rating_discipline'],
        ], $selectedAverageType, $requiredAverage);

        $ratingColumnLabel = match ($selectedAverageType) {
            'exercise' => 'Calificación Ejercicios',
            'instructor' => 'Calificación instructor',
            'class_type' => 'Calificación Clases',
            default => 'Calificación general',
        };
        $timingAfterFilters = microtime(true);

        $page = (int) $request->query->get('page', 1);
        $pagination = $paginator->paginate($ratingsQueryBuilder, $page, Session::NUMBER_OF_ITEMS);
        $timingAfterPaginate = microtime(true);

        $sessionIds = [];
        foreach ($pagination->getItems() as $item) {
            if (isset($item['sessionId'])) {
                $sessionIds[] = (int) $item['sessionId'];
            }
        }

        $sessionsById = $this->sessionRepository->findByIdsForRatings($sessionIds);
        $timingAfterSessions = microtime(true);

        $availableCounts = $this->reservationRepository->getAvailableCountForSessions($sessionIds);
        $timingAfterAvailableCounts = microtime(true);

        $ratingRows = [];
        foreach ($pagination->getItems() as $item) {
            $sessionId = isset($item['sessionId']) ? (int) $item['sessionId'] : 0;
            if (!isset($sessionsById[$sessionId])) {
                continue;
            }

            $session = $sessionsById[$sessionId];

            $ratingGeneral = null !== $item['ratingGeneralAverage']
                ? round((float) $item['ratingGeneralAverage'], 1)
                : null;
            $ratingExerciseAverage = null !== $item['ratingExerciseAverage']
                ? round((float) $item['ratingExerciseAverage'], 1)
                : null;
            $ratingInstructorAverage = null !== $item['ratingInstructorAverage']
                ? round((float) $item['ratingInstructorAverage'], 1)
                : null;
            $ratingClassTypeAverage = null !== $item['ratingClassTypeAverage']
                ? round((float) $item['ratingClassTypeAverage'], 1)
                : null;

            $ratingRows[] = [
                'session' => $session,
                'reservationsAvailable' => $availableCounts[$sessionId] ?? 0,
                'ratingGeneral' => $ratingGeneral,
                'ratingExerciseAverage' => $ratingExerciseAverage,
                'ratingInstructorAverage' => $ratingInstructorAverage,
                'ratingClassTypeAverage' => $ratingClassTypeAverage,
                'ratingDisplay' => match ($selectedAverageType) {
                    'exercise' => $ratingExerciseAverage,
                    'instructor' => $ratingInstructorAverage,
                    'class_type' => $ratingClassTypeAverage,
                    default => $ratingGeneral,
                },
            ];
        }
        $timingAfterRows = microtime(true);

        $ratingsTimings = [
            'filterBuildMs' => (int) round(($timingAfterFilters - $timingStart) * 1000),
            'paginateMs' => (int) round(($timingAfterPaginate - $timingAfterFilters) * 1000),
            'loadSessionsMs' => (int) round(($timingAfterSessions - $timingAfterPaginate) * 1000),
            'loadAvailableCountsMs' => (int) round(($timingAfterAvailableCounts - $timingAfterSessions) * 1000),
            'mapRowsMs' => (int) round(($timingAfterRows - $timingAfterAvailableCounts) * 1000),
            'totalMs' => (int) round(($timingAfterRows - $timingStart) * 1000),
        ];

        return $this->render('backend/stats/ratings.html.twig', [
            'ratingFilters' => $ratingFilters,
            'pagination' => $pagination,
            'ratingRows' => $ratingRows,
            'ratingsTimings' => $ratingsTimings,
            'branchOffices' => $this->branchOfficeRepository->getAll(),
            'instructors' => $this->staffRepository->getAllActiveInstructors(),
            'disciplines' => $this->disciplineRepository->getAllActives(),
            'ratingColumnLabel' => $ratingColumnLabel,
        ]);
    }

    #[Route('/ratings/export', name: 'backend_stats_ratings_export', methods: ['GET'])]
    #[IsGranted(RouteAccessVoter::ALLOWED_ROUTE_ACCESS)]
    public function ratingsExport(Request $request): Response
    {
        $exportToken = trim((string) $request->query->get('export_token', ''));
        $selectedAverageType = trim((string) $request->query->get('rating_average_type', ''));
        $requiredAverage = trim((string) $request->query->get('rating_average_type_value', ''));
        $requiredAverage = '' !== $requiredAverage ? (float) $requiredAverage : null;

        $ratingFilters = [
            'rating_date_start' => trim((string) $request->query->get('rating_date_start', '')),
            'rating_date_end' => trim((string) $request->query->get('rating_date_end', '')),
            'rating_branch_office' => trim((string) $request->query->get('rating_branch_office', '')),
            'rating_schedule' => trim((string) $request->query->get('rating_schedule', '')),
            'rating_instructor' => trim((string) $request->query->get('rating_instructor', '')),
            'rating_discipline' => trim((string) $request->query->get('rating_discipline', '')),
        ];

        $ratingFilters['rating_schedule'] = $this->normalizeScheduleFilter($ratingFilters['rating_schedule']) ?? '';

        $em = $this->entityManager;
        $qb = $em->createQueryBuilder();

        $qb
            ->select('r.id AS reservationId')
            ->addSelect('r.placeNumber')
            ->addSelect('r.ratingExercise')
            ->addSelect('r.ratingInstructor')
            ->addSelect('r.ratingClassType')
            ->addSelect('s.id AS sessionId')
            ->addSelect('s.dateStart')
            ->addSelect('s.timeStart')
            ->addSelect('er.name AS exerciseRoomName')
            ->addSelect('sp.firstname')
            ->addSelect('sp.paternalSurname')
            ->addSelect('sp.maternalSurname')
            ->addSelect('u.id AS userId')
            ->addSelect('u.name AS userName')
            ->from('App:Reservation', 'r')
            ->innerJoin('r.session', 's')
            ->innerJoin('r.user', 'u')
            ->leftJoin('s.exerciseRoom', 'er')
            ->leftJoin('s.instructor', 'st')
            ->leftJoin('st.profile', 'sp')
            ->where('r.isAvailable = :isAvailable')
            ->andWhere('s.status = :statusClosed')
            ->andWhere('r.ratedAt IS NOT NULL')
            ->setParameter('isAvailable', true)
            ->setParameter('statusClosed', Session::STATUS_CLOSED)
            ->orderBy('s.dateStart', 'DESC')
            ->addOrderBy('s.timeStart', 'DESC')
        ;

        if (!empty($ratingFilters['rating_date_start'])) {
            $dateStart = $this->parseDateFilter($ratingFilters['rating_date_start']);
            if ($dateStart instanceof \DateTimeInterface) {
                $dateStart = \DateTimeImmutable::createFromInterface($dateStart)->setTime(0, 0, 0);
                $qb->andWhere('s.dateStart >= :dateStart')
                   ->setParameter('dateStart', $dateStart);
            }
        }

        if (!empty($ratingFilters['rating_date_end'])) {
            $dateEnd = $this->parseDateFilter($ratingFilters['rating_date_end']);
            if ($dateEnd instanceof \DateTimeInterface) {
                $dateEnd = \DateTimeImmutable::createFromInterface($dateEnd)->setTime(23, 59, 59);
                $qb->andWhere('s.dateStart <= :dateEnd')
                   ->setParameter('dateEnd', $dateEnd);
            }
        }

        if (!empty($ratingFilters['rating_branch_office'])) {
            $qb->andWhere('s.branchOffice = :branchOffice')
               ->setParameter('branchOffice', (int) $ratingFilters['rating_branch_office']);
        }

        if (!empty($ratingFilters['rating_instructor'])) {
            $qb->andWhere('s.instructor = :instructor')
               ->setParameter('instructor', (int) $ratingFilters['rating_instructor']);
        }

        if (!empty($ratingFilters['rating_discipline'])) {
            $qb->andWhere('s.discipline = :discipline')
               ->setParameter('discipline', (int) $ratingFilters['rating_discipline']);
        }

        if (!empty($ratingFilters['rating_schedule'])) {
            $qb->andWhere('s.timeStart = :schedule')
               ->setParameter('schedule', $ratingFilters['rating_schedule']);
        }

        // AC1 Fix: Apply rating_average_type filter at RESERVATION level, not SESSION level
        if (null !== $requiredAverage) {
            // Calculate general average per reservation: (exercise + instructor + classType) / count_of_non_null
            $generalAverageExpr = '(CASE '
                . 'WHEN r.ratingExercise IS NOT NULL AND r.ratingInstructor IS NOT NULL AND r.ratingClassType IS NOT NULL '
                . 'THEN (r.ratingExercise + r.ratingInstructor + r.ratingClassType) / 3 '
                . 'WHEN r.ratingExercise IS NOT NULL AND r.ratingInstructor IS NOT NULL '
                . 'THEN (r.ratingExercise + r.ratingInstructor) / 2 '
                . 'WHEN r.ratingExercise IS NOT NULL AND r.ratingClassType IS NOT NULL '
                . 'THEN (r.ratingExercise + r.ratingClassType) / 2 '
                . 'WHEN r.ratingInstructor IS NOT NULL AND r.ratingClassType IS NOT NULL '
                . 'THEN (r.ratingInstructor + r.ratingClassType) / 2 '
                . 'WHEN r.ratingExercise IS NOT NULL THEN r.ratingExercise '
                . 'WHEN r.ratingInstructor IS NOT NULL THEN r.ratingInstructor '
                . 'WHEN r.ratingClassType IS NOT NULL THEN r.ratingClassType '
                . 'ELSE NULL END)';

            $filterExpr = match ($selectedAverageType) {
                'exercise' => sprintf('r.ratingExercise >= %f', $requiredAverage),
                'instructor' => sprintf('r.ratingInstructor >= %f', $requiredAverage),
                'class_type' => sprintf('r.ratingClassType >= %f', $requiredAverage),
                default => sprintf('%s >= %f', $generalAverageExpr, $requiredAverage),
            };

            $qb->andWhere($filterExpr);
        }

        $rows = $qb->getQuery()->getArrayResult();

        $filename = sprintf('Calificaciones_%s.xlsx', date('Y-m-d_H-i'));
        $tmpFile = sys_get_temp_dir() . '/' . uniqid('ratings_export_', true) . '.xlsx';

        try {
            $writer = new Writer();
            $writer->openToFile($tmpFile);

            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Calificaciones');

            // Header row
            $writer->addRow(Row::fromValues([
                'Fecha de la clase',
                'Hora de la clase',
                'Salon',
                'Instructor de la clase',
                'Asiento',
                'Usuario',
                'Cal. General',
                'Cal. ejercicios',
                'Cal. instructor',
                'Cal. clases',
            ]));

            // Data rows
            foreach ($rows as $row) {
                $ratingGeneral = null;
                $ratingCount = 0;

                if (null !== $row['ratingExercise']) {
                    $ratingGeneral = ($ratingGeneral ?? 0) + $row['ratingExercise'];
                    ++$ratingCount;
                }
                if (null !== $row['ratingInstructor']) {
                    $ratingGeneral = ($ratingGeneral ?? 0) + $row['ratingInstructor'];
                    ++$ratingCount;
                }
                if (null !== $row['ratingClassType']) {
                    $ratingGeneral = ($ratingGeneral ?? 0) + $row['ratingClassType'];
                    ++$ratingCount;
                }

                if ($ratingCount > 0) {
                    $ratingGeneral = round($ratingGeneral / $ratingCount, 1);
                } else {
                    $ratingGeneral = null;
                }

                /** @var \DateTimeInterface|null $dateStart */
                $dateStart = $row['dateStart'];
                /** @var \DateTimeInterface|null $timeStart */
                $timeStart = $row['timeStart'];

                $instructorName = trim(sprintf(
                    '%s %s %s',
                    $row['firstname'] ?? '',
                    $row['paternalSurname'] ?? '',
                    $row['maternalSurname'] ?? ''
                ));
                if (empty($instructorName)) {
                    $instructorName = '';
                }

                $dataCells = [
                    $dateStart?->format('d/m/Y') ?? '',
                    $timeStart?->format('H:i') ?? '',
                    $row['exerciseRoomName'] ?? '',
                    $instructorName,
                    $row['placeNumber'] ?? '',
                    $row['userName'] ?? '',
                    null !== $ratingGeneral ? $ratingGeneral : '',
                    $row['ratingExercise'] ?? '',
                    $row['ratingInstructor'] ?? '',
                    $row['ratingClassType'] ?? '',
                ];
                $writer->addRow(Row::fromValues($dataCells));
            }

            // Set column widths (width, columnIndex) - column index is 1-based
            $sheet->setColumnWidth(15, 1); // Fecha de la clase
            $sheet->setColumnWidth(15, 2); // Hora de la clase
            $sheet->setColumnWidth(16, 3); // Salon
            $sheet->setColumnWidth(25, 4); // Instructor
            $sheet->setColumnWidth(8, 5);  // Asiento
            $sheet->setColumnWidth(20, 6); // Usuario
            $sheet->setColumnWidth(12, 7); // Calificacion general
            $sheet->setColumnWidth(12, 8); // Cal. ejercicios
            $sheet->setColumnWidth(12, 9); // Cal. instructor
            $sheet->setColumnWidth(12, 10); // Cal. clases

            $writer->close();

            $response = new BinaryFileResponse($tmpFile);
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
            $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $response->headers->set('Cache-Control', 'max-age=0');
            $response->headers->set('Pragma', 'public');
            if ('' !== $exportToken) {
                $response->headers->setCookie(
                    Cookie::create('ratings_export_token', $exportToken)
                        ->withHttpOnly(false)
                        ->withPath('/')
                );
            }

            register_shutdown_function(static fn () => @unlink($tmpFile));

            return $response;
        } catch (\Exception $e) {
            @unlink($tmpFile);
            throw $e;
        }
    }

    #[Route('/ratings/{id}/detail', name: 'backend_stats_ratings_detail', methods: ['GET'])]
    #[IsGranted(RouteAccessVoter::ALLOWED_ROUTE_ACCESS)]
    public function ratingsDetail(int $id, Request $request): Response
    {
        $ratingFilters = [
            'rating_date_start' => trim((string) $request->query->get('rating_date_start', '')),
            'rating_date_end' => trim((string) $request->query->get('rating_date_end', '')),
            'rating_branch_office' => trim((string) $request->query->get('rating_branch_office', '')),
            'rating_schedule' => trim((string) $request->query->get('rating_schedule', '')),
            'rating_instructor' => trim((string) $request->query->get('rating_instructor', '')),
            'rating_discipline' => trim((string) $request->query->get('rating_discipline', '')),
            'rating_average_type' => trim((string) $request->query->get('rating_average_type', '')),
            'rating_average_type_value' => trim((string) $request->query->get('rating_average_type_value', '')),
        ];

        $ratingFilters['rating_schedule'] = $this->normalizeScheduleFilter($ratingFilters['rating_schedule']) ?? '';

        $selectedAverageType = $ratingFilters['rating_average_type'];
        $selectedAverageLabel = match ($selectedAverageType) {
            'exercise' => 'Ejercicios',
            'instructor' => 'Instructor',
            'class_type' => 'Clases',
            default => 'General',
        };

        $sessionMap = $this->sessionRepository->findByIdsForRatings([$id]);
        if (!isset($sessionMap[$id])) {
            throw $this->createNotFoundException('No se encontro la sesion para mostrar su desglose de calificaciones.');
        }

        $session = $sessionMap[$id];
        $breakdown = $this->reservationRepository->getSessionRatingBreakdown($id);

        return $this->render('backend/stats/rating_detail_modal.html.twig', [
            'session' => $session,
            'breakdown' => $breakdown,
            'ratingFilters' => $ratingFilters,
            'selectedAverageType' => $selectedAverageType,
            'selectedAverageLabel' => $selectedAverageLabel,
        ]);
    }

    private function parseDateFilter(?string $date): ?\DateTimeInterface
    {
        if (!$date) {
            return null;
        }

        $input = trim($date);
        $parsed = \DateTimeImmutable::createFromFormat('!d/m/Y', $input);
        $errors = \DateTimeImmutable::getLastErrors();
        $hasParseErrors = is_array($errors)
            && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);

        if (false === $parsed || $hasParseErrors || $parsed->format('d/m/Y') !== $input) {
            return null;
        }

        return $parsed;
    }

    private function normalizeScheduleFilter(?string $schedule): ?string
    {
        if (!$schedule) {
            return null;
        }

        foreach (['H:i', 'H:i:s'] as $format) {
            $dateTime = \DateTimeImmutable::createFromFormat('!' . $format, $schedule);
            $errors = \DateTimeImmutable::getLastErrors();

            $hasParseErrors = is_array($errors)
                && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);

            if (false !== $dateTime && !$hasParseErrors) {
                return $dateTime->format('H:i');
            }
        }

        return null;
    }
}
