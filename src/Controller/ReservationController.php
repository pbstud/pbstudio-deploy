<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Discipline;
use App\Entity\Session;
use App\Entity\User;
use App\Repository\BranchOfficeRepository;
use App\Repository\DisciplineRepository;
use App\Repository\ReservationRepository;
use App\Repository\SessionRepository;
use App\Repository\StaffRepository;
use App\Service\Reservation\ReservationException;
use App\Service\Reservation\ReservationService;
use App\Util\SeatLayoutMapper;
use App\Service\WaitingList\WaitingListException;
use App\Service\WaitingList\WaitingListService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

class ReservationController extends AbstractController
{
    // 24-hour format
    private const NEXT_WEEK_HOUR = 14;
    private const NEXT_WEEK_FROM_ISO_DAY = 5;

    #[Route('/reservar-clase/{slug}', name: 'reservation_calendar')]
    public function calendar(
        Request $request,
        BranchOfficeRepository $branchOfficeRepository,
        SessionRepository $sessionRepository,
        DisciplineRepository $disciplineRepository,
        StaffRepository $staffRepository,
        ReservationRepository $reservationRepository,
        ?string $slug = null,
    ): Response {
        $branchOffice = null;

        if (!empty($slug)) {
            $branchOffice = $branchOfficeRepository->getOneBySlug($slug);
        }

        if (!$branchOffice) {
            return $this->redirectToRoute('reservation_calendar', [
                'slug' => $branchOfficeRepository->getFirstPublic()->getSlug(),
            ]);
        }

        $period = $this->getPeriod($request->query->getInt('year'), $request->query->getInt('weekno'));
        $sessions = $sessionRepository->getCalendar($period, $branchOffice);

        $weekPrev = $this->getWeekPrev($period->start->copy());
        $weekNext = null;
        $weekNextStart = $period->start->copy()->addWeek()->startOfWeek(CarbonInterface::MONDAY);
        $periodNext = $weekNextStart->toPeriod($weekNextStart->copy()->endOfWeek(CarbonInterface::SUNDAY));
        if ($weekNextStart->lessThanOrEqualTo($this->getMaxAllowedWeekStart())
            && $sessionRepository->hasSessionsInPeriod($periodNext, $branchOffice)
        ) {
            $weekNext = $weekNextStart;
        }

        $userReservedSessionIds = [];
        if ($this->isGranted('ROLE_USER')) {
            /** @var \App\Entity\User $user */
            $user = $this->getUser();
            $userReservedSessionIds = $reservationRepository->getReservedSessionCountsByUser(
                $user,
                $period->start->toDateTimeImmutable(),
                $period->end->toDateTimeImmutable(),
            );
        }

        $template = 'calendar_ajax';
        $filter = [];

        if (!$request->isXmlHttpRequest()) {
            $filter['branchOffices'] = $branchOfficeRepository->getPublic();
            $filter['disciplines'] = $disciplineRepository->getAllActives();
            $filter['instructors'] = $staffRepository->getAllActiveInstructors();
            $template = 'calendar';
        }

        return $this->render(sprintf('reservation/%s.html.twig', $template), [
            'filter' => $filter,
            'branchOffice' => $branchOffice,
            'period' => $period,
            'sessions' => $sessions,
            'weekPrev' => $weekPrev,
            'weekNext' => $weekNext,
            'userReservedSessionIds' => $userReservedSessionIds,
        ]);
    }

    #[Route('/reservacion-clase/{id}', name: 'reservation_confirm', methods: ['GET', 'POST'])]
    public function confirm(
        Request $request,
        Session $session,
        ReservationRepository $reservationRepository,
        WaitingListService $waitingListService,
        ReservationService $reservationService,
    ): Response {
        if (!$this->isGranted('ROLE_USER')) {
            return $this->render('login/_reserve_links.html.twig');
        }

        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST') && $request->request->has('place_number')) {
            $placeNumber = $request->request->getInt('place_number');
            $consumptionSource = (string) $request->request->get('consumption_source', ReservationService::CONSUMPTION_SOURCE_AUTO);
            $json = [];

            try {
                $reservationService->reservate($user, $session, $placeNumber, null, $consumptionSource);

                $json = [
                    'targetUrl' => $this->generateUrl('reserved_sessions'),
                ];
            } catch (ReservationException $e) {
                $json['error'] = $e->getMessage();
            }

            return $this->json($json);
        }

        $reservations = [];
        $sessionReservations = $reservationRepository->getReservationsBySession($session);

        foreach ($sessionReservations as $reservation) {
            $reservations[$reservation->getPlaceNumber()] = $reservation;
        }

        $userReservations = $reservationRepository->getReservationPlacesByUserSession($user, $session);

