<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BranchOffice;
use App\Entity\ExerciseRoom;
use App\Entity\Reservation;
use App\Entity\Session;
use App\Util\SeatLayoutMapper;
use Carbon\CarbonPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Session>
 *
 * @method Session|null find($id, $lockMode = null, $lockVersion = null)
 * @method Session|null findOneBy(array $criteria, array $orderBy = null)
 * @method Session[]    findAll()
 * @method Session[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Session::class);
    }

    public function getQueryBuilderForBackendList(array $filters = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('s');

        $qb
            ->select('s.id, s.type, s.dateStart, s.timeStart, s.availableCapacity')
            ->addSelect('e.name exerciseRoom, b.name branchOffice, d.name discipline')
            ->addSelect('ip.firstname instructor, s.status')
            ->addSelect('COUNT(r.session) reservations')
            ->join('s.exerciseRoom', 'e')
            ->join('s.discipline', 'd')
            ->join('s.instructor', 'i')
            ->join('i.profile', 'ip')
            ->join('e.branchOffice', 'b')
            ->leftJoin('s.reservations', 'r', Join::WITH, $qb->expr()->eq('r.isAvailable', ':isAvailable'))
            ->setParameter('isAvailable', true)
            ->groupBy('s.id')
            ->orderBy('s.dateStart', 'ASC')
            ->addOrderBy('s.timeStart', 'ASC')
        ;

        $this->applyBackendListFilters($qb, $filters);

        return $qb;
    }

    public function findForBackendList(array $filters = [], bool $isExport = false, bool $newestFirst = false): array
    {
        $qb = $this->createQueryBuilder('s');
        $orderDirection = $newestFirst ? 'DESC' : 'ASC';

        $qb
            ->select('s.id, s.type, s.dateStart, s.timeStart, s.availableCapacity')
            ->addSelect('e.name exerciseRoom, b.name branchOffice, d.name discipline')
            ->addSelect('ip.firstname instructor, s.status')
            ->join('s.exerciseRoom', 'e')
            ->join('s.discipline', 'd')
            ->join('s.instructor', 'i')
            ->join('i.profile', 'ip')
            ->join('e.branchOffice', 'b')
            ->leftJoin('s.reservations', 'r', Join::WITH, $qb->expr()->eq('r.isAvailable', ':isAvailable'))
            ->setParameter('isAvailable', true)
            ->orderBy('s.dateStart', $orderDirection)
            ->addOrderBy('s.timeStart', $orderDirection)
        ;

        if (!$isExport) {
            $qb
                ->addSelect('COUNT(r.session) reservations')
                ->groupBy('s.id')
            ;
        }

        if ($isExport) {
            $qb
                ->addSelect('ruser.name userName', 'ruser.lastname userLastname', 'r.placeNumber')
                ->leftJoin('r.user', 'ruser')
                ->addOrderBy('s.id')
                ->addOrderBy('r.placeNumber')
            ;
        }

        if (!empty($filters['type'])) {
            $qb
                ->andWhere('s.type = :type')
                ->setParameter('type', $filters['type'])
            ;
        }

        if (!empty($filters['discipline'])) {
            $qb
                ->andWhere('s.discipline = :discipline')
                ->setParameter('discipline', $filters['discipline'])
            ;
        }

        if (!empty($filters['instructor'])) {
            $qb
                ->andWhere('s.instructor = :instructor')
                ->setParameter('instructor', $filters['instructor'])
            ;
        }

        if (!empty($filters['branchOffice'])) {
            $qb
                ->andWhere('s.branchOffice = :branchOffice')
                ->setParameter('branchOffice', $filters['branchOffice'])
            ;
        }

        $dateStart = null;
        if (!empty($filters['date_start']) && is_string($filters['date_start'])) {
            $dateStartInput = trim($filters['date_start']);
            $parsedDateStart = \DateTimeImmutable::createFromFormat('!d/m/Y', $dateStartInput);
            $dateStartErrors = \DateTimeImmutable::getLastErrors();
            $hasDateStartErrors = is_array($dateStartErrors)
                && (($dateStartErrors['warning_count'] ?? 0) > 0 || ($dateStartErrors['error_count'] ?? 0) > 0);

            if (false !== $parsedDateStart && !$hasDateStartErrors && $parsedDateStart->format('d/m/Y') === $dateStartInput) {
                $dateStart = $parsedDateStart;
            }
        }

        if ($dateStart) {
            $qb
                ->andWhere($qb->expr()->gte('s.dateStart', ':date_start'))
                ->setParameter('date_start', $dateStart->format('Y-m-d 00:00:00'))
            ;
        }

        $dateEnd = null;
        if (!empty($filters['date_end']) && is_string($filters['date_end'])) {
            $dateEndInput = trim($filters['date_end']);
            $parsedDateEnd = \DateTimeImmutable::createFromFormat('!d/m/Y', $dateEndInput);
            $dateEndErrors = \DateTimeImmutable::getLastErrors();
            $hasDateEndErrors = is_array($dateEndErrors)
                && (($dateEndErrors['warning_count'] ?? 0) > 0 || ($dateEndErrors['error_count'] ?? 0) > 0);

            if (false !== $parsedDateEnd && !$hasDateEndErrors && $parsedDateEnd->format('d/m/Y') === $dateEndInput) {
                $dateEnd = $parsedDateEnd;
            }
        }

        if ($dateEnd) {
            $qb
                ->andWhere($qb->expr()->lte('s.dateStart', ':date_end'))
                ->setParameter('date_end', $dateEnd->format('Y-m-d 23:59:59'))
            ;
        }

        if (isset($filters['status']) && '' !== $filters['status']) {
            $status = (int) $filters['status'];

            if (Session::STATUS_NOT_CANCELED === $status) {
                $qb
                    ->andWhere('s.status != :statusCancel')
                    ->setParameter('statusCancel', Session::STATUS_CANCEL)
                ;
            } else {
                $qb
                    ->andWhere('s.status = :status')
                    ->setParameter('status', $filters['status'])
                ;
            }
        }

        if (!empty($filters['assigned_branches'])) {
            $qb
                ->andWhere($qb->expr()->in('s.branchOffice', ':branches'))
                ->setParameter('branches', $filters['assigned_branches'])
            ;
        }

        if (!empty($filters['exerciseRoom'])) {
            $qb
                ->andWhere('s.exerciseRoom = :exerciseRoom')
                ->setParameter('exerciseRoom', $filters['exerciseRoom'])
            ;
        }

        if (!empty($filters['schedule'])) {
            $qb
                ->andWhere('s.timeStart = :timeStart')
                ->setParameter('timeStart', $filters['schedule'])
            ;
        }

        return $qb->getQuery()->getResult();
    }

    private function applyBackendListFilters(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['type'])) {
            $qb
                ->andWhere('s.type = :type')
                ->setParameter('type', $filters['type'])
            ;
        }

        if (!empty($filters['discipline'])) {
            $qb
                ->andWhere('s.discipline = :discipline')
                ->setParameter('discipline', $filters['discipline'])
            ;
        }

        if (!empty($filters['instructor'])) {
            $qb
                ->andWhere('s.instructor = :instructor')
                ->setParameter('instructor', $filters['instructor'])
            ;
        }

        if (!empty($filters['branchOffice'])) {
            $qb
                ->andWhere('s.branchOffice = :branchOffice')
                ->setParameter('branchOffice', $filters['branchOffice'])
            ;
        }

        $dateStart = null;
        if (!empty($filters['date_start']) && is_string($filters['date_start'])) {
            $dateStartInput = trim($filters['date_start']);
            $parsedDateStart = \DateTimeImmutable::createFromFormat('!d/m/Y', $dateStartInput);
            $dateStartErrors = \DateTimeImmutable::getLastErrors();
            $hasDateStartErrors = is_array($dateStartErrors)
                && (($dateStartErrors['warning_count'] ?? 0) > 0 || ($dateStartErrors['error_count'] ?? 0) > 0);

            if (false !== $parsedDateStart && !$hasDateStartErrors && $parsedDateStart->format('d/m/Y') === $dateStartInput) {
                $dateStart = $parsedDateStart;
            }
        }

        if ($dateStart) {
            $qb
                ->andWhere($qb->expr()->gte('s.dateStart', ':date_start'))
                ->setParameter('date_start', $dateStart->format('Y-m-d 00:00:00'))
            ;
        }

        $dateEnd = null;
        if (!empty($filters['date_end']) && is_string($filters['date_end'])) {
            $dateEndInput = trim($filters['date_end']);
            $parsedDateEnd = \DateTimeImmutable::createFromFormat('!d/m/Y', $dateEndInput);
            $dateEndErrors = \DateTimeImmutable::getLastErrors();
            $hasDateEndErrors = is_array($dateEndErrors)
                && (($dateEndErrors['warning_count'] ?? 0) > 0 || ($dateEndErrors['error_count'] ?? 0) > 0);

            if (false !== $parsedDateEnd && !$hasDateEndErrors && $parsedDateEnd->format('d/m/Y') === $dateEndInput) {
                $dateEnd = $parsedDateEnd;
            }
        }

        if ($dateEnd) {
            $qb
                ->andWhere($qb->expr()->lte('s.dateStart', ':date_end'))
                ->setParameter('date_end', $dateEnd->format('Y-m-d 23:59:59'))
            ;
        }

        if (isset($filters['status']) && '' !== $filters['status']) {
            $status = (int) $filters['status'];

            if (Session::STATUS_NOT_CANCELED === $status) {
                $qb
                    ->andWhere('s.status != :statusCancel')
                    ->setParameter('statusCancel', Session::STATUS_CANCEL)
                ;
            } else {
                $qb
                    ->andWhere('s.status = :status')
                    ->setParameter('status', $filters['status'])
                ;
            }
        }

        if (!empty($filters['assigned_branches'])) {
            $qb
                ->andWhere($qb->expr()->in('s.branchOffice', ':branches'))
                ->setParameter('branches', $filters['assigned_branches'])
            ;
        }

        if (!empty($filters['exerciseRoom'])) {
            $qb
                ->andWhere('s.exerciseRoom = :exerciseRoom')
                ->setParameter('exerciseRoom', $filters['exerciseRoom'])
            ;
        }

        if (!empty($filters['schedule'])) {
            $qb
                ->andWhere('s.timeStart = :timeStart')
                ->setParameter('timeStart', $filters['schedule'])
            ;
        }
    }

    /**
     * Loads sessions for ratings list with relations used by the template to avoid lazy-load N+1.
     *
     * @param int[] $sessionIds
     * @return array<int, Session>
     */
    public function findByIdsForRatings(array $sessionIds): array
    {
        if ([] === $sessionIds) {
            return [];
        }

        $sessions = $this->createQueryBuilder('s')
            ->select('s', 'i', 'p', 'bo', 'er')
            ->join('s.instructor', 'i')
            ->leftJoin('i.profile', 'p')
            ->join('s.branchOffice', 'bo')
            ->join('s.exerciseRoom', 'er')
            ->where('s.id IN (:sessionIds)')
            ->setParameter('sessionIds', array_values(array_unique($sessionIds)))
            ->getQuery()
            ->getResult()
        ;

        $map = [];
        foreach ($sessions as $session) {
            $map[(int) $session->getId()] = $session;
        }

        return $map;
    }

    public function getCalendar(CarbonPeriod $period, BranchOffice $branchOffice): array
    {
        $results = $this->getQueryBuilderInPeriod($period, $branchOffice)->getQuery()->getResult();
        $grouped = [];

        /** @var Session $result */
        foreach ($results as $result) {
            $grouped[$result->getDateStart()->format('Y-m-d')][] = $result;
        }

        return $grouped;
    }

    public function hasSessionsInPeriod(CarbonPeriod $period, BranchOffice $branchOffice): bool
    {
        $qb = $this->getQueryBuilderInPeriod($period, $branchOffice);
        $qb->select($qb->expr()->count('s.id'));

        return (bool) $qb->getQuery()->getSingleScalarResult();
    }

    public function findAllGroupByDateStart()
    {
        $qb = $this->createQueryBuilder('s');

        $qb
            ->select('DISTINCT s.dateStart, b.name branchOffice, b.id branchOfficeId')
            ->join('s.exerciseRoom', 'e')
            ->join('e.branchOffice', 'b')
            ->where('s.dateStart >= :current_date')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('current_date', new \DateTime())
            ->setParameter('statuses', [
                Session::STATUS_OPEN,
                Session::STATUS_FULL,
            ])
            ->groupBy('s.dateStart, b.name')
            ->orderBy('s.dateStart', 'DESC')
        ;

        return $qb->getQuery()->getResult();
    }

    public function updateCapacity(ExerciseRoom $exerciseRoom): int
    {
        $now = new \DateTime();
        $notAvailable = $exerciseRoom->getPlacesNotAvailable() ?? [];
        $today = (clone $now)->setTime(0, 0);
        $capacity = (int) ($exerciseRoom->getCapacity() ?? 0);
        $seatLayout = SeatLayoutMapper::buildPersistedSeatLayout($exerciseRoom->getSeatLayout(), $capacity);
        $availableCapacity = $capacity - count($notAvailable);

        $futureSessions = $this->createQueryBuilder('s')
            ->where('s.exerciseRoom = :exerciseRoom')
            ->andWhere('(s.dateStart > :today OR (s.dateStart = :today AND s.timeStart >= :currentTime))')
            ->setParameter('exerciseRoom', $exerciseRoom)
            ->setParameter('today', $today, Types::DATE_MUTABLE)
            ->setParameter('currentTime', $now, Types::TIME_MUTABLE)
            ->getQuery()
            ->getResult()
        ;

        $sessionIds = array_values(array_filter(array_map(
            static fn (Session $session): ?int => $session->getId(),
            $futureSessions
        ), static fn (?int $sessionId): bool => null !== $sessionId));

        $reservationTotalsBySessionId = $this->getActiveReservationTotalsBySessionIds($sessionIds);

        foreach ($futureSessions as $session) {
            $sessionId = $session->getId();
            $activeReservations = null !== $sessionId
                ? (int) ($reservationTotalsBySessionId[$sessionId] ?? 0)
                : 0;

            $session
                ->setExerciseRoomCapacity($capacity)
                ->setPlacesNotAvailable($notAvailable ?: null)
                ->setSeatLayout($seatLayout)
                ->setAvailableCapacity($availableCapacity)
            ;

            $this->syncSessionAvailabilityStatus($session, $activeReservations);
        }

        return count($futureSessions);
    }

    /**
     * @return Session[]
     */
    public function getFutureByExerciseRoom(ExerciseRoom $exerciseRoom): array
    {
        $now = new \DateTime();
        $today = (clone $now)->setTime(0, 0);

        return $this->createQueryBuilder('s')
            ->where('s.exerciseRoom = :exerciseRoom')
            ->andWhere('(s.dateStart > :today OR (s.dateStart = :today AND s.timeStart >= :currentTime))')
            ->setParameter('exerciseRoom', $exerciseRoom)
            ->setParameter('today', $today, Types::DATE_MUTABLE)
            ->setParameter('currentTime', $now, Types::TIME_MUTABLE)
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * @param int[] $sessionIds
     *
     * @return array<int, int>
     */
    private function getActiveReservationTotalsBySessionIds(array $sessionIds): array
    {
        if ([] === $sessionIds) {
            return [];
        }

        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('IDENTITY(r.session) AS sessionId', 'COUNT(r.id) AS total')
            ->from(Reservation::class, 'r')
            ->where('r.session IN (:sessionIds)')
            ->andWhere('r.isAvailable = :isAvailable')
            ->groupBy('r.session')
            ->setParameter('sessionIds', $sessionIds)
            ->setParameter('isAvailable', true)
            ->getQuery()
            ->getArrayResult()
        ;

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row['sessionId']] = (int) $row['total'];
        }

        return $totals;
    }

    private function syncSessionAvailabilityStatus(Session $session, int $activeReservations): void
    {
        $status = $session->getStatus();
        if (!in_array($status, [Session::STATUS_OPEN, Session::STATUS_FULL], true)) {
            return;
        }

        $availableCapacity = max(0, (int) $session->getAvailableCapacity());

        if ($activeReservations >= $availableCapacity) {
            $session->setStatus(Session::STATUS_FULL);

            return;
        }

        $session->setStatus(Session::STATUS_OPEN);
    }

    /**
     * Retorna sesiones abiertas/llenas cuyo dateStart+timeStart cae entre $from y $to.
     * Carga candidatos por fecha y filtra en PHP usando getDateTimeStart().
     *
     * @return Session[]
     */
    public function getSessionsStartingBetween(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $fromDate = \DateTimeImmutable::createFromInterface($from)->setTime(0, 0, 0);
        $toDate   = \DateTimeImmutable::createFromInterface($to)->setTime(23, 59, 59);

        $candidates = $this->createQueryBuilder('s')
            ->where('s.status IN (:statuses)')
            ->andWhere('s.dateStart >= :fromDate')
            ->andWhere('s.dateStart <= :toDate')
            ->setParameter('statuses', [Session::STATUS_OPEN, Session::STATUS_FULL])
            ->setParameter('fromDate', $fromDate->format('Y-m-d'))
            ->setParameter('toDate', $toDate->format('Y-m-d'))
            ->getQuery()
            ->getResult();

        return array_values(array_filter($candidates, static function (Session $s) use ($from, $to): bool {
            try {
                $start = $s->getDateTimeStart();
            } catch (\Throwable) {
                return false;
            }

            return $start >= $from && $start <= $to;
        }));
    }

    /**
     * @return Session[]
     */
    public function getNotClosed(): array
    {
        $currentDate = new \DateTime();
        $qb = $this->createQueryBuilder('s');

        $qb
            ->andWhere('s.status IN (:status)')
            ->andWhere('(
                ( s.dateStart < :current_date ) OR
                ( s.dateStart = :current_date AND DATEADD(s.timeStart, 10, \'MINUTE\') <= :current_time )
            )')
            ->setParameter('status', [
                Session::STATUS_OPEN,
                Session::STATUS_FULL,
            ])
            ->setParameter('current_date', $currentDate->format('Y-m-d'))
            ->setParameter('current_time', $currentDate->format('H:i:s'))
        ;

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Session[]
     */
    public function getForChange(\DateTimeInterface $dateStart, ?\DateTimeInterface $dateEnd = null): array
    {
        $start = \DateTimeImmutable::createFromInterface($dateStart)->setTime(0, 0, 0);
        $end = $dateEnd
            ? \DateTimeImmutable::createFromInterface($dateEnd)->setTime(23, 59, 59)
            : $start->modify('+30 days')->setTime(23, 59, 59)
        ;

        $qb = $this->createQueryBuilder('s');
        $qb
            ->addSelect('e', 'd', 'i', 'ip', 'b')
            ->join('s.exerciseRoom', 'e')
            ->join('s.discipline', 'd')
            ->join('s.instructor', 'i')
            ->join('i.profile', 'ip')
            ->join('e.branchOffice', 'b')
            ->where('s.dateStart >= :dateStart')
            ->andWhere('s.dateStart <= :dateEnd')
            ->andWhere('s.status = :statusOpen')
            ->orderBy('s.dateStart', 'ASC')
            ->addOrderBy('s.timeStart', 'ASC')
            ->addOrderBy('s.branchOffice', 'ASC')
            ->setParameter('dateStart', $start)
            ->setParameter('dateEnd', $end)
            ->setParameter('statusOpen', Session::STATUS_OPEN)
        ;

        return $qb->getQuery()->getResult();
    }

    private function getQueryBuilderInPeriod(CarbonPeriod $period, BranchOffice $branchOffice): QueryBuilder
    {
        $now = new \DateTime('today');

        $qb = $this->createQueryBuilder('s');
        $qb
            ->where($qb->expr()->between('s.dateStart', ':begin', ':end'))
            ->andWhere('s.branchOffice = :branch_office')
            ->andWhere($qb->expr()->in('s.status', ':status'))
            ->andWhere($qb->expr()->gte('s.dateStart', ':today'))
            ->setParameter('begin', $period->start->toDateString())
            ->setParameter('end', $period->end->toDateString())
            ->setParameter('branch_office', $branchOffice)
            ->setParameter('status', [
                Session::STATUS_OPEN,
                Session::STATUS_FULL,
            ])
            ->setParameter('today', $now)
            ->orderBy('s.timeStart', 'ASC')
        ;

        return $qb;
    }
}
