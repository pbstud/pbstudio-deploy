<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\Entity\Transaction;
use App\Repository\BranchOfficeRepository;
use App\Repository\ConfigurationRepository;
use App\Repository\ExerciseRoomRepository;
use App\Repository\PackageRepository;
use App\Repository\ReservationRepository;
use App\Repository\SessionRepository;
use App\Repository\StaffRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

class StatsService
{
    public function __construct(
        private readonly TransactionRepository $transactionRepository,
        private readonly UserRepository $userRepository,
        private readonly BranchOfficeRepository $branchOfficeRepository,
        private readonly StaffRepository $staffRepository,
        private readonly ConfigurationRepository $configurationRepository,
        private readonly ReservationRepository $reservationRepository,
        private readonly ExerciseRoomRepository $exerciseRoomRepository,
        private readonly SessionRepository $sessionRepository,
        private readonly PackageRepository $packageRepository,
    ) {
    }

    public function getDashboardStats(): array
    {
        $currentDate = new \DateTime('first day of this month');
        $endDate = new \DateTime('last day of this month');

        return [
            'totalAmountProcessed' => $this->transactionRepository->getTotalAmount(),
            'totalAmountInCurrentMonth' => $this->transactionRepository->getTotalAmountForRangeDate($currentDate, $endDate),
            'totalActiveUsers' => $this->userRepository->getTotalActiveUsers(),
        ];
    }

    public function getBillingBlock(): array
    {
        $now = CarbonImmutable::today();
        $currentMonth = $now->startOfMonth();
        $currentYear = $now->startOfYear();
        $currentWeek = $now->startOfWeek(Carbon::MONDAY);
        $currentWeekEnd = $currentWeek->addDays(6);

        return [
            'amountDaily' => $this->transactionRepository->getTotalAmount($now->toDate()),
            'amountWeekly' => $this->transactionRepository->getTotalAmountForRangeDate($currentWeek->toDate(), $currentWeekEnd->toDate()),
            'amountMonthly' => $this->transactionRepository->getTotalAmount($currentMonth->toDate()),
            'amountAnnually' => $this->transactionRepository->getTotalAmount($currentYear->toDate()),
            'amountProcessed' => $this->transactionRepository->getTotalAmount(),
            'currentYear' => $currentYear,
            'currentMonth' => $currentMonth,
            'currentWeek' => $currentWeek,
            'transactionDateStart' => \DateTime::createFromFormat('Y-m-d H:i:s', Transaction::DATE_START),
        ];
    }

    public function getBlock3DiscountsBySucursal(): array
    {
        $now = CarbonImmutable::today();
        $currentYear = $now->startOfYear();
        $currentMonth = $now->startOfMonth();

        $data = [];
        foreach ($this->branchOfficeRepository->getPublic() as $branchOffice) {
            $branchOfficeId = $branchOffice->getId();
            if (null === $branchOfficeId) {
                continue;
            }

            $data[$branchOfficeId] = [
                'name' => $branchOffice->getName(),
                'annually' => [
                    'without_discount' => 0.0,
                    'percentage_discount' => 0.0,
                    'special_price' => 0.0,
                ],
                'monthly' => [
                    'without_discount' => 0.0,
                    'percentage_discount' => 0.0,
                    'special_price' => 0.0,
                ],
            ];
        }

        $apply = function (array $rows, string $periodKey) use (&$data): void {
            foreach ($rows as $row) {
                $branchOfficeId = (int) $row['branchOfficeId'];
                if (!isset($data[$branchOfficeId])) {
                    continue;
                }

                $data[$branchOfficeId][$periodKey]['without_discount'] = (float) $row['withoutDiscount'];
                $data[$branchOfficeId][$periodKey]['percentage_discount'] = (float) $row['percentageDiscount'];
                $data[$branchOfficeId][$periodKey]['special_price'] = (float) $row['specialPrice'];
            }
        };

        $apply($this->transactionRepository->getTotalsByDiscountAndPublicBranchOffice($currentYear->toDate(), $now->toDate()), 'annually');
        $apply($this->transactionRepository->getTotalsByDiscountAndPublicBranchOffice($currentMonth->toDate(), $now->toDate()), 'monthly');

        return [
            'currentYear' => $currentYear,
            'currentMonth' => $currentMonth,
            'data' => $data,
        ];
    }

    public function getBlock4PaymentMethodsBySucursal(): array
    {
        $now = CarbonImmutable::today();
        $currentYear = $now->startOfYear();
        $currentMonth = $now->startOfMonth();

        $data = [];
        foreach ($this->branchOfficeRepository->getPublic() as $branchOffice) {
            $branchOfficeId = $branchOffice->getId();
            if (null === $branchOfficeId) {
                continue;
            }

            $data[$branchOfficeId] = [
                'name' => $branchOffice->getName(),
                'annually' => ['cash' => 0.0, 'card' => 0.0, 'pos' => 0.0],
                'monthly'  => ['cash' => 0.0, 'card' => 0.0, 'pos' => 0.0],
            ];
        }

        $apply = function (array $rows, string $periodKey) use (&$data): void {
            foreach ($rows as $row) {
                $branchOfficeId = (int) $row['branchOfficeId'];
                if (!isset($data[$branchOfficeId])) {
                    continue;
                }

                $data[$branchOfficeId][$periodKey]['cash'] = (float) $row['cash'];
                $data[$branchOfficeId][$periodKey]['card'] = (float) $row['card'];
                $data[$branchOfficeId][$periodKey]['pos']  = (float) $row['pos'];
            }
        };

        $apply($this->transactionRepository->getTotalsByChargeMethodAndPublicBranchOffice($currentYear->toDate(), $now->toDate()), 'annually');
        $apply($this->transactionRepository->getTotalsByChargeMethodAndPublicBranchOffice($currentMonth->toDate(), $now->toDate()), 'monthly');

        return [
            'currentYear'  => $currentYear,
            'currentMonth' => $currentMonth,
            'data'         => $data,
        ];
    }