        try {
            $showWaitingList = true;
            $onWaitingList = (bool) $waitingListService->findUser($session, $user);
            $msgNoClassesAvailable = false;
            $waitingListClosed = false;

            if (!$onWaitingList) {
                $waitingListService->validate($user, $session);
            }
        } catch (WaitingListException $e) {
            $showWaitingList = false;
            $waitingListClosed = !$onWaitingList && 1010 === (int) $e->getCode();

            if (!$onWaitingList && 1020 === (int) $e->getCode()) {
                $msgNoClassesAvailable = true;
            }
        }

        $branchOfficePlace = $session->getBranchOffice()->getPlace();
        $branchOfficeSlug = (new AsciiSlugger())->slug($branchOfficePlace)->lower();

        $consumptionOptions = $reservationService->getReservationConsumptionOptions($user, $session);

        /** @var Discipline $discipline */
        $discipline = $session->getDiscipline();
        $gridClass = str_replace(' ', '-', strtolower($discipline->getName()));
        $gridClass .= '-'.$branchOfficeSlug;

        $seatLayout = $session->getSeatLayout() ?? $session->getExerciseRoom()?->getSeatLayout();
        $capacity = (int) ($session->getExerciseRoomCapacity() ?? $session->getExerciseRoom()?->getCapacity() ?? 0);
        $slotToSeat = SeatLayoutMapper::buildDisplaySlotToSeatMap($seatLayout, $capacity);

        return $this->render('reservation/session.html.twig', [
            'discipline' => $session->getDiscipline(),
            'grid_class' => $gridClass,
            'session' => $session,
            'reservations' => $reservations,
            'userReservations' => $userReservations,
            'show_waitinglist' => $showWaitingList,
            'on_waitinglist' => $onWaitingList,
            'msg_no_classes_available' => $msgNoClassesAvailable,
            'waiting_list_closed' => $waitingListClosed,
            'capacity' => $capacity,
            'slot_to_seat' => $slotToSeat,
            'consumption_options' => $consumptionOptions,
        ]);
    }

    #[Route('/lista-de-espera/{id}', name: 'reservation_waitinglist', methods: ['POST'])]
    public function waitingList(
        Request $request,
        Session $session,
        WaitingListService $waitingListService,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json([
                'error' => 'Debes iniciar sesión para registrarte en la lista de espera.',
            ]);
        }

        if (!$this->isCsrfTokenValid('reservation_waitinglist_'.$session->getId(), (string) $request->request->get('_token'))) {
            return $this->json([
                'error' => 'Token de seguridad inválido.',
            ]);
        }

        try {
            $waitingListService->add($user, $session);

            return $this->json(['success' => true, 'notif' => true, 'message' => '¡Ya estás en la lista de espera! Te notificaremos si se libera un lugar.']);
        } catch (WaitingListException $e) {
            $json['error'] = $e->getMessage();

            return $this->json($json);
        }
    }

    private function getPeriod(int $year, int $weekNo): CarbonPeriod
    {
        $now = Carbon::now();
        $now->locale('es_MX');

        [$curWeek, $curYear] = $this->getCurWeekYear();

        $year = $year ?: $curYear;
        $weekNo = $weekNo ?: $curWeek;

        if ($year < $curYear || ($year === $curYear && $weekNo < $curWeek)) {
            $weekNo = $curWeek;
            $year = $curYear;
        }

        $now->setISODate($year, $weekNo);
        $start = $now->copy()->startOfWeek(CarbonInterface::MONDAY);

        $maxAllowedWeekStart = $this->getMaxAllowedWeekStart();
        if ($start->greaterThan($maxAllowedWeekStart)) {
            $start = $maxAllowedWeekStart->copy();
        }

        return $start->toPeriod($start->copy()->endOfWeek(CarbonInterface::SUNDAY));
    }

    private function canIncludeNextWeek(): bool
    {
        return Carbon::now()->dayOfWeekIso >= self::NEXT_WEEK_FROM_ISO_DAY;
    }

    private function getMaxAllowedWeekStart(): CarbonInterface
    {
        [$curWeek, $curYear] = $this->getCurWeekYear();

        $base = Carbon::now();
        $base->setISODate($curYear, $curWeek);
        $base = $base->startOfWeek(CarbonInterface::MONDAY);

        if ($this->canIncludeNextWeek()) {
            return $base->copy()->addWeek();
        }

        return $base;
    }

    private function getWeekPrev(CarbonInterface $carbon): ?CarbonInterface
    {
        $carbon->subWeek();

        [$curWeek, $curYear] = $this->getCurWeekYear();

        if ($carbon->yearIso < $curYear || ($carbon->yearIso === $curYear && $carbon->weekOfYear < $curWeek)) {
            return null;
        }

        return $carbon;
    }

    private function getCurWeekYear(): array
    {
        $now = Carbon::now();

        $curWeek = $now->weekOfYear;
        $curYear = $now->yearIso;

        if ($now->isSunday() && $now->noZeroHour >= self::NEXT_WEEK_HOUR) {
            ++$curWeek;
        }

        // Next year start week 1
        if ($curWeek > $now->weeksInYear) {
            $curWeek = 1;
            ++$curYear;
        }

        return [$curWeek, $curYear];
    }
}
