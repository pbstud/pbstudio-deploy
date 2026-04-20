<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\BranchOffice;
use App\Entity\ExerciseRoom;
use App\Entity\Session;
use App\Entity\Staff;
use App\Repository\BranchOfficeRepository;
use App\Repository\ExerciseRoomRepository;
use App\Repository\ReservationRepository;
use App\Repository\SessionRepository;
use App\Repository\StaffRepository;
use App\Service\WaitingList\WaitingListService;
use App\Util\SeatLayoutMapper;
use App\Util\Schedule;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Attribute\MapDateTime;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/session-day')]
class SessionDayController extends AbstractController
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    #[Route('/', name: 'backend_session_day', methods: ['GET'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function index(SessionRepository $sessionRepository): Response
    {
        $sessions = $sessionRepository->findAllGroupByDateStart();

        return $this->render('backend/session_day/index.html.twig', [
            'sessions' => $sessions,
        ]);
    }

    #[Route('/new-branch-office', name: 'backend_session_day_new_branch_office', methods: ['GET'])]
    public function newBranchOffice(BranchOfficeRepository $branchOfficeRepository): Response
    {
        return $this->render('backend/session_day/new_branch_office.html.twig', [
            'branchOffices' => $branchOfficeRepository->findAll(),
        ]);
    }

    #[Route('/import-from-date/{branchOfficeId}', name: 'backend_session_day_import_from_date', methods: ['GET'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function importFromDate(
        Request $request,
        int $branchOfficeId,
        BranchOfficeRepository $branchOfficeRepository,
        SessionRepository $sessionRepository,
    ): JsonResponse {
        $rawDate = trim((string) $request->query->get('date', ''));
        if ('' === $rawDate) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Debes seleccionar una fecha para importar.',
            ], 400);
        }

        /** @var BranchOffice|null $branchOffice */
        $branchOffice = $branchOfficeRepository->find($branchOfficeId);
        if (!$branchOffice) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Sucursal invalida.',
            ], 404);
        }

        $importDate = \DateTime::createFromFormat('d/m/Y', $rawDate) ?: \DateTime::createFromFormat('d-m-Y', $rawDate);
        if (!$importDate) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Formato de fecha invalido. Usa d/m/Y.',
            ], 400);
        }

        $importDate->setTime(0, 0, 0);

        $sessions = $sessionRepository->findBy([
            'status' => [
                Session::STATUS_OPEN,
                Session::STATUS_FULL,
                Session::STATUS_CLOSED,
            ],
            'dateStart' => $importDate,
            'branchOffice' => $branchOffice,
        ], [
            'timeStart' => 'ASC',
        ]);

        $items = [];
        /** @var Session $session */
        foreach ($sessions as $session) {
            $exerciseRoom = $session->getExerciseRoom();
            $instructor = $session->getInstructor();
            if (!$exerciseRoom) {
                continue;
            }

            $items[] = [
                'sessionId' => (int) $session->getId(),
                'timeStart' => $session->getTimeStart()?->format('H:i') ?? '',
                'exerciseRoomId' => (int) $exerciseRoom->getId(),
                'instructorId' => $instructor ? (int) $instructor->getId() : null,
                'instructorName' => $instructor ? (string) ($instructor->getProfile()?->getFirstname() ?? '') : '',
                'instructorColorHex' => $instructor ? (string) ($instructor->getProfile()?->getColorHex() ?? '') : '',
                'information' => (string) ($session->getInformation() ?? ''),
                'capacity' => (int) ($session->getExerciseRoomCapacity() ?? 0),
                'status' => (int) ($session->getStatus() ?? Session::STATUS_OPEN),
            ];
        }

        return new JsonResponse([
            'success' => true,
            'items' => $items,
            'total' => count($items),
        ]);
    }

    #[Route('/new', name: 'backend_session_day_new', methods: ['GET', 'POST'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function newDay(
        Request $request,
        Schedule $scheduleUtil,
        BranchOfficeRepository $branchOfficeRepository,
        ExerciseRoomRepository $exerciseRoomRepository,
        StaffRepository $instructorRepository,
        SessionInterface $sessionRequest,
        EntityManagerInterface $em
    ): Response {
        $branchOfficeId = $request->query->get('branch_office');

        if (empty($branchOfficeId)) {
            $this->addFlash('danger', 'Sucursal invalida.');

            return $this->redirectToRoute('backend_session_day_new_branch_office');
        }

        /** @var BranchOffice $branchOffice */
        $branchOffice = $branchOfficeRepository->find($branchOfficeId);

        $data = [
            'branchOffice' => $branchOffice,
        ];

        if ('POST' === $request->getMethod()) {
            $this->logger->info('[SessionDay] newDay POST recibido', [
                'branch_office' => $branchOfficeId,
                'user'          => $this->getUser()?->getUserIdentifier(),
                'ip'            => $request->getClientIp(),
            ]);

            try {
                $sessions = $request->request->all('session');

                // Protección server-side contra duplicados: solo procesar la pestaña activa.
                // El campo mode='legacy'|'modern' lo setea el JS antes del submit.
                // Si no llega (JS deshabilitado), se usa 'legacy' como fallback seguro.
                $mode = 'legacy';
                if (isset($sessions['mode']) && 'modern' === $sessions['mode']) {
                    $mode = 'modern';
                }

                $this->logger->info('[SessionDay] Modo de pestaña recibido', ['mode' => $mode]);

                $schedules = is_array($sessions['schedules'] ?? null) ? $sessions['schedules'] : [];
                $scheduleTimes = is_array($sessions['scheduleTimes'] ?? null) ? $sessions['scheduleTimes'] : [];
                $information = is_array($sessions['information'] ?? null) ? $sessions['information'] : [];

                // Filtrar schedules al modo activo.
                // Vista clásica: claves son strings de hora ('06:00', '07:00', ...).
                // Vista dinámica: claves son enteros (0, 1, 2, ...).
                $schedules = array_filter(
                    $schedules,
                    static function (mixed $key) use ($mode): bool {
                        $isNumeric = ctype_digit((string) $key);
                        return 'modern' === $mode ? $isNumeric : !$isNumeric;
                    },
                    ARRAY_FILTER_USE_KEY
                );
                $scheduleTimes = array_filter(
                    $scheduleTimes,
                    static function (mixed $key) use ($mode): bool {
                        $isNumeric = ctype_digit((string) $key);
                        return 'modern' === $mode ? $isNumeric : !$isNumeric;
                    },
                    ARRAY_FILTER_USE_KEY
                );
                $information = array_filter(
                    $information,
                    static function (mixed $key) use ($mode): bool {
                        $isNumeric = ctype_digit((string) $key);
                        return 'modern' === $mode ? $isNumeric : !$isNumeric;
                    },
                    ARRAY_FILTER_USE_KEY
                );
                $rawDateStarts = [];
                if (is_array($sessions['dateStarts'] ?? null)) {
                    $rawDateStarts = $sessions['dateStarts'];
                } elseif (!empty($sessions['dateStart'])) {
                    $rawDateStarts = [(string) $sessions['dateStart']];
                }

                $rawDateStarts = array_values(array_unique(array_filter(array_map(
                    static fn ($date): string => trim((string) $date),
                    $rawDateStarts
                ), static fn (string $date): bool => '' !== $date)));

                if ([] === $rawDateStarts) {
                    $this->addFlash('danger', 'Debes seleccionar al menos una fecha.');

                    throw new \InvalidArgumentException('Sin fechas seleccionadas.');
                }

                $targetDates = [];
                $today = new \DateTime('today');
                foreach ($rawDateStarts as $rawDateStart) {
                    $newDate = \DateTime::createFromFormat('d/m/Y', $rawDateStart);
                    if (!$newDate || $newDate->format('d/m/Y') !== $rawDateStart) {
                        $this->addFlash('danger', sprintf('Fecha invalida: %s', $rawDateStart));

                        throw new \InvalidArgumentException(sprintf('Fecha invalida: %s', $rawDateStart));
                    }

                    $newDate->setTime(0, 0, 0);
                    if ($newDate <= $today) {
                        $this->addFlash('danger', 'Solo se pueden programar fechas futuras.');

                        throw new \InvalidArgumentException('Fecha no futura.');
                    }

                    $targetDates[] = $newDate;
                }

                $baseDate = clone $targetDates[0];

                $this->logger->debug('[SessionDay] Fechas parseadas en newDay', [
                    'raw_input' => $rawDateStarts,
                    'parsed'    => array_map(static fn (\DateTime $date): string => $date->format('Y-m-d'), $targetDates),
                ]);

                $connection = $em->getConnection();
                $connection->beginTransaction();

                $createdTotal = 0;
                $createdByDate = [];
                $baseDateKey = $baseDate->format('Y-m-d');
                $createdBaseDateSessions = [];

                foreach ($targetDates as $targetDate) {
                    $createdForDate = 0;
                    foreach ($schedules as $timeKey => $schedule) {
                        $time = !empty($scheduleTimes[$timeKey]) ? (string) $scheduleTimes[$timeKey] : (string) $timeKey;
                        [$timeHour, $timeMinute] = $this->extractHourMinute($time);
                        $dateTimeForSchedule = (clone $targetDate)->setTime($timeHour, $timeMinute);

                        foreach ($schedule as $exerciseRoom => $instructor) {
                            $info = !empty($information[$timeKey][$exerciseRoom]) ? $information[$timeKey][$exerciseRoom] : null;

                            $exerciseRoom = $exerciseRoomRepository->findOneById($exerciseRoom);
                            $instructor = $instructorRepository->findOneById($instructor);

                            if ($instructor) {
                                $session = new Session();
                                $capacity = (int) ($exerciseRoom->getCapacity() ?? 0);
                                $placesNotAvailable = SeatLayoutMapper::buildPersistedPlacesNotAvailable(
                                    $exerciseRoom->getPlacesNotAvailable(),
                                    $capacity,
                                    (int) ($exerciseRoom->getCapacity() ?? 0)
                                );
                                $session
                                    ->setDateStart(clone $dateTimeForSchedule)
                                    ->setTimeStart(clone $dateTimeForSchedule)
                                    ->setExerciseRoom($exerciseRoom)
                                    ->setExerciseRoomCapacity($capacity)
                                    ->setPlacesNotAvailable($placesNotAvailable)
                                    ->setSeatLayout(SeatLayoutMapper::buildPersistedSeatLayout($exerciseRoom->getSeatLayout(), $capacity))
                                    ->updateAvailableCapacity()
                                    ->setType($exerciseRoom->getType())
                                    ->setDiscipline($exerciseRoom->getDiscipline())
                                    ->setInstructor($instructor)
                                    ->setInformation($info)
                                    ->setBranchOffice($branchOffice)
                                ;

                                if ((int) $session->getAvailableCapacity() <= 0) {
                                    $session->setStatus(Session::STATUS_FULL);
                                }

                                $this->logger->debug('[SessionDay] Creando sesión', [
                                    'fecha'       => $dateTimeForSchedule->format('Y-m-d H:i:s'),
                                    'sala'        => $exerciseRoom?->getId(),
                                    'instructor'  => $instructor?->getId(),
                                ]);

                                $em->persist($session);
                                ++$createdForDate;

                                if ($targetDate->format('Y-m-d') === $baseDateKey) {
                                    $createdBaseDateSessions[] = $session;
                                }
                            }
                        }
                    }

                    $createdTotal += $createdForDate;
                    $createdByDate[$targetDate->format('Y-m-d')] = $createdForDate;
                }

                $em->flush();
                $connection->commit();

                $createdBaseDateSessionIds = array_values(array_filter(array_map(
                    static fn (Session $session): ?int => $session->getId(),
                    $createdBaseDateSessions
                ), static fn (?int $id): bool => null !== $id && $id > 0));

                $createdSessionIdsKey = sprintf('sessionDayCreatedSessionIds_%s_%s', (string) $branchOfficeId, $baseDate->format('Ymd'));

                if ([] !== $createdBaseDateSessionIds) {
                    $sessionRequest->set($createdSessionIdsKey, $createdBaseDateSessionIds);
                } else {
                    $sessionRequest->remove($createdSessionIdsKey);
                }

                if (0 === $createdTotal) {
                    $this->addFlash('warning', 'No se crearon clases. Verifica que cada fila tenga instructor asignado.');
                } else {
                    $this->logger->info('[SessionDay] Clases creadas por fecha', [
                        'total' => $createdTotal,
                        'by_date' => $createdByDate,
                    ]);
                    $this->addFlash('success', sprintf('Las clases han sido creadas (%d en %d fecha(s)).', $createdTotal, count($targetDates)));
                }

                return $this->redirectToRoute('backend_session_day_edit', [
                    'editDate' => $baseDate->format('d-m-Y'),
                    'branchOfficeId' => $branchOfficeId,
                    'applyDates' => array_map(static fn (\DateTime $date): string => $date->format('d-m-Y'), $targetDates),
                    'fromCreation' => 1,
                ]);
            } catch (\Exception $e) {
                if (isset($connection) && $connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                $this->logger->error('[SessionDay] Excepción en newDay', [
                    'mensaje' => $e->getMessage(),
                    'clase'   => get_class($e),
                    'trace'   => $e->getTraceAsString(),
                ]);

                $this->addFlash('danger', 'Ocurrió un error al crear las clases. No se guardó ningún cambio.');

                $data['data'] = $request->request->all('session');
            }
        }

        $data['exerciseRooms'] = $exerciseRoomRepository->getActiveByBranchOffice($branchOffice);

        $data['schedules'] = $scheduleUtil->getSchedules();

        if ($sessionRequest->has('dateStart')) {
            $data['data']['dateStarts'] = [$sessionRequest->get('dateStart')->format('d/m/Y')];

            $sessionRequest->remove('dateStart');
        }

        return $this->render('backend/session_day/new.html.twig', $data);
    }

    #[Route('/edit/{editDate}/{branchOfficeId}', name: 'backend_session_day_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function editDay(
        Request $request,
        #[MapDateTime(format: 'd-m-Y')]
        \DateTime $editDate,
        int $branchOfficeId,
        Schedule $scheduleUtil,
        EntityManagerInterface $em,
        SessionInterface $sessionRequest,
        ReservationRepository $reservationRepository,
        WaitingListService $waitingListService,
    ): Response {
        $this->logger->info('[SessionDay] editDay invocado', [
            'editDate'       => $editDate->format('Y-m-d'),
            'branchOfficeId' => $branchOfficeId,
            'user'           => $this->getUser()?->getUserIdentifier(),
            'method'         => $request->getMethod(),
            'ip'             => $request->getClientIp(),
        ]);

        $currentDate = new \DateTime();
        if ($currentDate >= $editDate) {
            $this->logger->warning('[SessionDay] Fecha no editable (es pasada o hoy)', [
                'editDate' => $editDate->format('Y-m-d'),
            ]);

            $this->addFlash('danger', 'Solo se pueden editar fechas futuras.');

            return $this->redirectToRoute('backend_session');
        }

        $exerciseRoomRepository = $em->getRepository(ExerciseRoom::class);
        $instructorRepository = $em->getRepository(Staff::class);
        $sessionRepository = $em->getRepository(Session::class);

        $sessions = $sessionRepository->findBy([
            'status' => [
                Session::STATUS_OPEN,
                Session::STATUS_FULL,
            ],
            'dateStart' => $editDate,
            'branchOffice' => $branchOfficeId,
        ]);

        $fromCreation = in_array(strtolower((string) $request->query->get('fromCreation', '0')), ['1', 'true', 'yes'], true);

        $rawApplyDatesContext = $request->query->all('applyDates');
        if (!is_array($rawApplyDatesContext)) {
            $singleApplyDate = trim((string) $request->query->get('applyDates', ''));
            $rawApplyDatesContext = '' !== $singleApplyDate ? [$singleApplyDate] : [];
        }

        if ([] === $rawApplyDatesContext && 'POST' === $request->getMethod()) {
            $postedSession = $request->request->all('session');
            if (is_array($postedSession['applyDates'] ?? null)) {
                $rawApplyDatesContext = $postedSession['applyDates'];
            }
        }

        $parsedApplyDatesFromContext = [];
        foreach ($rawApplyDatesContext as $rawApplyDate) {
            $rawApplyDate = trim((string) $rawApplyDate);
            if ('' === $rawApplyDate) {
                continue;
            }

            $parsedApplyDate = 
                \DateTime::createFromFormat('d-m-Y', $rawApplyDate)
                ?: \DateTime::createFromFormat('d/m/Y', $rawApplyDate);

            if (!$parsedApplyDate) {
                continue;
            }

            $parsedApplyDate->setTime(0, 0, 0);
            $parsedApplyDatesFromContext[$parsedApplyDate->format('Y-m-d')] = true;
        }

        $isMultiDateFromCreation = count($parsedApplyDatesFromContext) > 1;
        $createdSessionIdsKey = sprintf('sessionDayCreatedSessionIds_%s_%s', (string) $branchOfficeId, $editDate->format('Ymd'));
        $createdSessionIds = [];
        $limitToCreatedSessions = false;
        if ($fromCreation && $isMultiDateFromCreation) {
            $rawCreatedSessionIds = $sessionRequest->get($createdSessionIdsKey, []);
            if (is_array($rawCreatedSessionIds)) {
                $createdSessionIds = array_values(array_unique(array_filter(array_map(
                    static fn ($id): int => (int) $id,
                    $rawCreatedSessionIds
                ), static fn (int $id): bool => $id > 0)));
            }

            if ([] !== $createdSessionIds) {
                $limitToCreatedSessions = true;
                $sessions = array_values(array_filter($sessions, static fn (Session $session): bool => in_array((int) $session->getId(), $createdSessionIds, true)));
            }
        }

        $this->logger->debug('[SessionDay] Sesiones encontradas en BD', [
            'fecha'    => $editDate->format('Y-m-d'),
            'sucursal' => $branchOfficeId,
            'total'    => count($sessions),
        ]);

        /** @var BranchOfficeRepository $branchOfficeRepository */
        $branchOfficeRepository = $em->getRepository(BranchOffice::class);

        /** @var BranchOffice $branchOffice */
        $branchOffice = $branchOfficeRepository->find($branchOfficeId);
        $data = [
            'branchOffice' => $branchOffice,
        ];

        if (!$sessions) {
            $this->logger->warning('[SessionDay] Sin sesiones para esa fecha — redirigiendo a newDay', [
                'fecha'    => $editDate->format('Y-m-d'),
                'sucursal' => $branchOfficeId,
            ]);

            $sessionRequest->set('dateStart', $editDate);

            return $this->redirectToRoute('backend_session_day_new');
        }

        $data['dateStart'] = $editDate;

        /** @var Session $session */
        foreach ($sessions as $session) {
            $key = $session->getId().$session->getInstructor()->getId().$session->getInformation().$session->getExerciseRoomCapacity().$session->getTimeStart()->format('H:i').$this->getParameter('secret');

            $data['schedules'][$session->getTimeStart()->format('H:i')][$session->getExerciseRoom()->getId()] = [
                'session' => $session->getId(),
                'instructor' => $session->getInstructor()->getId(),
                'hash' => hash('md5', $key),
                'information' => $session->getInformation(),
                'capacity' => $session->getExerciseRoomCapacity(),
            ];
        }

        if ('POST' === $request->getMethod()) {
            $this->logger->info('[SessionDay] editDay POST recibido', [
                'editDate'       => $editDate->format('Y-m-d'),
                'branchOfficeId' => $branchOfficeId,
                'user'           => $this->getUser()?->getUserIdentifier(),
            ]);
            $sessions = $request->request->all('session');
            $schedules = is_array($sessions['schedules'] ?? null) ? $sessions['schedules'] : [];
            $scheduleTimes = is_array($sessions['scheduleTimes'] ?? null) ? $sessions['scheduleTimes'] : [];
            $information = is_array($sessions['information'] ?? null) ? $sessions['information'] : [];
            $capacities = is_array($sessions['capacity'] ?? null) ? $sessions['capacity'] : [];
            $baseEditDate = clone $editDate;
            $rawApplyDates = is_array($sessions['applyDates'] ?? null) ? $sessions['applyDates'] : [];
            $applyDates = [];

            foreach ($rawApplyDates as $rawApplyDate) {
                $rawApplyDate = trim((string) $rawApplyDate);
                if ('' === $rawApplyDate) {
                    continue;
                }

                $parsed = \DateTime::createFromFormat('d-m-Y', $rawApplyDate) ?: \DateTime::createFromFormat('d/m/Y', $rawApplyDate);
                if (!$parsed) {
                    continue;
                }

                $parsed->setTime(0, 0, 0);
                $applyDates[$parsed->format('Y-m-d')] = clone $parsed;
            }

            if ([] === $applyDates) {
                $applyDates[$baseEditDate->format('Y-m-d')] = clone $baseEditDate;
            }

            $updatedByDate = [];
            $createdByDate = [];
            $updatedBaseDateSessionIds = [];
            $createdBaseDateSessions = [];

            $connection = $em->getConnection();

            try {
                $connection->beginTransaction();

                foreach ($schedules as $scheduleKey => $exerciseRooms) {
                    $time = !empty($scheduleTimes[$scheduleKey]) ? (string) $scheduleTimes[$scheduleKey] : (string) $scheduleKey;
                    [$timeHour, $timeMinute] = $this->extractHourMinute($time);

                    $originalTime = preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d(?::[0-5]\\d)?$/', (string) $scheduleKey)
                        ? (string) $scheduleKey
                        : $time;
                    [$originalTimeHour, $originalTimeMinute] = $this->extractHourMinute($originalTime);

                    foreach ($exerciseRooms as $exerciseRoom => $session) {
                        $info = !empty($information[$scheduleKey][$exerciseRoom]) ? $information[$scheduleKey][$exerciseRoom] : null;
                        $capacity = !empty($capacities[$scheduleKey][$exerciseRoom]) ? $capacities[$scheduleKey][$exerciseRoom] : null;
                        $hasAdditionalDetails = '' !== trim((string) ($info ?? '')) || '' !== trim((string) ($capacity ?? ''));

                        $instructor = (int) ($session['instructor'] ?? 0);
                        if (!($instructor > 0)) {
                            if ($hasAdditionalDetails) {
                                throw new \InvalidArgumentException(sprintf(
                                    'Hay detalles en una clase sin instructor (%s / sala %s).',
                                    $scheduleKey,
                                    (string) $exerciseRoom
                                ));
                            }

                            continue;
                        }

                        if (isset($session['hash'])) {
                            $key = $session['session'].$session['instructor'].$info.$capacity.$time.$this->getParameter('secret');
                            $hash = hash('md5', $key);

                            $this->logger->debug('[SessionDay] Validando hash de sesión existente', [
                                'session_id'      => $session['session'] ?? null,
                                'hash_calculado'  => $hash,
                                'hash_recibido'   => $session['hash'],
                                'coincide'        => hash_equals($hash, $session['hash']),
                            ]);

                            if (!hash_equals($hash, $session['hash'])) {
                                $exerciseRoomEntity = $exerciseRoomRepository->findOneById($exerciseRoom);
                                $instructorEntity = $instructorRepository->findOneById($session['instructor']);

                                foreach ($applyDates as $applyDate) {
                                    $sourceDateTime = (clone $applyDate)->setTime($originalTimeHour, $originalTimeMinute);
                                    $targetDateTime = (clone $applyDate)->setTime($timeHour, $timeMinute);

                                    $targetSession = $sessionRepository->findOneBy([
                                        'branchOffice' => $branchOffice,
                                        'exerciseRoom' => $exerciseRoomEntity,
                                        'dateStart' => $sourceDateTime,
                                        'timeStart' => $sourceDateTime,
                                        'status' => [
                                            Session::STATUS_OPEN,
                                            Session::STATUS_FULL,
                                        ],
                                    ]);

                                    if (!$targetSession) {
                                        continue;
                                    }

                                    $targetSession
                                        ->setInstructor($instructorEntity)
                                        ->setInformation($info)
                                        ->setExerciseRoomCapacity((int) $capacity)
                                        ->setDateStart(clone $targetDateTime)
                                        ->setTimeStart(clone $targetDateTime)
                                        ->updateAvailableCapacity()
                                    ;

                                    $this->syncSessionAvailabilityStatus($targetSession, $reservationRepository);

                                    if ($applyDate->format('Y-m-d') === $baseEditDate->format('Y-m-d')) {
                                        $updatedBaseDateSessionIds[] = (int) $targetSession->getId();
                                    }

                                    $dateKey = $applyDate->format('Y-m-d');
                                    $updatedByDate[$dateKey] = ($updatedByDate[$dateKey] ?? 0) + 1;
                                }
                            }
                        } else {
                            /** @var ExerciseRoom $exerciseRoomEntity */
                            $exerciseRoomEntity = $exerciseRoomRepository->findOneById($exerciseRoom);
                            $instructorEntity = $instructorRepository->findOneById($session['instructor']);
                            $capacity = $capacity > 0 ? (int) $capacity : (int) ($exerciseRoomEntity?->getCapacity() ?? 0);
                            $placesNotAvailable = SeatLayoutMapper::buildPersistedPlacesNotAvailable(
                                $exerciseRoomEntity?->getPlacesNotAvailable(),
                                $capacity,
                                (int) ($exerciseRoomEntity?->getCapacity() ?? 0)
                            );

                            foreach ($applyDates as $applyDate) {
                                $targetDateTime = (clone $applyDate)->setTime($timeHour, $timeMinute);

                                $newSession = new Session();
                                $newSession
                                    ->setDateStart(clone $targetDateTime)
                                    ->setTimeStart(clone $targetDateTime)
                                    ->setExerciseRoom($exerciseRoomEntity)
                                    ->setType($exerciseRoomEntity->getType())
                                    ->setDiscipline($exerciseRoomEntity->getDiscipline())
                                    ->setInstructor($instructorEntity)
                                    ->setInformation($info)
                                    ->setBranchOffice($branchOffice)
                                    ->setExerciseRoomCapacity($capacity)
                                    ->setPlacesNotAvailable($placesNotAvailable)
                                    ->setSeatLayout(SeatLayoutMapper::buildPersistedSeatLayout($exerciseRoomEntity->getSeatLayout(), $capacity))
                                    ->updateAvailableCapacity()
                                ;

                                if ((int) $newSession->getAvailableCapacity() <= 0) {
                                    $newSession->setStatus(Session::STATUS_FULL);
                                }

                                $em->persist($newSession);

                                if ($applyDate->format('Y-m-d') === $baseEditDate->format('Y-m-d')) {
                                    $createdBaseDateSessions[] = $newSession;
                                }

                                $dateKey = $applyDate->format('Y-m-d');
                                $createdByDate[$dateKey] = ($createdByDate[$dateKey] ?? 0) + 1;
                            }
                        }
                    }
                }

                $em->flush();

                foreach ($updatedByDate as $dateKey => $updatedCount) {
                    if ($updatedCount <= 0) {
                        continue;
                    }

                    $dateObj = \DateTime::createFromFormat('Y-m-d', $dateKey);
                    if (!$dateObj) {
                        continue;
                    }

                    $sessionsForDate = $sessionRepository->findBy([
                        'branchOffice' => $branchOffice,
                        'dateStart' => $dateObj,
                        'status' => [
                            Session::STATUS_OPEN,
                            Session::STATUS_FULL,
                        ],
                    ]);

                    foreach ($sessionsForDate as $sessionToPromote) {
                        $waitingListService->promoteAvailablePlaces($sessionToPromote);
                    }
                }

                $connection->commit();

                if ($fromCreation && $isMultiDateFromCreation) {
                    $createdBaseDateSessionIds = array_values(array_filter(array_map(
                        static fn (Session $session): ?int => $session->getId(),
                        $createdBaseDateSessions
                    ), static fn (?int $id): bool => null !== $id && $id > 0));

                    $trackedSessionIds = array_values(array_unique(array_filter(array_merge(
                        $createdSessionIds,
                        $updatedBaseDateSessionIds,
                        $createdBaseDateSessionIds
                    ), static fn ($id): bool => (int) $id > 0)));

                    $sessionRequest->set($createdSessionIdsKey, $trackedSessionIds);
                }
            } catch (\Exception $e) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                $this->logger->error('[SessionDay] Excepción en editDay', [
                    'mensaje' => $e->getMessage(),
                    'clase'   => get_class($e),
                    'trace'   => $e->getTraceAsString(),
                ]);

                $this->addFlash('danger', 'Ocurrió un error al actualizar las clases. No se guardó ningún cambio.');

                return $this->redirectToRoute('backend_session_day_edit', [
                    'editDate' => $editDate->format('d-m-Y'),
                    'branchOfficeId' => $branchOfficeId,
                    'applyDates' => array_map(static fn (\DateTime $date): string => $date->format('d-m-Y'), array_values($applyDates)),
                    'fromCreation' => $fromCreation ? 1 : 0,
                ]);
            }

            $this->logger->info('[SessionDay] Resumen edición múltiple', [
                'apply_dates' => array_map(static fn (\DateTime $date): string => $date->format('Y-m-d'), $applyDates),
                'updated_by_date' => $updatedByDate,
                'created_by_date' => $createdByDate,
            ]);

            $this->addFlash('success', 'Las clases han sido actualizadas correctamente.');

            $applyDatesForRedirect = array_map(static fn (\DateTime $date): string => $date->format('d-m-Y'), array_values($applyDates));

            return $this->redirectToRoute('backend_session_day_edit', [
                'editDate' => $editDate->format('d-m-Y'),
                'branchOfficeId' => $branchOfficeId,
                'applyDates' => $applyDatesForRedirect,
                'fromCreation' => $fromCreation ? 1 : 0,
            ]);
        }

        $exerciseRooms = $exerciseRoomRepository->getActiveByBranchOffice($branchOffice);

        if ($limitToCreatedSessions) {
            $schedules = [];
            foreach (array_keys($data['schedules'] ?? []) as $existingSchedule) {
                $schedules[$existingSchedule] = $existingSchedule;
            }
            ksort($schedules);
        } else {
            $schedules = iterator_to_array($scheduleUtil->getSchedules());
            foreach (array_keys($data['schedules'] ?? []) as $existingSchedule) {
                if (!isset($schedules[$existingSchedule])) {
                    $schedules[$existingSchedule] = $existingSchedule;
                }
            }
            ksort($schedules);
        }

        $rawApplyDates = $request->query->all('applyDates');
        if (!is_array($rawApplyDates)) {
            $rawApplyDates = [];
        }

        $applyDates = [];
        foreach ($rawApplyDates as $rawApplyDate) {
            $rawApplyDate = trim((string) $rawApplyDate);
            if ('' === $rawApplyDate) {
                continue;
            }

            $parsed = \DateTime::createFromFormat('d-m-Y', $rawApplyDate) ?: \DateTime::createFromFormat('d/m/Y', $rawApplyDate);
            if (!$parsed) {
                continue;
            }

            $parsed->setTime(0, 0, 0);
            $applyDates[$parsed->format('Y-m-d')] = clone $parsed;
        }

        if ([] === $applyDates) {
            $applyDates[$editDate->format('Y-m-d')] = clone $editDate;
        }

        return $this->render('backend/session_day/edit.html.twig', [
            'data' => $data,
            'exerciseRooms' => $exerciseRooms,
            'sessions' => $sessions,
            'schedules' => $schedules,
            'applyDates' => array_map(static fn (\DateTime $date): string => $date->format('d-m-Y'), array_values($applyDates)),
            'applyDatesDisplay' => array_map(static fn (\DateTime $date): string => $date->format('d/m/Y'), array_values($applyDates)),
            'fromCreation' => $fromCreation,
        ]);
    }

    private function syncSessionAvailabilityStatus(
        Session $session,
        ReservationRepository $reservationRepository,
    ): void {
        $status = $session->getStatus();
        if (!in_array($status, [Session::STATUS_OPEN, Session::STATUS_FULL], true)) {
            return;
        }

        $availableCapacity = max(0, (int) $session->getAvailableCapacity());
        $activeReservations = $reservationRepository->getTotalReservationsForSession($session);

        if ($activeReservations >= $availableCapacity) {
            $session->setStatus(Session::STATUS_FULL);

            return;
        }

        $session->setStatus(Session::STATUS_OPEN);
    }

    /**
     * Accepts HH:mm and HH:mm:ss.
     *
     * @return array{0:int,1:int}
     */
    private function extractHourMinute(string $time): array
    {
        $time = trim($time);
        if (!preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d(?::[0-5]\\d)?$/', $time)) {
            throw new \InvalidArgumentException(sprintf('Horario invalido: %s', $time));
        }

        $parts = explode(':', $time);

        return [(int) $parts[0], (int) $parts[1]];
    }
}