    public function getBlock8WeekdaysBySucursal(\DateTimeInterface $from, \DateTimeInterface $to, int $top = 2): array
    {
        $weekdayLabels = [
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado',
            7 => 'Domingo',
        ];

        $data = [];
        foreach ($this->branchOfficeRepository->getPublic() as $branchOffice) {
            $branchOfficeId = $branchOffice->getId();
            if (null === $branchOfficeId) {
                continue;
            }

            $data[$branchOfficeId] = [
                'name' => $branchOffice->getName(),
                'weekdays' => [],
            ];
        }

        // DAYOFWEEK() returns 1=Sunday..7=Saturday; remap to 1=Monday..7=Sunday
        $remapDow = static fn(int $dow): int => $dow === 1 ? 7 : $dow - 1;

        foreach ($this->reservationRepository->getWeekdayAttendancesByPublicBranchOffice($from, $to) as $row) {
            $branchOfficeId = (int) $row['branchOfficeId'];
            if (!isset($data[$branchOfficeId])) {
                continue;
            }

            if (count($data[$branchOfficeId]['weekdays']) >= $top) {
                continue;
            }

            $weekdayOrder = $remapDow((int) $row['dayOfWeek']);
            $data[$branchOfficeId]['weekdays'][] = [
                'order' => $weekdayOrder,
                'label' => $weekdayLabels[$weekdayOrder] ?? (string) $weekdayOrder,
                'attendances' => (int) $row['attendances'],
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'top' => $top,
            'data' => $data,
        ];
    }

    public function getBlock8SchedulesBySucursal(\DateTimeInterface $from, \DateTimeInterface $to, int $top = 2): array
    {
        $data = [];
        foreach ($this->branchOfficeRepository->getPublic() as $branchOffice) {
            $branchOfficeId = $branchOffice->getId();
            if (null === $branchOfficeId) {
                continue;
            }

            $data[$branchOfficeId] = [
                'name' => $branchOffice->getName(),
                'schedules' => [],
            ];
        }

        foreach ($this->reservationRepository->getScheduleAttendancesByPublicBranchOffice($from, $to) as $row) {
            $branchOfficeId = (int) $row['branchOfficeId'];
            if (!isset($data[$branchOfficeId])) {
                continue;
            }

            if (count($data[$branchOfficeId]['schedules']) >= $top) {
                continue;
            }

            $schedule = $row['schedule'];
            $scheduleValue = $schedule instanceof \DateTimeInterface
                ? $schedule->format('H:i')
                : mb_substr((string) $schedule, 0, 5);

            $data[$branchOfficeId]['schedules'][] = [
                'value' => $scheduleValue,
                'label' => $scheduleValue,
                'attendances' => (int) $row['attendances'],
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'top' => $top,
            'data' => $data,
        ];
    }

    public function getBlock8PackagesBySucursal(\DateTimeInterface $from, \DateTimeInterface $to, int $top = 2): array
    {
        $data = [];
        foreach ($this->branchOfficeRepository->getPublic() as $branchOffice) {
            $branchOfficeId = $branchOffice->getId();
            if (null === $branchOfficeId) {
                continue;
            }

            $data[$branchOfficeId] = [
                'name' => $branchOffice->getName(),
                'packages' => [],
            ];
        }

        foreach ($this->transactionRepository->getPackagePurchasesByPublicBranchOffice($from, $to) as $row) {
            $branchOfficeId = (int) $row['branchOfficeId'];
            if (!isset($data[$branchOfficeId])) {
                continue;
            }

            if (count($data[$branchOfficeId]['packages']) >= $top) {
                continue;
            }

            $packageId = isset($row['packageId']) && null !== $row['packageId'] ? (int) $row['packageId'] : null;
            $totalClasses = (int) $row['packageTotalClasses'];
            $type = (string) $row['packageType'];
            $typeLabel = 'i' === $type ? 'individual' : ('g' === $type ? 'grupal' : $type);
            $label = $totalClasses . ' clase' . ($totalClasses === 1 ? '' : 's') . ' (' . $typeLabel . ')';

            $effectivePrice = null;
            if (null !== $packageId) {
                $pkg = $this->packageRepository->find($packageId);
                if (null !== $pkg) {
                    $effectivePrice = (float) $pkg->getTotalPrice();
                }
            }

            $data[$branchOfficeId]['packages'][] = [
                'packageId' => $packageId,
                'label' => $label,
                'price' => $effectivePrice,
                'purchases' => (int) $row['purchases'],
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'top' => $top,
            'data' => $data,
        ];
    }

    /**
     * Bloque 8 — Clientes con mayor gasto por sucursal publica.
     */
    public function getBlock8ClientsBySucursal(\DateTimeInterface $from, \DateTimeInterface $to, int $top = 2): array
    {
        $data = [];
        foreach ($this->branchOfficeRepository->getPublic() as $branchOffice) {
            $branchOfficeId = $branchOffice->getId();
            if (null === $branchOfficeId) {
                continue;
            }

            $data[$branchOfficeId] = [
                'name' => $branchOffice->getName(),
                'clients' => [],
            ];
        }

        foreach ($this->transactionRepository->getTopClientsBySpendingAndPublicBranchOffice($from, $to) as $row) {
            $branchOfficeId = (int) $row['branchOfficeId'];
            if (!isset($data[$branchOfficeId])) {
                continue;
            }

            if (count($data[$branchOfficeId]['clients']) >= $top) {
                continue;
            }

            $userId = isset($row['userId']) && null !== $row['userId'] ? (int) $row['userId'] : null;
            $fullName = trim(($row['userName'] ?? '') . ' ' . ($row['userLastname'] ?? ''));

            $data[$branchOfficeId]['clients'][] = [
                'userId' => $userId,
                'name' => $fullName ?: 'Usuario sin nombre',
                'email' => (string) ($row['userEmail'] ?? ''),
                'totalSpent' => (float) $row['totalSpent'],
                'purchases' => (int) $row['purchases'],
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'top' => $top,
            'data' => $data,
        ];
    }

    /**
     * Ranking — Top usuarios por asistencias confirmadas por sucursal pública en el rango.
     */
    public function getBlock8TopAttendanceBySucursal(\DateTimeInterface $from, \DateTimeInterface $to, int $top = 2): array
    {
        $data = [];
        foreach ($this->branchOfficeRepository->getPublic() as $branchOffice) {
            $branchOfficeId = $branchOffice->getId();
            if (null === $branchOfficeId) {
                continue;
            }
            $data[$branchOfficeId] = [
                'name' => $branchOffice->getName(),
                'users' => [],
            ];
        }

        foreach ($this->reservationRepository->getTopAttendedUsersByPublicBranchOffice($from, $to) as $row) {
            $branchOfficeId = (int) $row['branchOfficeId'];
            if (!isset($data[$branchOfficeId])) {
                continue;
            }
            if (count($data[$branchOfficeId]['users']) >= $top) {
                continue;
            }
            $fullName = trim(($row['userName'] ?? '') . ' ' . ($row['userLastname'] ?? ''));
            $data[$branchOfficeId]['users'][] = [
                'userId' => (int) $row['userId'],
                'name' => $fullName ?: 'Usuario sin nombre',
                'email' => (string) ($row['userEmail'] ?? ''),
                'attendanceCount' => (int) $row['attendanceCount'],
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'top' => $top,
            'data' => $data,
        ];
    }

    /**
     * Ranking completo — lista plana de usuarios por gasto en sucursales públicas.
     * Ordenado por sucursal ASC, luego por total gastado DESC.
     *
     * @return array{from: \DateTimeInterface, to: \DateTimeInterface, rows: array<int, array<string, mixed>>}
     */
    public function getFullSpendingRanking(?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        $rows = [];
        foreach ($this->transactionRepository->getTopClientsBySpendingAndPublicBranchOffice($from, $to) as $row) {
            $userId   = isset($row['userId']) && null !== $row['userId'] ? (int) $row['userId'] : null;
            $fullName = trim(($row['userName'] ?? '') . ' ' . ($row['userLastname'] ?? ''));

            $rows[] = [
                'branchOfficeName' => (string) ($row['branchOfficeName'] ?? ''),
                'userId'           => $userId,
                'name'             => $fullName ?: 'Usuario sin nombre',
                'email'            => (string) ($row['userEmail'] ?? ''),
                'totalSpent'       => (float) $row['totalSpent'],
                'purchases'        => (int) $row['purchases'],
            ];
        }

        return ['from' => $from, 'to' => $to, 'rows' => $rows];
    }

    /**
     * Ranking completo — lista plana de usuarios por asistencias confirmadas en sucursales públicas.
     * Ordenado por sucursal ASC, luego por asistencias DESC.
     *
     * @return array{from: ?\DateTimeInterface, to: ?\DateTimeInterface, rows: array<int, array<string, mixed>>}
     */
    public function getFullAttendanceRanking(?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        $rows = [];
        foreach ($this->reservationRepository->getTopAttendedUsersByPublicBranchOffice($from, $to) as $row) {
            $fullName = trim(($row['userName'] ?? '') . ' ' . ($row['userLastname'] ?? ''));
            $rows[] = [
                'branchOfficeName' => (string) ($row['branchOfficeName'] ?? ''),
                'userId'           => (int) $row['userId'],
                'name'             => $fullName ?: 'Usuario sin nombre',
                'email'            => (string) ($row['userEmail'] ?? ''),
                'attendanceCount'  => (int) $row['attendanceCount'],
            ];
        }

        return ['from' => $from, 'to' => $to, 'rows' => $rows];
    }

    /**
     * Bloque 2 — Ultimas transacciones completadas.
     */
    public function getBlock2LastTransactions(int $limit = 5): array
    {
        return $this->transactionRepository->getLastCompleted($limit);
    }

    /**
     * Bloque 9 — Resumen de usuarios: total, nuevos este mes, activos con paquete vigente.
     */
    public function getBlock9UserSummary(): array
    {
        $now = CarbonImmutable::today();
        $currentMonth = $now->startOfMonth();

        return [
            'total'        => $this->userRepository->getTotal(),
            'monthly'      => $this->userRepository->getTotal($currentMonth->toDate()),
            'active'       => $this->userRepository->getTotalWithTransactionActive(),
            'currentMonth' => $currentMonth,
        ];
    }

    /**
     * Bloque 10 — Ultimos usuarios registrados.
     */
    public function getBlock10LastUsers(int $limit = 5): array
    {
        return $this->userRepository->getLastUsers($limit);
    }

    public function getUsersBlock(CarbonImmutable $currentMonth): array
    {
        return [
            'totalUsers' => $this->userRepository->getTotal(),
            'totalUsersMonthly' => $this->userRepository->getTotal($currentMonth->toDate()),
            'totalUsersActive' => $this->userRepository->getTotalWithTransactionActive(),
        ];
    }

    public function getInstructorReservations(string $startDate): array
    {
        $period = CarbonPeriod::create($startDate, '14 days', Carbon::today());
        $startWeekly = $period->last();
        $endWeekly = $startWeekly->clone()->addDays(13);

        $total = $period->count();
        $indexMonth = (($total % 2) === 0) ? $total - 2 : $total - 1;
        $startMonth = $period->toArray()[$indexMonth];

        $studioInstructors = [];

        $grouped = function (array $reservations, string $reservationsKey) use (&$studioInstructors): void {
            foreach ($reservations as $reservation) {
                $studioId = $reservation['studioId'];
                $instructorId = $reservation['instructorId'];
                $totalReservations = $reservation['reservations'];

                if (!isset($studioInstructors[$studioId])) {
                    $studioInstructors[$studioId] = [
                        'studio' => $reservation['studio'],
                        'instructors' => [],
                    ];
                }

                if (isset($studioInstructors[$studioId]['instructors'][$instructorId])) {
                    $reservation = $studioInstructors[$studioId]['instructors'][$instructorId];
                }

                $reservation[$reservationsKey] = $totalReservations;
                $studioInstructors[$studioId]['instructors'][$instructorId] = $reservation;
            }
        };

        $instructorReservations = $this->reservationRepository->getGroupedInstructorStudio(
            $startMonth->toDate(),
            $endWeekly->toDate()
        );
        $grouped($instructorReservations, 'monthly');

        $instructorReservations = $this->reservationRepository->getGroupedInstructorStudio(
            $startWeekly->toDate(),
            $endWeekly->toDate()
        );
        $grouped($instructorReservations, 'weekly');

        return [
            'startMonth' => $startMonth->toDate(),
            'endMonth' => $endWeekly->toDate(),
            'startWeekly' => $startWeekly->toDate(),
            'endWeekly' => $endWeekly->toDate(),
            'data' => $studioInstructors,
        ];
    }

    /**
     * Bloque 5 — Clases por sucursal publica y disciplina activa, con periodos dia/semana/mes.
     * Jerarquia: [ boId => [ 'name', 'private' => [monthly,weekly,daily], 'disciplines' => [ dId => [...] ] ] ]
     * - Disciplinas: solo sesiones type='g' (grupales)
     * - Privada: columna extra con sesiones type='i' (individuales), sin desglose por disciplina
     */
    public function getBlock5Classes(): array
    {
        $now = CarbonImmutable::today();
        $currentMonth    = $now->startOfMonth();
        $currentMonthEnd = $now->endOfMonth();
        $currentWeek     = $now->startOfWeek(Carbon::MONDAY);
        $currentWeekEnd  = $currentWeek->addDays(6);

        // Base: disciplinas activas por sucursal publica, via ExerciseRoom
        $data = [];
        foreach ($this->exerciseRoomRepository->getActiveByPublicBranchOffice() as $er) {
            $bo   = $er->getBranchOffice();
            $disc = $er->getDiscipline();
            if ($disc === null) {
                continue;
            }
            $boId = $bo->getId();
            $dId  = $disc->getId();
            if (!isset($data[$boId])) {
                $data[$boId] = [
                    'name'        => $bo->getName(),
                    'private'     => ['monthly' => 0, 'weekly' => 0, 'daily' => 0],
                    'disciplines' => [],
                ];
            }
            if (!isset($data[$boId]['disciplines'][$dId])) {
                $data[$boId]['disciplines'][$dId] = [
                    'name'    => $disc->getName(),
                    'monthly' => 0,
                    'weekly'  => 0,
                    'daily'   => 0,
                ];
            }
        }

        // Superponer conteos grupales por disciplina
        $applyGroup = function (array $rows, string $key) use (&$data): void {
            foreach ($rows as $row) {
                $boId = (int) $row['branchOfficeId'];
                $dId  = (int) $row['disciplineId'];
                if (isset($data[$boId]['disciplines'][$dId])) {
                    $data[$boId]['disciplines'][$dId][$key] = (int) $row['sessions'];
                }
            }
        };

        $applyGroup($this->sessionRepository->getCountByDisciplineAndPublicBranchOffice($currentMonth->toDate(), $currentMonthEnd->toDate()), 'monthly');
        $applyGroup($this->sessionRepository->getCountByDisciplineAndPublicBranchOffice($currentWeek->toDate(), $currentWeekEnd->toDate()), 'weekly');
        $applyGroup($this->sessionRepository->getCountByDisciplineAndPublicBranchOffice($now->toDate(), $now->toDate()), 'daily');

        // Superponer conteos privados (individuales) por sucursal
        $applyPrivate = function (array $rows, string $key) use (&$data): void {
            foreach ($rows as $row) {
                $boId = (int) $row['branchOfficeId'];
                if (isset($data[$boId])) {
                    $data[$boId]['private'][$key] = (int) $row['sessions'];
                }
            }
        };

        $applyPrivate($this->sessionRepository->getCountPrivateByPublicBranchOffice($currentMonth->toDate(), $currentMonthEnd->toDate()), 'monthly');
        $applyPrivate($this->sessionRepository->getCountPrivateByPublicBranchOffice($currentWeek->toDate(), $currentWeekEnd->toDate()), 'weekly');
        $applyPrivate($this->sessionRepository->getCountPrivateByPublicBranchOffice($now->toDate(), $now->toDate()), 'daily');

        // Eliminar sucursales sin ningún dato
        $data = array_filter($data, function (array $studio): bool {
            $private = $studio['private'];
            if ($private['monthly'] > 0 || $private['weekly'] > 0 || $private['daily'] > 0) {
                return true;
            }
            foreach ($studio['disciplines'] as $disc) {
                if ($disc['monthly'] > 0 || $disc['weekly'] > 0 || $disc['daily'] > 0) {
                    return true;
                }
            }
            return false;
        });

        return [
            'data'            => $data,
            'currentDay'      => $now,
            'currentWeek'     => $currentWeek,
            'currentWeekEnd'  => $currentWeekEnd,
            'currentMonth'    => $currentMonth,
            'currentMonthEnd' => $currentMonthEnd,
        ];
    }

    /**
     * Bloque 6 — Asistencias reales por instructor (attended=true) por sucursal.
     * Periodos: mes calendario y catorcena actual (serie de 14 dias desde start_date de configuracion).
     */
    public function getBlock6Instructors(): array
    {
        $today = CarbonImmutable::today();
        $stats = $this->configurationRepository->findStats()?->getData() ?? [];
        $startDateRaw = $stats['start_date'] ?? null;

        if ($startDateRaw instanceof \DateTimeInterface) {
            $startDate = CarbonImmutable::instance($startDateRaw)->startOfDay();
        } elseif (is_string($startDateRaw) && '' !== trim($startDateRaw)) {
            $startDate = CarbonImmutable::parse($startDateRaw)?->startOfDay();
        } else {
            $startDate = null;
        }

        if (null === $startDate) {
            return [
                'hasStartDate' => false,
                'startMonth' => null,
                'endMonth' => null,
                'startCatorcena' => null,
                'endCatorcena' => null,
                'data' => [],
            ];
        }

        $period = CarbonPeriod::create($startDate, '14 days', $today);
        $periodPoints = $period->toArray();
        if ([] === $periodPoints) {
            $periodPoints = [$startDate];
        }

        $startCatorcena = end($periodPoints);
        if (!$startCatorcena instanceof CarbonImmutable) {
            $startCatorcena = CarbonImmutable::instance($startCatorcena);
        }
        $endCatorcena = $startCatorcena->addDays(13);

        $startMonth = $today->startOfMonth();
        if ($startMonth->lessThan($startDate)) {
            $startMonth = $startDate;
        }
        $endMonth = $today->endOfMonth();

        $data = [];
        foreach ($this->branchOfficeRepository->getPublic() as $branchOffice) {
            $branchOfficeId = $branchOffice->getId();
            if (null === $branchOfficeId) {
                continue;
            }

            $data[$branchOfficeId] = [
                'name' => $branchOffice->getName(),
                'instructors' => [],
            ];
        }

        $activeInstructors = [];
        foreach ($this->staffRepository->getAllActiveInstructors() as $instructor) {
            $instructorId = $instructor->getId();
            if (null === $instructorId) {
                continue;
            }

            $profile = $instructor->getProfile();
            $activeInstructors[$instructorId] = [
                'firstname' => $profile?->getFirstname() ?? '',
                'paternalSurname' => $profile?->getPaternalSurname() ?? '',
                'maternalSurname' => $profile?->getMaternalSurname() ?? '',
                'monthly' => 0,
                'catorcena' => 0,
            ];
        }

        foreach ($this->sessionRepository->getInstructorBranchOfficeMap() as $row) {
            $branchOfficeId = (int) $row['branchOfficeId'];
            $instructorId = (int) $row['instructorId'];

            if (!isset($data[$branchOfficeId], $activeInstructors[$instructorId])) {
                continue;
            }

            $data[$branchOfficeId]['instructors'][$instructorId] = $activeInstructors[$instructorId];
        }

        $grouped = function (array $rows, string $key) use (&$data): void {
            foreach ($rows as $row) {
                $studioId = (int) $row['studioId'];
                $instructorId = (int) $row['instructorId'];
                $attendances = (int) $row['attendances'];

                if (!isset($data[$studioId]['instructors'][$instructorId])) {
                    continue;
                }

                $data[$studioId]['instructors'][$instructorId][$key] = $attendances;
            }
        };

        $grouped(
            $this->reservationRepository->getAttendanceGroupedByInstructor($startMonth->toDate(), $endMonth->toDate()),
            'monthly'
        );
        $grouped(
            $this->reservationRepository->getAttendanceGroupedByInstructor($startCatorcena->toDate(), $endCatorcena->toDate()),
            'catorcena'
        );

        foreach ($data as &$studio) {
            uasort(
                $studio['instructors'],
                static function (array $left, array $right): int {
                    $leftName = trim(sprintf('%s %s %s', $left['firstname'], $left['paternalSurname'], $left['maternalSurname']));
                    $rightName = trim(sprintf('%s %s %s', $right['firstname'], $right['paternalSurname'], $right['maternalSurname']));

                    return strcasecmp($leftName, $rightName);
                }
            );
        }
        unset($studio);

        return [
            'hasStartDate' => true,
            'startMonth' => $startMonth,
            'endMonth' => $endMonth,
            'startCatorcena' => $startCatorcena,
            'endCatorcena' => $endCatorcena,
            'data' => $data,
        ];
    }

    /**
     * Bloque 7 — Fitpass por sucursal para ano/mes/semana/dia.
     * Incluye reservadas, asistidas y no asistidas.
     * El usuario Fitpass se toma de sessions.fitpass_user_id en Configuracion.
     */
    private function getBlock7ByProvider(string $configKey): array
    {
        $today = CarbonImmutable::today();
        $currentYear = $today->startOfYear();
        $currentYearEnd = $today->endOfYear();
        $currentMonth = $today->startOfMonth();
        $currentMonthEnd = $today->endOfMonth();
        $currentWeek = $today->startOfWeek(Carbon::MONDAY);
        $currentWeekEnd = $currentWeek->addDays(6);

        $sessionsConfig = $this->configurationRepository->findSessions()?->getData() ?? [];
        $userIdRaw = $sessionsConfig[$configKey] ?? null;
        $userId = is_numeric((string) $userIdRaw) ? (int) $userIdRaw : 0;

        $base = [
            'hasFitpassUser' => false,
            'fitpassUserId'  => null,
            'currentYear'     => $currentYear,
            'currentYearEnd'  => $currentYearEnd,
            'currentMonth'    => $currentMonth,
            'currentMonthEnd' => $currentMonthEnd,
            'currentWeek'     => $currentWeek,
            'currentWeekEnd'  => $currentWeekEnd,
            'currentDay'      => $today,
            'data'            => [],
        ];

        if ($userId <= 0 || null === $this->userRepository->find($userId)) {
            return $base;
        }

        $data = [];
        foreach ($this->branchOfficeRepository->getPublic() as $branchOffice) {
            $branchOfficeId = $branchOffice->getId();
            if (null === $branchOfficeId) {
                continue;
            }

            $data[$branchOfficeId] = [
                'name'    => $branchOffice->getName(),
                'year'    => ['reserved' => 0, 'attended' => 0, 'notAttended' => 0],
                'monthly' => ['reserved' => 0, 'attended' => 0, 'notAttended' => 0],
                'weekly'  => ['reserved' => 0, 'attended' => 0, 'notAttended' => 0],
                'daily'   => ['reserved' => 0, 'attended' => 0, 'notAttended' => 0],
            ];
        }

        $apply = function (array $rows, string $key) use (&$data): void {
            foreach ($rows as $row) {
                $branchOfficeId = (int) $row['branchOfficeId'];
                if (!isset($data[$branchOfficeId])) {
                    continue;
                }

                $data[$branchOfficeId][$key] = [
                    'reserved'    => (int) ($row['reserved']    ?? 0),
                    'attended'    => (int) ($row['attended']    ?? 0),
                    'notAttended' => (int) ($row['notAttended'] ?? 0),
                ];
            }
        };

        $apply(
            $this->reservationRepository->getFitpassAttendedByPublicBranchOffice($currentYear->toDate(), $currentYearEnd->toDate(), $userId),
            'year'
        );
        $apply(
            $this->reservationRepository->getFitpassAttendedByPublicBranchOffice($currentMonth->toDate(), $currentMonthEnd->toDate(), $userId),
            'monthly'
        );
        $apply(
            $this->reservationRepository->getFitpassAttendedByPublicBranchOffice($currentWeek->toDate(), $currentWeekEnd->toDate(), $userId),
            'weekly'
        );
        $apply(
            $this->reservationRepository->getFitpassAttendedByPublicBranchOffice($today->toDate(), $today->toDate(), $userId),
            'daily'
        );

        return array_merge($base, [
            'hasFitpassUser' => true,
            'fitpassUserId'  => $userId,
            'data'           => $data,
        ]);
    }

    public function getBlock7Fitpass(): array
    {
        return $this->getBlock7ByProvider('fitpass_user_id');
    }

    public function getBlock7Wellhub(): array
    {
        return $this->getBlock7ByProvider('wellhub_user_id');
    }

    public function getBlock7TotalPass(): array
    {
        return $this->getBlock7ByProvider('totalpass_user_id');
    }

    public function getNewUserRetentionBlock(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = $this->transactionRepository->getNewUserPurchasersInRange($from, $to);

        $sinRenovacion = [];
        $conRenovacion = [];

        foreach ($rows as $row) {
            $entry = [
                'userId'          => (int) $row['userId'],
                'fullName'        => trim($row['firstName'] . ' ' . $row['lastName']),
                'email'           => $row['email'],
                'firstPurchaseAt' => $row['firstPurchaseAt'],
                'subsequentCount' => (int) $row['subsequentCount'],
            ];

            if ($entry['subsequentCount'] === 0) {
                $sinRenovacion[] = $entry;
            } else {
                $conRenovacion[] = $entry;
            }
        }

        return [
            'from'          => $from,
            'to'            => $to,
            'sinRenovacion' => $sinRenovacion,
            'conRenovacion' => $conRenovacion,
        ];
    }

    public function getStudiosExerciseRooms(): array
    {
        $now = CarbonImmutable::today();
        $reservationsMonthly = $this->reservationRepository->getGroupedByExerciseRoom($now->startOfMonth()->toDate());
        $reservationsMonthly = array_column($reservationsMonthly, 'reservations', 'exerciseRoomId');
        $reservationsWeekly = $this->reservationRepository->getGroupedByExerciseRoom($now->startOfWeek(Carbon::MONDAY)->toDate());
        $reservationsWeekly = array_column($reservationsWeekly, 'reservations', 'exerciseRoomId');
        $reservationsDaily = $this->reservationRepository->getGroupedByExerciseRoom($now->toDate());
        $reservationsDaily = array_column($reservationsDaily, 'reservations', 'exerciseRoomId');
        $exerciseRooms = $this->exerciseRoomRepository->getAllActive();
        $groupExerciseRooms = [];
        foreach ($exerciseRooms as $exerciseRoom) {
            $branchOfficeId = $exerciseRoom->getBranchOffice()->getId();
            if (!isset($groupExerciseRooms[$branchOfficeId])) {
                $groupExerciseRooms[$branchOfficeId] = ['studio' => $exerciseRoom->getBranchOffice()->getName(), 'exerciseRooms' => []];
            }
            $groupExerciseRooms[$branchOfficeId]['exerciseRooms'][$exerciseRoom->getId()] = [
                'name' => $exerciseRoom->getName(),
                'monthly' => $reservationsMonthly[$exerciseRoom->getId()] ?? 0,
                'weekly' => $reservationsWeekly[$exerciseRoom->getId()] ?? 0,
                'daily' => $reservationsDaily[$exerciseRoom->getId()] ?? 0,
            ];
        }
        return $groupExerciseRooms;
    }

    public function getDiscountsBlock(CarbonImmutable $currentYear, CarbonImmutable $currentMonth): array
    {
        return [
            'totalNoDiscountAnnually' => $this->transactionRepository->getTotalAmount($currentYear->toDate(), true),
            'totalNoDiscountMonthly' => $this->transactionRepository->getTotalAmount($currentMonth->toDate(), true),
            'totalDiscountAnnually' => $this->transactionRepository->getTotalAmount($currentYear->toDate(), true, true),
            'totalDiscountMonthly' => $this->transactionRepository->getTotalAmount($currentMonth->toDate(), true, true),
        ];
    }

    public function getChargeMethodsBlock(CarbonImmutable $currentYear, CarbonImmutable $currentMonth): array
    {
        return [
            'totalCashAnnually' => $this->transactionRepository->getTotalByChargeMethod(Transaction::CHARGE_METHOD_CASH, $currentYear->toDate()),
            'totalCashMonthly' => $this->transactionRepository->getTotalByChargeMethod(Transaction::CHARGE_METHOD_CASH, $currentMonth->toDate()),
            'totalCardAnnually' => $this->transactionRepository->getTotalByChargeMethod(Transaction::CHARGE_METHOD_CARD, $currentYear->toDate()),
            'totalCardMonthly' => $this->transactionRepository->getTotalByChargeMethod(Transaction::CHARGE_METHOD_CARD, $currentMonth->toDate()),
            'totalTerminalAnnually' => $this->transactionRepository->getTotalByChargeMethod(Transaction::CHARGE_METHOD_POS, $currentYear->toDate()),
            'totalTerminalMonthly' => $this->transactionRepository->getTotalByChargeMethod(Transaction::CHARGE_METHOD_POS, $currentMonth->toDate()),
        ];
    }

    /**
     * Bloque 11 — Cumpleaños de usuarios activos en ventanas temporales.
     *
     * @return array{
     *   this_week: array<int, array{id:int,name:?string,lastname:?string,email:string,birthdayFormatted:?string}>,
     *   this_month: array<int, array{id:int,name:?string,lastname:?string,email:string,birthdayFormatted:?string}>,
     *   next_month: array<int, array{id:int,name:?string,lastname:?string,email:string,birthdayFormatted:?string}>,
     * }
     */
    public function getBlock11Birthdays(): array
    {
        $sortByDateAsc = static function (array $users): array {
            usort($users, static function (array $a, array $b): int {
                $aDate = (int) (new \DateTimeImmutable((string) $a['birthday']))->format('md');
                $bDate = (int) (new \DateTimeImmutable((string) $b['birthday']))->format('md');
                return $aDate <=> $bDate;
            });

            return $users;
        };

        return [
            'this_week'  => $sortByDateAsc($this->userRepository->getUsersWithBirthdayInWindow('this_week')),
            'this_month' => $sortByDateAsc($this->userRepository->getUsersWithBirthdayInWindow('this_month')),
            'next_month' => $sortByDateAsc($this->userRepository->getUsersWithBirthdayInWindow('next_month')),
        ];
    }

    /**
     * Bloque 12 — Aniversarios precomputados por el cron diario.
     */
    public function getBlock12Anniversaries(int $limit = 50): array
    {
        $users = $this->userRepository->getUsersWithAnniversarySnapshot($limit);

        $grouped = ['this_week' => [], 'this_month' => []];

        foreach ($users as $user) {
            $fullName = trim(((string) ($user['name'] ?? '')).' '.((string) ($user['lastname'] ?? '')));
            $displayName = '' !== $fullName ? $fullName : 'Usuario sin nombre';
            $uid = (int) ($user['id'] ?? 0);

            foreach (['this_week' => ($user['thisWeekEvents'] ?? []), 'this_month' => ($user['thisMonthEvents'] ?? [])] as $windowKey => $events) {
                foreach ($events as $event) {
                    if (!isset($grouped[$windowKey][$uid])) {
                        $grouped[$windowKey][$uid] = [
                            'id' => $uid,
                            'name' => $displayName,
                            'email' => (string) ($user['email'] ?? ''),
                            'events' => [],
                        ];
                    }
                    $grouped[$windowKey][$uid]['events'][] = [
                        'type' => (string) ($event['type'] ?? ''),
                        'year' => (int) ($event['year'] ?? 0),
                        'date' => (string) ($event['date'] ?? ''),
                    ];
                }
            }
        }

        $today = new \DateTimeImmutable('today');
        $todayMd = (int) $today->format('md');

        $sortByRepresentativeDateAsc = function (array $users) use ($todayMd): array {
            usort($users, function (array $a, array $b) use ($todayMd): int {
                // Extraer fechas (d/m) de todos los eventos del usuario
                $aDates = array_values(array_filter(array_map(function (array $evt): ?int {
                    return $this->parseDayMonthToMd((string) ($evt['date'] ?? ''));
                }, $a['events']), static fn (?int $md): bool => null !== $md));
                $bDates = array_values(array_filter(array_map(function (array $evt): ?int {
                    return $this->parseDayMonthToMd((string) ($evt['date'] ?? ''));
                }, $b['events']), static fn (?int $md): bool => null !== $md));

                // Buscar fecha representativa: próxima, o la menor si todas pasaron
                $aRepresentative = $this->getRepresentativeDate($aDates, $todayMd);
                $bRepresentative = $this->getRepresentativeDate($bDates, $todayMd);

                if ($aRepresentative === $bRepresentative) {
                    return ((string) ($a['name'] ?? '')) <=> ((string) ($b['name'] ?? ''));
                }

                return $aRepresentative <=> $bRepresentative;
            });

            return $users;
        };

        return [
            'this_week' => array_values($sortByRepresentativeDateAsc($grouped['this_week'])),
            'this_month' => array_values($sortByRepresentativeDateAsc($grouped['this_month'])),
        ];
    }

    /**
     * Obtiene la fecha representativa para ordenamiento.
     * - Si existe fecha próxima (>= hoy), devuelve la mínima de las próximas.
     * - Si todas pasaron, devuelve la mínima de las pasadas.
     *
     * @param array<int> $dates Array de fechas en formato 'md' (ej. 0507 para 7 de mayo)
     */
    private function getRepresentativeDate(array $dates, int $todayMd): int
    {
        if ([] === $dates) {
            return PHP_INT_MAX;
        }

        $upcomingDates = array_filter($dates, static fn (int $d): bool => $d >= $todayMd);

        // Si hay fechas próximas, usar la menor de ellas
        if ([] !== $upcomingDates) {
            return min($upcomingDates);
        }

        // Si todas pasaron, usar la menor
        return min($dates);
    }

    private function parseDayMonthToMd(string $dayMonth): ?int
    {
        $dayMonth = trim($dayMonth);
        if ('' === $dayMonth) {
            return null;
        }

        if (!preg_match('/^(\d{1,2})\/(\d{1,2})$/', $dayMonth, $matches)) {
            return null;
        }

        $day = (int) $matches[1];
        $month = (int) $matches[2];
        if (!checkdate($month, $day, 2000)) {
            return null;
        }

        return ($month * 100) + $day;
    }

    public function getRanking(): array
    {
        $now = CarbonImmutable::today();
        $dateStart = $now->startOfMonth()->subMonths(2)->toDate();

        $ranking = [];

        $grouped = function (array $reservations, string $key) use (&$ranking): void {
            foreach ($reservations as $reservation) {
                $studioId = $reservation['studioId'];
                $ranking[$studioId]['studio'] = $reservation['studio'];
                $ranking[$studioId][$key][] = $reservation[$key];
            }
        };

        $reservationsDay = $this->reservationRepository->getGroupedByDay($dateStart);
        $grouped($reservationsDay, 'day');

        $reservationsSchedule = $this->reservationRepository->getGroupedBySchedule($dateStart);
        $grouped($reservationsSchedule, 'schedule');

        $transactionsPackage = $this->transactionRepository->getGroupedByPackage($dateStart);
        $grouped($transactionsPackage, 'package');

        $reservationsCustomer = $this->reservationRepository->getGroupedByCustomerForStudios($dateStart);
        foreach ($reservationsCustomer as $reservation) {
            $studioId = (int) $reservation['studioId'];

            if (!isset($ranking[$studioId]['studio'])) {
                $ranking[$studioId]['studio'] = $reservation['studio'];
            }

            if (!isset($ranking[$studioId]['customer'])) {
                $ranking[$studioId]['customer'] = [];
            }

            if (count($ranking[$studioId]['customer']) < 5) {
                $ranking[$studioId]['customer'][] = $reservation;
            }
        }

        return [
            'dateStart' => $dateStart,
            'data' => $ranking,
        ];
    }
}
