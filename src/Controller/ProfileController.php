<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Discipline;
use App\Entity\Reservation;
use App\Entity\Session;
use App\Entity\User;
use App\Event\ReservationCanceledEvent;
use App\Event\SecurityCredentialChangedEvent;
use App\Event\WaitingListRemovedEvent;
use App\Form\ProfileFormType;
use App\Repository\BranchOfficeRepository;
use App\Repository\DisciplineRepository;
use App\Repository\ReservationRepository;
use App\Repository\SessionAuditRepository;
use App\Repository\SessionRepository;
use App\Repository\StaffRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Repository\WaitingListRepository;
use App\Service\Reservation\ReservationException;
use App\Service\Reservation\ReservationService;
use App\Service\SessionTimeCancel\TimeToCancel;
use App\Service\WaitingList\WaitingListCancellationWindowService;
use App\Service\WaitingList\WaitingListService;
use App\Util\PackageSessionType;
use App\Util\SeatLayoutMapper;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

#[Route('/mi-cuenta')]
#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    #[Route('/', name: 'profile', methods: ['GET'])]
    public function index(
        TransactionRepository $transactionRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('profile/index.html.twig', [
            'user' => $user,
            'lastTransactions' => $transactionRepository->getLastCompletedByUser($user),
        ]);
    }

    #[Route('/clases-disponibles', name: 'sessions_available', methods: ['GET'])]
    public function sessionsAvailable(
        ReservationRepository $reservationRepository,
        TransactionRepository $transactionRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $transactions = [];
        $transactionsAvailable = $transactionRepository->findAllTransactionAvailableByUser($user);

        foreach ($transactionsAvailable as $transaction) {
            $reservedSessions = $reservationRepository->getTotalAvailableByTransaction($transaction);
            $sessionsUsed = $reservationRepository->getTotalUsedByTransaction($transaction);

            $transactions[] = [
                'package_total_sessions' => $transaction->getPackageTotalClasses(),
                'package_is_unlimited' => $transaction->isPackageIsUnlimited(),
                'package_alt_text' => $transaction->getPackage()->getAltText(),
                'package_type' => PackageSessionType::getDescription($transaction->getPackageType()),
                'total_sessions_available' => $transaction->getPackageTotalClasses() - ($sessionsUsed + $reservedSessions),
                'total_sessions_used' => $sessionsUsed,
                'total_reserved_sessions' => $reservedSessions,
                'expiration_at' => $transaction->getExpirationAt(),
            ];
        }

        return $this->render('profile/sessions_available.html.twig', [
            'transactions' => $transactions,
        ]);
    }

    #[Route('/clases-tomadas', name: 'sessions_used', methods: ['GET'])]
    public function sessionsUsed(
        Request $request,
        ReservationRepository $reservationRepository,
        PaginatorInterface $paginator,
        DisciplineRepository $disciplineRepository,
        StaffRepository $staffRepository,
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $isHistoryMode = '1' === (string) $request->query->get('history', '0');
        $disciplines = $disciplineRepository->getAllActives();
        $instructors = $staffRepository->getAllActiveInstructors();

        if ($isHistoryMode) {
            $historyDisciplineFilterRaw = (string) $request->query->get('history_discipline_id', '');
            [$historyDisciplineId, $historyDisciplineType] = $this->resolveHistoryDisciplineFilter($historyDisciplineFilterRaw);

            $historyDateStartRaw = (string) $request->query->get('history_date_start', '');
            $historyDateEndRaw = (string) $request->query->get('history_date_end', '');
            $historyDateStart = $this->parseHistoryDate($historyDateStartRaw);
            $historyDateEnd = $this->parseHistoryDate($historyDateEndRaw);
            $hasDateValidationError = false;
            $historyValidationMessage = '';

            if ('' !== trim($historyDateStartRaw) && null === $historyDateStart) {
                $historyValidationMessage = 'Formato invalido. Usa fecha inicio y final como dd/mm/aaaa.';
                $hasDateValidationError = true;
            }

            if ('' === $historyValidationMessage && '' !== trim($historyDateEndRaw) && null === $historyDateEnd) {
                $historyValidationMessage = 'Formato invalido. Usa fecha inicio y final como dd/mm/aaaa.';
                $hasDateValidationError = true;
            }

            $today = new \DateTimeImmutable('today');
            if ('' === $historyValidationMessage && $historyDateStart instanceof \DateTimeImmutable && $historyDateStart > $today) {
                $historyValidationMessage = 'La fecha de inicio no puede ser mayor a la fecha actual.';
                $hasDateValidationError = true;
            }

            if ('' === $historyValidationMessage && $historyDateStart instanceof \DateTimeImmutable && $historyDateEnd instanceof \DateTimeImmutable && $historyDateEnd < $historyDateStart) {
                $historyValidationMessage = 'La fecha final no puede ser menor a la fecha de inicio.';
                $hasDateValidationError = true;
            }

            if ($hasDateValidationError) {
                // No ejecutar filtros cuando las fechas son invalidas.
                $historyDateStart = null;
                $historyDateEnd = null;
                $historyDisciplineId = 0;
                $historyDisciplineType = '';
            }

            $filters = [
                'date_start' => $historyDateStart,
                'date_end' => $historyDateEnd,
                'discipline_id' => $historyDisciplineId,
                'class_type' => $historyDisciplineType,
                'instructor_id' => max(0, (int) $request->query->get('history_instructor_id', 0)),
            ];

            $historyQuery = $reservationRepository->getTakenSessionsQueryBuilderByUser($user, $filters);
            $historyPagination = $paginator->paginate(
                $historyQuery,
                max(1, (int) $request->query->get('page', 1)),
                10,
            );

            return $this->render('profile/sessions_used.html.twig', [
                'reservations' => $historyPagination,
                'history_mode' => true,
                'disciplines' => $disciplines,
                'instructors' => $instructors,
                'history_validation_message' => $historyValidationMessage,
                'history_filters' => [
                    'history_date_start' => $historyDateStartRaw,
                    'history_date_end' => $historyDateEndRaw,
                    'history_discipline_id' => $historyDisciplineFilterRaw,
                    'history_instructor_id' => $request->query->get('history_instructor_id', ''),
                ],
            ]);
        }

        $lastMonthDate = (new \DateTimeImmutable('today'))->modify('-1 month');
        $reservations = $reservationRepository->getRecentTakenSessionsByUser($user, $lastMonthDate);

        return $this->render('profile/sessions_used.html.twig', [
            'reservations' => $reservations,
            'history_mode' => false,
            'disciplines' => $disciplines,
            'instructors' => $instructors,
        ]);
    }

    private function parseHistoryDate(string $value): ?\DateTimeImmutable
    {
        $normalized = trim($value);
        if ('' === $normalized) {
            return null;
        }

        $normalized = preg_replace('/[\s\-]+/', '/', $normalized) ?? $normalized;
        $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;
        $normalized = trim((string) $normalized, '/');

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{2})$/', $normalized, $matches)) {
            $year = (int) $matches[3];
            $normalized = sprintf('%s/%s/%04d', $matches[1], $matches[2], 2000 + $year);
        }

        $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $normalized);
        if ($date instanceof \DateTimeImmutable && $date->format('d/m/Y') === $normalized) {
            return $date;
        }

        $isoDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $normalized);

        return $isoDate ?: null;
    }

    /**
     * @return array{0:int, 1:string}
     */
    private function resolveHistoryDisciplineFilter(string $rawValue): array
    {
        $value = trim($rawValue);
        if ('' === $value) {
            return [0, ''];
        }

        if ('private:any' === $value) {
            return [0, PackageSessionType::TYPE_INDIVIDUAL];
        }

        if (preg_match('/^(\d+):(g|i)$/', $value, $matches)) {
            return [(int) $matches[1], (string) $matches[2]];
        }

        return [max(0, (int) $value), ''];
    }

    #[Route('/clases-tomadas/{id}/calificar', name: 'session_used_rate', methods: ['GET', 'POST'])]
    public function rateTakenSession(
        Request $request,
        Reservation $reservation,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->getId() !== $reservation->getUser()?->getId()) {
            throw new AccessDeniedHttpException('No puedes calificar una clase que no te pertenece.');
        }

        $session = $reservation->getSession();
        $isRateable = $session
            && Session::STATUS_CLOSED === $session->getStatus()
            && $reservation->isIsAvailable()
            && $this->isWithinRatingWindow($session->getDateStart());

        $returnTo = $this->resolveReturnTo($request);

        if ($request->isMethod('GET')) {
            return $this->render('profile/session_rate.html.twig', [
                'reservation' => $reservation,
                'session' => $session,
                'is_rateable' => $isRateable,
                'return_to' => $returnTo,
            ]);
        }

        if (!$this->isCsrfTokenValid('reservation_rate_'.$reservation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!$isRateable) {
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'error' => 'Solo puedes calificar clases tomadas y activas.',
                ]);
            }

            $this->addFlash('danger', 'Solo puedes calificar clases tomadas y activas.');

            return $this->redirectToRoute('sessions_used', [
                '_fragment' => 'section-content',
            ]);
        }

        $ratingExerciseRaw = $request->request->get('rating_exercise');
        $ratingInstructorRaw = $request->request->get('rating_instructor');
        $ratingClassTypeRaw = $request->request->get('rating_class_type');

        $ratingExercise = null !== $ratingExerciseRaw && '' !== (string) $ratingExerciseRaw
            ? (int) $ratingExerciseRaw
            : null;
        $ratingInstructor = null !== $ratingInstructorRaw && '' !== (string) $ratingInstructorRaw
            ? (int) $ratingInstructorRaw
            : null;
        $ratingClassType = null !== $ratingClassTypeRaw && '' !== (string) $ratingClassTypeRaw
            ? (int) $ratingClassTypeRaw
            : null;

        if (null === $ratingExercise
            && null === $ratingInstructor
            && null === $ratingClassType) {
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'error' => 'Debes marcar minimo una calificacion para que se registre.',
                ]);
            }

            $this->addFlash('danger', 'Debes marcar minimo una calificacion para que se registre.');

            return $this->redirectToRoute('sessions_used', [
                '_fragment' => 'section-content',
            ]);
        }

        if ((null !== $ratingExercise && !$this->isValidRating($ratingExercise))
            || (null !== $ratingInstructor && !$this->isValidRating($ratingInstructor))
            || (null !== $ratingClassType && !$this->isValidRating($ratingClassType))) {
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'error' => 'Cada calificacion debe estar entre 1 y 5 estrellas.',
                ]);
            }

            $this->addFlash('danger', 'Cada calificacion debe estar entre 1 y 5 estrellas.');

            return $this->redirectToRoute('sessions_used', [
                '_fragment' => 'section-content',
            ]);
        }

        $wasRated = $reservation->hasRatings();

        $reservation
            ->setRatingExercise($ratingExercise)
            ->setRatingInstructor($ratingInstructor)
            ->setRatingClassType($ratingClassType)
            ->setRatedAt(new \DateTime())
        ;

        $userRatingAverage = $reservation->getUserRatingAverage();

        $em->flush();

        $successMessage = $wasRated
            ? 'Calificacion actualizada correctamente.'
            : 'Calificacion registrada correctamente.';

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'success' => $successMessage,
                'userRatingAverage' => $userRatingAverage,
                    'targetUrl' => $returnTo,
            ]);
        }

        return $this->redirect($returnTo);
    }

    private function resolveReturnTo(Request $request): string
    {
        $returnTo = (string) ($request->request->get('return_to') ?? $request->query->get('return_to', ''));
        $returnTo = trim($returnTo);

        if ('' !== $returnTo && str_starts_with($returnTo, '/')) {
            return $returnTo;
        }

        return $this->generateUrl('sessions_used', [
            '_fragment' => 'section-content',
        ]);
    }

    private function isWithinRatingWindow(?\DateTimeInterface $sessionDateStart): bool
    {
        if (null === $sessionDateStart) {
            return false;
        }

        $windowStart = new \DateTimeImmutable('-1 month');

        return $sessionDateStart >= $windowStart;
    }

    #[Route('/proximas-clases', name: 'reserved_sessions', methods: ['GET'])]
    public function reservedSessions(
        ReservationRepository $reservationRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $reservations = $reservationRepository->getReservedSessionsByUser($user);

        return $this->render('profile/reserved_sessions.html.twig', [
            'reservations' => $reservations,
        ]);
    }

    #[Route('/lista-espera', name: 'profile_waiting_list', methods: ['GET'])]
    public function waitingList(
        WaitingListRepository $waitingListRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $waitingList = $waitingListRepository->getByUser($user);

        return $this->render('profile/waiting_list.html.twig', [
            'waitingList' => $waitingList,
        ]);
    }

    #[Route('/editar', name: 'profile_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        TransactionRepository $transactionRepository,
        EntityManagerInterface $em,
        EventDispatcherInterface $dispatcher,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $previousEmail = $user->getEmail();

        $form = $this->createForm(ProfileFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($user);
            $em->flush();

            $newEmail = $user->getEmail();
            if ($previousEmail !== $newEmail) {
                $dispatcher->dispatch(new SecurityCredentialChangedEvent(
                    $user,
                    SecurityCredentialChangedEvent::TYPE_EMAIL,
                    $previousEmail,
                    $newEmail,
                    $request->getClientIp(),
                    $request->headers->get('User-Agent'),
                ));
            }

            return $this->redirectToRoute('profile', [
                '_fragment' => 'perfil',
            ]);
        }

        return $this->render('profile/edit.html.twig', [
            'form' => $form->createView(),
            'lastTransactions' => $transactionRepository->getLastCompletedByUser($user),
        ]);
    }

    #[Route('/transaccion', name: 'transaction', methods: ['GET'])]
    public function transaction(
        TransactionRepository $transactionRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $transactions = $transactionRepository->getAllCompletedByUser($user);

        return $this->render('profile/transaction_list.html.twig', [
            'transactions' => $transactions,
        ]);
    }

    #[Route('/reservacion/{id}/cancelar', name: 'reservation_cancel', methods: ['GET', 'POST'])]
    public function reservationCancel(
        Request $request,
        Reservation $reservation,
        ReservationRepository $reservationRepository,
        SessionAuditRepository $sessionAuditRepository,
        TimeToCancel $timeToCancelService,
        ReservationService $reservationService,
        WaitingListCancellationWindowService $waitingListCancellationWindowService,
        \App\Service\ReservationCancellationService $cancellationService,
        LoggerInterface $logger,
    ): Response {
        /** @var User $loggedUser */
        $loggedUser = $this->getUser();

        if ($loggedUser->getId() !== $reservation->getUser()->getId()) {
            throw new AccessDeniedHttpException('La clase que intentas cancelar no te pertenece.');
        }

        if ($this->reservationHasChangeFlow($reservation, $sessionAuditRepository)) {
            $message = 'Esta reservación ya fue cambiada y no permite cancelación.';

            if ($request->isMethod('POST')) {
                return $this->json(['error' => $message]);
            }

            $this->addFlash('danger', $message);

            return $this->redirectToRoute('reserved_sessions', [
                '_fragment' => 'section-content',
            ]);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('reservation_cancel', $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token');
            }

            try {
                $logger->info('[USER_CANCEL] Iniciando cancelación de reservación por usuario', [
                    'reservation_id' => $reservation->getId(),
                    'user_id' => $loggedUser->getId(),
                    'session_id' => $reservation->getSession()->getId(),
                ]);

                // Cancelar la reservación
                $reservationService->cancel($reservation, ReservationCanceledEvent::SOURCE_USER);
                
                $logger->info('[USER_CANCEL] Reservación cancelada exitosamente', [
                    'reservation_id' => $reservation->getId(),
                ]);

                // Registrar auditoría de cancelación por usuario
                $reason = $request->request->get('reason'); // Opcional: motivo del usuario
                
                $logger->info('[USER_CANCEL] Registrando auditoría', [
                    'reservation_id' => $reservation->getId(),
                    'has_reason' => !empty($reason),
                ]);
                
                $cancellationService->auditUserCancellation($reservation, $reason);
                
                $logger->info('[USER_CANCEL] Proceso completo');

                return $this->json(['success' => true, 'message' => '¡Tu cancelación fue exitosa! Tu lugar quedó liberado correctamente.']);
            } catch (ReservationException $e) {
                $json['error'] = $e->getMessage();

                return $this->json($json);
            }
        }

        /** @var Session $session */
        $session = $reservation->getSession();

        $reservations = [];
        $sessionReservations = $reservationRepository->getReservationsBySession($session);

        foreach ($sessionReservations as $sessionReservation) {
            $reservations[$sessionReservation->getPlaceNumber()] = $sessionReservation;
        }

        $timeToCancel = $session->isIndividual() ?
            $timeToCancelService->getTimeToCancelIndividual() :
            $timeToCancelService->getTimeToCancelGroup()
        ;

        $branchOfficePlace = $session->getBranchOffice()->getPlace();
        $branchOfficeSlug = (new AsciiSlugger())->slug($branchOfficePlace)->lower();

        /** @var Discipline $discipline */
        $discipline = $session->getDiscipline();
        $gridClass = str_replace(' ', '-', strtolower($discipline->getName()));
        $gridClass .= '-'.$branchOfficeSlug;

        $seatLayout = $session->getSeatLayout() ?? $session->getExerciseRoom()?->getSeatLayout();
        $capacity = (int) ($session->getExerciseRoomCapacity() ?? $session->getExerciseRoom()?->getCapacity() ?? 0);
        $slotToSeat = SeatLayoutMapper::buildDisplaySlotToSeatMap($seatLayout, $capacity);
        $waitingListCancellationStatus = $waitingListCancellationWindowService->getStatus($reservation);

        return $this->render('profile/reservation_cancel.html.twig', [
            'discipline' => $session->getDiscipline(),
            'grid_class' => $gridClass,
            'reservations' => $reservations,
            'session' => $session,
            'reservation' => $reservation,
            'time_to_cancel' => $timeToCancel->toHours(),
            'waiting_list_cancel_status' => $waitingListCancellationStatus,
            'capacity' => $capacity,
            'slot_to_seat' => $slotToSeat,
        ]);
    }

    #[Route('/reservacion/{id}/cambiar', name: 'reservation_change', methods: ['GET'])]
    public function reservationChange(
        Request $request,
        Reservation $reservation,
        SessionRepository $sessionRepository,
        ReservationService $reservationService,
        SessionAuditRepository $sessionAuditRepository,
        BranchOfficeRepository $branchOfficeRepository,
        DisciplineRepository $disciplineRepository,
        StaffRepository $staffRepository,
        ReservationRepository $reservationRepository,
    ): Response {
        /** @var User $loggedUser */
        $loggedUser = $this->getUser();

        if ($loggedUser->getId() !== $reservation->getUser()->getId()) {
            throw new AccessDeniedHttpException('La clase que intentas cambiar no te pertenece.');
        }

        if ($this->reservationHasChangeFlow($reservation, $sessionAuditRepository)) {
            $this->addFlash('danger', 'Esta reservación ya fue cambiada y no permite otro cambio.');

            return $this->redirectToRoute('reserved_sessions', [
                '_fragment' => 'section-content',
            ]);
        }

        if (!$reservationService->canChange($reservation)) {
            $this->addFlash('danger', 'Lo sentimos, la reservación no acepta cambios.');

            return $this->redirectToRoute('reserved_sessions', [
                '_fragment' => 'section-content',
            ]);
        }

        $today = new \DateTimeImmutable('today');
        $dow = (int) $today->format('N'); // 1=Mon, 7=Sun
        $showNextWeek = $dow >= 5;

        // Current ISO week start (Monday) and max allowed week start
        $curWeekStart = $today->modify(sprintf('-%d days', $dow - 1))->setTime(0, 0, 0);
        $maxWeekStart = $showNextWeek ? $curWeekStart->modify('+7 days') : $curWeekStart;

        // Parse requested week from query params (defaults to current week)
        $curIsoWeek = (int) $curWeekStart->format('W');
        $curIsoYear = (int) $curWeekStart->format('o');
        $reqYear     = $request->query->getInt('year', $curIsoYear);
        $reqWeek     = $request->query->getInt('weekno', $curIsoWeek);

        // setISODate with day=1 anchors to Monday of the ISO week
        $reqWeekStart = (new \DateTimeImmutable())->setISODate($reqYear, $reqWeek, 1)->setTime(0, 0, 0);

        // Clamp to allowed range
        if ($reqWeekStart < $curWeekStart) {
            $reqWeekStart = $curWeekStart;
        }
        if ($reqWeekStart > $maxWeekStart) {
            $reqWeekStart = $maxWeekStart;
        }

        $reqWeekEnd = $reqWeekStart->modify('+6 days')->setTime(23, 59, 59);

        // On initial non-AJAX load verify at least one session exists in the whole window
        if (!$request->isXmlHttpRequest()) {
            $windowEnd   = $maxWeekStart->modify('+6 days')->setTime(23, 59, 59);
            $allSessions = $sessionRepository->getForChange($today, $windowEnd);
            $allFiltered = array_filter($allSessions, fn(Session $s) => $this->isSessionAllowedForChangeTarget($reservation, $s));

            if (!$allFiltered) {
                $message = $this->getChangeAvailabilityMessage($reservation, $allSessions);
                $this->addFlash('danger', $message);

                return $this->redirectToRoute('reserved_sessions', [
                    '_fragment' => 'section-content',
                ]);
            }
        }

        // Fetch sessions for the requested week only
        $sessions        = $sessionRepository->getForChange($reqWeekStart, $reqWeekEnd);
        $filteredSessions = array_filter($sessions, fn(Session $s) => $this->isSessionAllowedForChangeTarget($reservation, $s));

        // Group by date
        $sessionsByDate = [];
        foreach ($filteredSessions as $session) {
            $sessionsByDate[$session->getDateStart()->format('Y-m-d')][] = $session;
        }

        // Build week days array (Mon–Sun)
        $weekDays = [];
        for ($i = 0; $i < 7; $i++) {
            $current = $reqWeekStart->modify("+{$i} days");
            $dateKey = $current->format('Y-m-d');
            $weekDays[] = [
                'date'     => $current,
                'dateKey'  => $dateKey,
                'sessions' => $sessionsByDate[$dateKey] ?? [],
            ];
        }

        // weekPrev / weekNext
        $prevWeekStart = $reqWeekStart->modify('-7 days');
        $weekPrev = $prevWeekStart >= $curWeekStart ? [
            'weekno' => (int) $prevWeekStart->format('W'),
            'year'   => (int) $prevWeekStart->format('o'),
        ] : null;

        $nextWeekStart = $reqWeekStart->modify('+7 days');
        $weekNext = $nextWeekStart <= $maxWeekStart ? [
            'weekno' => (int) $nextWeekStart->format('W'),
            'year'   => (int) $nextWeekStart->format('o'),
        ] : null;

        $partialParams = [
            'reservation' => $reservation,
            'weekDays'    => $weekDays,
            'weekPrev'    => $weekPrev,
            'weekNext'    => $weekNext,
            'weekStart'   => $reqWeekStart,
            'weekEnd'     => $reqWeekStart->modify('+6 days'),
            'userReservedPlaces' => $reservationRepository->getReservedPlacesByUser(
                $loggedUser,
                $reqWeekStart,
                $reqWeekEnd,
            ),
        ];

        if ($request->isXmlHttpRequest()) {
            return $this->render('profile/_change_calendar_week.html.twig', $partialParams);
        }

        return $this->render('profile/reservation_change.html.twig', array_merge($partialParams, [
            'branchOffices' => $branchOfficeRepository->getPublic(),
            'disciplines'   => $disciplineRepository->getAllActives(),
            'instructors'   => $staffRepository->getAllActiveInstructors(),
        ]));
    }

    #[Route('/reservacion/{id}/cambiar/{sessionId}',name: 'reservation_change_session', methods: ['GET', 'POST'])]
    public function reservationChangeSession(
        Request $request,
        Reservation $reservation,
        #[MapEntity(mapping: ['sessionId' => 'id'])]
        Session $session,
        ReservationRepository $reservationRepository,
        SessionAuditRepository $sessionAuditRepository,
        ReservationService $reservationService,
        WaitingListService $waitingListService,
        \App\Service\ReservationCancellationService $cancellationService,
        LoggerInterface $logger,
    ): Response {
        /** @var User $loggedUser */
        $loggedUser = $this->getUser();

        if ($loggedUser->getId() !== $reservation->getUser()->getId()) {
            throw new AccessDeniedHttpException('La clase que intentas cambiar no te pertenece.');
        }

        if ($this->reservationHasChangeFlow($reservation, $sessionAuditRepository)) {
            $message = 'Esta reservación ya fue cambiada y no permite otro cambio.';

            if ($request->isMethod('POST')) {
                return $this->json(['error' => $message]);
            }

            $this->addFlash('danger', $message);

            return $this->redirectToRoute('reserved_sessions', [
                '_fragment' => 'section-content',
            ]);
        }

        if (!$this->isSessionAllowedForChangeTarget($reservation, $session)) {
            $message = 'La sesión seleccionada no está disponible para cambio.';

            if ($request->isMethod('POST')) {
                return $this->json(['error' => $message]);
            }

            $this->addFlash('danger', $message);

            return $this->redirectToRoute('reserved_sessions', [
                '_fragment' => 'section-content',
            ]);
        }

        if ($request->isMethod('POST') && $request->request->has('place_number')) {
            if (!$this->isCsrfTokenValid('reservation_change_session', $request->request->get('_token'))) {
                $logger->warning('[USER_CHANGE] Token CSRF inválido', [
                    'reservation_id' => $reservation->getId(),
                    'user_id' => $loggedUser->getId(),
                    'current_session_id' => $reservation->getSession()->getId(),
                    'new_session_id' => $session->getId(),
                ]);

                throw $this->createAccessDeniedException('Invalid CSRF token');
            }

            try {
                $logger->info('[USER_CHANGE] Iniciando cambio de reservación', [
                    'reservation_id' => $reservation->getId(),
                    'user_id' => $loggedUser->getId(),
                    'current_session_id' => $reservation->getSession()->getId(),
                    'new_session_id' => $session->getId(),
                ]);

                if (!$reservationService->canChange($reservation)) {
                    throw new ReservationException('La reservación no acepta cambios.');
                }

                // Guardar sesión anterior ANTES del cambio (para auditoría)
                $previousSession = $reservation->getSession();
                $previousPlace = $reservation->getPlaceNumber();

                if ($previousPlace === null) {
                    throw new ReservationException('No se pudo determinar el asiento anterior para la auditoría.');
                }

                $logger->info('[USER_CHANGE] Sesión anterior capturada', [
                    'previous_session_id' => $previousSession->getId(),
                    'previous_place' => $previousPlace,
                ]);

                $placeNumber = $request->request->getInt('place_number');
                $reservationService->change($reservation, $session, $placeNumber);
                $waitingListService->checkAndReserve($previousSession, $previousPlace);

                $logger->info('[USER_CHANGE] Reservación cambiada exitosamente', [
                    'reservation_id' => $reservation->getId(),
                    'place_number' => $placeNumber,
                ]);

                // Registrar auditoría de cambio por usuario
                $reason = $request->request->get('reason'); // Opcional: motivo del usuario

                $logger->info('[USER_CHANGE] Registrando auditoría bidireccional', [
                    'has_reason' => !empty($reason),
                    'previous_session_id' => $previousSession->getId(),
                    'new_session_id' => $session->getId(),
                ]);

                $cancellationService->auditUserChange($reservation, $previousSession, $previousPlace, $reason);

                $logger->info('[USER_CHANGE] Proceso de cambio completo');

                return $this->render('profile/reservation_change_session_success.html.twig');
            } catch (ReservationException $e) {
                $json['error'] = $e->getMessage();

                return $this->json($json);
            }
        }

        $reservations = [];
        $sessionReservations = $reservationRepository->getReservationsBySession($session);
        foreach ($sessionReservations as $sessionReservation) {
            $reservations[$sessionReservation->getPlaceNumber()] = $sessionReservation;
        }

        $userReservations = $reservationRepository->getReservationPlacesByUserSession($loggedUser, $session);

        $branchOfficePlace = $session->getBranchOffice()->getPlace();
        $branchOfficeSlug = (new AsciiSlugger())->slug($branchOfficePlace)->lower();

        /** @var Discipline $discipline */
        $discipline = $session->getDiscipline();
        $gridClass = str_replace(' ', '-', strtolower($discipline->getName()));
        $gridClass .= '-'.$branchOfficeSlug;

        $seatLayout = $session->getSeatLayout() ?? $session->getExerciseRoom()?->getSeatLayout();
        $capacity = (int) ($session->getExerciseRoomCapacity() ?? $session->getExerciseRoom()?->getCapacity() ?? 0);
        $slotToSeat = SeatLayoutMapper::buildDisplaySlotToSeatMap($seatLayout, $capacity);

        return $this->render('profile/reservation_change_session.html.twig', [
            'discipline' => $session->getDiscipline(),
            'grid_class' => $gridClass,
            'reservation' => $reservation,
            'session' => $session,
            'reservations' => $reservations,
            'userReservations' => $userReservations,
            'capacity' => $capacity,
            'slot_to_seat' => $slotToSeat,
        ]);
    }

    private function reservationHasChangeFlow(
        Reservation $reservation,
        SessionAuditRepository $sessionAuditRepository,
    ): bool {
        if ($reservation->getChangedAt() !== null) {
            return true;
        }

        $reservationId = $reservation->getId();
        if ($reservationId === null) {
            return false;
        }

        return $sessionAuditRepository->hasReservationBeenChanged($reservationId);
    }

    private function isSessionAllowedForChangeTarget(Reservation $reservation, Session $session): bool
    {
        $currentSession = $reservation->getSession();
        if ($currentSession === null) {
            return false;
        }

        $currentSessionId = $reservation->getSession()?->getId();
        $targetSessionId = $session->getId();

        if ($currentSessionId === null || $targetSessionId === null || $currentSessionId === $targetSessionId) {
            return false;
        }

        if ($currentSession->isIndividual() !== $session->isIndividual()) {
            return false;
        }

        if ($session->getStatus() !== Session::STATUS_OPEN) {
            return false;
        }

        // Validate target session is within the original package validity period
        $transaction = $reservation->getTransaction();
        if ($transaction !== null && $transaction->getExpirationAt() !== null) {
            $targetDate = $session->getDateStart();
            if ($targetDate !== null && $targetDate > $transaction->getExpirationAt()) {
                return false;
            }
        }

        $targetDateTime = $session->getDateTimeStart();
        $now = new \DateTimeImmutable();
        if ($targetDateTime <= $now) {
            return false;
        }

        $maxDate = (new \DateTimeImmutable('today'))->modify('+30 days')->setTime(23, 59, 59);

        return $targetDateTime <= $maxDate;
    }

    private function getChangeAvailabilityMessage(Reservation $reservation, array $allSessions): string
    {
        $currentSession = $reservation->getSession();
        if ($currentSession === null) {
            return 'Lo sentimos, no hay clases disponibles para cambio en este momento.';
        }

        $isCurrentIndividual = $currentSession->isIndividual();
        $classType = $isCurrentIndividual ? 'individual' : 'grupal';

        // Caso A: Si no hay clases publicadas en absoluto
        if (empty($allSessions)) {
            return 'No hay más clases publicadas de tipo ' . $classType . ' en el período disponible para cambio.';
        }

        // Agrupar sesiones por tipo
        $sessionsByType = [
            'same_type' => [],
            'different_type' => [],
        ];

        foreach ($allSessions as $session) {
            if ($session->isIndividual() === $isCurrentIndividual) {
                $sessionsByType['same_type'][] = $session;
            } else {
                $sessionsByType['different_type'][] = $session;
            }
        }

        // Caso B: Hay clases pero SOLO de otro tipo (no del tipo actual)
        if (empty($sessionsByType['same_type']) && !empty($sessionsByType['different_type'])) {
            $otherType = $isCurrentIndividual ? 'grupal' : 'individual';
            return 'No hay clases disponibles de tipo ' . $classType . ' para cambio. Existen clases de tipo ' . $otherType . ', pero no se pueden cambiar entre tipos diferentes.';
        }

        // Caso C: Hay clases del mismo tipo, pero todas están fuera de vigencia del paquete
        $transaction = $reservation->getTransaction();
        if ($transaction !== null && $transaction->getExpirationAt() !== null) {
            $expirationAt = $transaction->getExpirationAt();
            $sessionsOutOfValidityCount = 0;

            foreach ($sessionsByType['same_type'] as $session) {
                $targetDate = $session->getDateStart();
                if ($targetDate !== null && $targetDate > $expirationAt) {
                    $sessionsOutOfValidityCount++;
                }
            }

            // Si TODAS las clases del mismo tipo están fuera de vigencia
            if ($sessionsOutOfValidityCount === count($sessionsByType['same_type'])) {
                return 'Existen otras clases de tipo ' . $classType . ', pero están fuera de la vigencia de tu paquete actual. Tu paquete solo cubre clases hasta el ' . $expirationAt->format('d/m/Y') . '.';
            }
        }

        // Caso D: Hay clases del mismo tipo con vigencia válida pero se filtran por otra razón
        return 'Lo sentimos, no hay clases disponibles para cambio en este momento.';
    }

    #[Route('/waiting-list/{sessionId}/remove', name: 'waiting_list_remove', methods: ['GET', 'POST'])]
    public function waitingListRemove(
        int $sessionId,
        Request $request,
        WaitingListRepository $waitingListRepository,
        EntityManagerInterface $em,
        EventDispatcherInterface $eventDispatcher,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $waitingList = $waitingListRepository->getOneBySession($sessionId, $user);

        if ($request->isMethod('GET')) {
            if (!$waitingList) {
                return $this->render('profile/waiting_list_remove_success.html.twig');
            }

            return $this->render('profile/waiting_list_remove.html.twig', [
                'waitingList' => $waitingList,
            ]);
        }

        // Validar token CSRF
        if (!$this->isCsrfTokenValid('waiting_list_remove_'.$sessionId, (string) $request->request->get('_token'))) {
            return $this->json([
                'error' => 'Token de seguridad inválido.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($waitingList) {
            $eventDispatcher->dispatch(new WaitingListRemovedEvent($waitingList));
            $em->remove($waitingList);
            $em->flush();
        }

        return $this->render('profile/waiting_list_remove_success.html.twig');
    }

    public function resume(
        TransactionRepository $transactionRepository,
        UserRepository $userRepository,
        ReservationRepository $reservationRepository,
        WaitingListRepository $waitingListRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $data = [
            'sessions' => $transactionRepository->getCountSessionsAvailableByUser($user),
            'sessions_used' => $reservationRepository->getSessionsTakenByUser($user, true),
            'reserved_sessions' => $reservationRepository->getReservedSessionsByUser($user, true),
            'waiting_list' => $waitingListRepository->getByUser($user, true),
        ];

        $data['sessions_available'] = $data['sessions'] - ($data['sessions_used'] + $data['reserved_sessions']);
        $data['sessions_available'] = max(0, $data['sessions_available']);

        $data['free_session'] = $user->isFreeSession();

        $data['unlimited_transactions'] = $userRepository->getUnlimitedTransactionsAvailable($user);

        return $this->render('profile/_resume.html.twig', $data);
    }

    private function isValidRating(int $rating): bool
    {
        return $rating >= 1 && $rating <= 5;
    }
}
