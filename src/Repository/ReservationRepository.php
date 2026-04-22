<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Reservation;
use App\Entity\Session;
use App\Entity\Transaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reservation>
 *
 * @method Reservation|null find($id, $lockMode = null, $lockVersion = null)
 * @method Reservation|null findOneBy(array $criteria, array $orderBy = null)
 * @method Reservation[]    findAll()
 * @method Reservation[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    public function getTotalReservationsForSession(Session $session): int
    {
        $qb = $this
            ->createQueryBuilder('r')
            ->select('COUNT(r)')
            ->where('r.session = :session')
            ->andWhere('r.isAvailable = :isAvailable')
            ->setParameters([
                'session' => $session,
                'isAvailable' => true,
            ])
        ;

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function getTotalReservationsForTransaction(Transaction $transaction): int
    {
        $qb = $this
            ->createQueryBuilder('r')
            ->select('COUNT(r)')
            ->join('r.session', 's')
            ->where('r.transaction = :transaction')
            ->andWhere('r.isAvailable = :isAvailable')
            ->andWhere('s.status != :sessionStatusCancel')
            ->setParameters([
                'transaction' => $transaction,
                'isAvailable' => true,
                'sessionStatusCancel' => Session::STATUS_CANCEL,
            ])
        ;

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return Reservation[]
     */
    public function getReservationsBySession(Session $session): array
    {
        $qb = $this
            ->createQueryBuilder('r')
            ->addSelect('u')
            ->join('r.user', 'u')
            ->where('r.session = :session')
            ->andWhere('r.isAvailable = :isAvailable')
            ->setParameters([
                'session' => $session,
                'isAvailable' => true,
            ])
        ;

        return $qb->getQuery()->getResult();
    }

    public function getTotalAvailableByTransaction(Transaction $transaction): int
    {
        $qb = $this->createQueryBuilder('r');

        $qb
            ->select('COUNT(r)')
            ->join('r.session', 's')
            ->where('r.transaction = :transaction')
            ->andWhere('r.isAvailable = :available')
            ->andWhere('s.status IN (:session_status)')
            ->setParameter('transaction', $transaction)
            ->setParameter('available', true)
            ->setParameter('session_status', [
                Session::STATUS_OPEN,
                Session::STATUS_FULL,
            ])
        ;

        return $qb->getQuery()->getSingleScalarResult();
    }

    public function getTotalUsedByTransaction(Transaction $transaction): int
    {
        $qb = $this->createQueryBuilder('r');

        $qb
            ->select('COUNT(r)')
            ->join('r.session', 's')
            ->where('r.transaction = :transaction')
            ->andWhere('r.isAvailable = :available')
            ->andWhere('s.status = :session_status')
            ->setParameter('transaction', $transaction)
            ->setParameter('available', true)
            ->setParameter('session_status', Session::STATUS_CLOSED)
        ;

        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return Reservation[]|int
     */
    public function getSessionsTakenByUser(User $user, bool $count = false): array|int
    {
        $qb = $this->getTakenSessionsQueryBuilderByUser($user);

        if ($count) {
            $qb->select('COUNT(r)');

            return $qb->getQuery()->getSingleScalarResult();
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function getTakenSessionsQueryBuilderByUser(User $user, array $filters = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('r');

        $qb
            ->addSelect('s', 'd', 'i', 'p', 'er')
            ->join('r.session', 's')
            ->join('r.transaction', 't')
            ->leftJoin('s.discipline', 'd')
            ->leftJoin('s.instructor', 'i')
            ->leftJoin('i.profile', 'p')
            ->leftJoin('s.exerciseRoom', 'er')
            ->where('r.user = :user')
            ->andWhere('r.isAvailable = true')
            ->andWhere('s.status = :session_status')
            ->andWhere('t.isExpired = :isExpired')
            ->andWhere('t.status = :isPaid')
            ->setParameters([
                'user' => $user,
                'session_status' => Session::STATUS_CLOSED,
                'isExpired' => false,
                'isPaid' => Transaction::STATUS_PAID,
            ])
            ->orderBy('s.dateStart', 'DESC')
            ->addOrderBy('s.timeStart', 'DESC')
        ;

        if (!empty($filters['date_start']) && $filters['date_start'] instanceof \DateTimeInterface) {
            $qb
                ->andWhere('s.dateStart >= :date_start')
                ->setParameter('date_start', $filters['date_start'])
            ;
        }

        if (!empty($filters['date_end']) && $filters['date_end'] instanceof \DateTimeInterface) {
            $qb
                ->andWhere('s.dateStart <= :date_end')
                ->setParameter('date_end', $filters['date_end'])
            ;
        }

        if (!empty($filters['discipline_id'])) {
            $qb
                ->andWhere('d.id = :discipline_id')
                ->setParameter('discipline_id', (int) $filters['discipline_id'])
            ;
        } elseif (!empty($filters['discipline'])) {
            $qb
                ->andWhere('LOWER(d.name) LIKE :discipline')
                ->setParameter('discipline', '%'.mb_strtolower(trim((string) $filters['discipline'])).'%')
            ;
        }

        if (!empty($filters['instructor_id'])) {
            $qb
                ->andWhere('i.id = :instructor_id')
                ->setParameter('instructor_id', (int) $filters['instructor_id'])
            ;
        } elseif (!empty($filters['instructor'])) {
            $instructorTerm = '%'.mb_strtolower(trim((string) $filters['instructor'])).'%';
            $qb
                ->andWhere('LOWER(i.username) LIKE :instructor OR LOWER(p.firstname) LIKE :instructor OR LOWER(p.paternalSurname) LIKE :instructor OR LOWER(p.maternalSurname) LIKE :instructor')
                ->setParameter('instructor', $instructorTerm)
            ;
        }

        if (!empty($filters['class_type'])) {
            $qb
                ->andWhere('s.type = :class_type')
                ->setParameter('class_type', (string) $filters['class_type'])
            ;
        }

        return $qb;
    }

    /**
     * @return Reservation[]
     */
    public function getRecentTakenSessionsByUser(User $user, \DateTimeInterface $dateStart): array
    {
        return $this
            ->getTakenSessionsQueryBuilderByUser($user, [
                'date_start' => $dateStart,
            ])
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * @return Reservation[]|int
     */
    /**
     * Devuelve los IDs de sesiones en las que el usuario ya tiene reserva activa
     * dentro del rango de fechas dado.
     *
     * @return int[]
     */
    public function getReservedSessionIdsByUser(User $user, \DateTimeInterface $dateStart, \DateTimeInterface $dateEnd): array
    {
        return $this->createQueryBuilder('r')
            ->select('IDENTITY(r.session) as sessionId')
            ->join('r.session', 's')
            ->where('r.user = :user')
            ->andWhere('r.isAvailable = true')
            ->andWhere('s.dateStart >= :dateStart')
            ->andWhere('s.dateStart <= :dateEnd')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('dateStart', $dateStart)
            ->setParameter('dateEnd', $dateEnd)
            ->setParameter('statuses', [Session::STATUS_OPEN, Session::STATUS_FULL])
            ->getQuery()
            ->getSingleColumnResult()
        ;
    }

    /**
     * Returns [sessionId => count] for active reservations of a user in a date range.
     *
     * @return array<int, int>
     */
    public function getReservedSessionCountsByUser(User $user, \DateTimeInterface $dateStart, \DateTimeInterface $dateEnd): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.session) as sessionId, COUNT(r.id) as cnt')
            ->join('r.session', 's')
            ->where('r.user = :user')
            ->andWhere('r.isAvailable = true')
            ->andWhere('s.dateStart >= :dateStart')
            ->andWhere('s.dateStart <= :dateEnd')
            ->andWhere('s.status IN (:statuses)')
            ->groupBy('r.session')
            ->setParameter('user', $user)
            ->setParameter('dateStart', $dateStart)
            ->setParameter('dateEnd', $dateEnd)
            ->setParameter('statuses', [Session::STATUS_OPEN, Session::STATUS_FULL])
            ->getQuery()
            ->getArrayResult()
        ;

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['sessionId']] = (int) $row['cnt'];
        }

        return $counts;
    }

    public function getReservedSessionsByUser(User $user, bool $count = false): array|int
    {
        $qb = $this->createQueryBuilder('r');

        $qb
            ->addSelect('s', 'd', 'i', 'p', 'bo', 'er')
            ->join('r.session', 's')
            ->join('s.discipline', 'd')
            ->join('s.instructor', 'i')
            ->join('i.profile', 'p')
            ->join('s.branchOffice', 'bo')
            ->join('s.exerciseRoom', 'er')
            ->where('r.user = :user')
            ->andWhere('r.isAvailable = true')
            ->andWhere('s.status IN (:session_status)')
            ->setParameters([
                'user' => $user,
                'session_status' => [Session::STATUS_OPEN, Session::STATUS_FULL],
            ])
            ->orderBy('s.dateStart', 'ASC')
            ->addOrderBy('s.timeStart', 'ASC')
        ;

        if ($count) {
            $qb->select('COUNT(r)');

            return $qb->getQuery()->getSingleScalarResult();
        }

        return $qb->getQuery()->getResult();
    }

    public function getReservationPlacesByUserSession(User $user, Session $session): array
    {
        $qb = $this->createQueryBuilder('r');

        $qb
            ->select('r.placeNumber')
            ->where('r.session = :session')
            ->andWhere('r.user = :user')
            ->andWhere('r.isAvailable = :isAvailable')
            ->setParameter('session', $session)
            ->setParameter('user', $user)
            ->setParameter('isAvailable', true)
        ;

        return $qb->getQuery()->getSingleColumnResult();
    }

    public function hasUnlimitedReservationsByUserSession(User $user, Session $session): bool
    {
        $qb = $this->createQueryBuilder('r');

        $qb
            ->select($qb->expr()->count('r.id'))
            ->join('r.transaction', 't')
            ->where('r.session = :session')
            ->andWhere('r.user = :user')
            ->andWhere('r.isAvailable = :isAvailable')
            ->andWhere('t.packageIsUnlimited = :packageUnlimited')
            ->setParameter('session', $session)
            ->setParameter('user', $user)
            ->setParameter('isAvailable', true)
            ->setParameter('packageUnlimited', true)
        ;

        return $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function getUnlimitedReservationsByUser(User $user, \DateTimeInterface $date): int
    {
        $qb = $this->createQueryBuilder('r');

        $qb
            ->select($qb->expr()->count('r.id'))
            ->join('r.session', 's')
            ->join('r.transaction', 't')
            ->where('s.dateStart = :date')
            ->andWhere('r.user = :user')
            ->andWhere('t.packageIsUnlimited = :packageUnlimited')
            ->andWhere('r.isAvailable = :isAvailable')
            ->setParameter('date', $date)
            ->setParameter('user', $user)
            ->setParameter('packageUnlimited', true)
            ->setParameter('isAvailable', true)
        ;

        return $qb->getQuery()->getSingleScalarResult();
    }

    public function getUnlimitedReservationsByUserAndPackageType(
        User $user,
        \DateTimeInterface $date,
        string $packageType,
    ): int {
        $qb = $this->createQueryBuilder('r');

        $qb
            ->select($qb->expr()->count('r.id'))
            ->join('r.session', 's')
            ->join('r.transaction', 't')
            ->where('s.dateStart = :date')
            ->andWhere('r.user = :user')
            ->andWhere('t.packageIsUnlimited = :packageUnlimited')
            ->andWhere('t.packageType = :packageType')
            ->andWhere('r.isAvailable = :isAvailable')
            ->setParameter('date', $date)
            ->setParameter('user', $user)
            ->setParameter('packageUnlimited', true)
            ->setParameter('packageType', $packageType)
            ->setParameter('isAvailable', true)
        ;

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function getByUserList(User $user, array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('r');

        $qb
            ->addSelect('s', 'i', 'p', 'bo', 'er')
            ->join('r.session', 's')
            ->join('s.instructor', 'i')
            ->join('i.profile', 'p')
            ->join('s.branchOffice', 'bo')
            ->join('s.exerciseRoom', 'er')
            ->where('r.user = :user')
            ->andWhere('s.status != :session_status_cancel')
            ->orderBy('r.createdAt', 'DESC')
            ->setParameter('user', $user)
            ->setParameter('session_status_cancel', Session::STATUS_CANCEL)
        ;

        if ('' !== $filters['filter_status']) {
            $qb
                ->andWhere('r.isAvailable = :isAvailable')
                ->setParameter('isAvailable', $filters['filter_status'])
            ;
        }

        if ($filters['filter_session_date_start'] && $filters['filter_session_date_end']) {
            $dateStart = \DateTime::createFromFormat('d/m/Y', $filters['filter_session_date_start']);
            $dateEnd = \DateTime::createFromFormat('d/m/Y', $filters['filter_session_date_end']);

            $qb
                ->andWhere('s.dateStart BETWEEN :date_start AND :date_end')
                ->setParameter('date_start', $dateStart->format('Y-m-d 00:00:00'))
                ->setParameter('date_end', $dateEnd->format('Y-m-d 23:59:59'))
            ;
        } elseif ($filters['filter_session_date_start']) {
            $dateStart = \DateTime::createFromFormat('d/m/Y', $filters['filter_session_date_start']);

            $qb
                ->andWhere('s.dateStart >= :date_start')
                ->setParameter('date_start', $dateStart->format('Y-m-d 00:00:00'))
            ;
        } elseif ($filters['filter_session_date_end']) {
            $dateEnd = \DateTime::createFromFormat('d/m/Y', $filters['filter_session_date_end']);

            $qb
                ->andWhere('s.dateStart <= :date_end')
                ->setParameter('date_end', $dateEnd->format('Y-m-d 23:59:59'))
            ;
        }

        if (!empty($filters['filter_branch_office'])) {
            $qb
                ->andWhere('s.branchOffice = :branchOffice')
                ->setParameter('branchOffice', $filters['filter_branch_office'])
            ;
        }

        if (!empty($filters['filter_exercise_room'])) {
            $qb
                ->andWhere('s.exerciseRoom = :exerciseRoom')
                ->setParameter('exerciseRoom', $filters['filter_exercise_room'])
            ;
        }

        return $qb;
    }

    public function getGroupedInstructorStudio(\DateTime $from, \DateTime $to): array
    {
        $qb = $this->createQueryBuilder('r');

        $qb
            ->select('COUNT(r.id) as reservations')
            ->addSelect('b.id as studioId', 'b.name as studio')
            ->addSelect('i.id as instructorId', 'p.firstname', 'p.paternalSurname', 'p.maternalSurname')
            ->join('r.session', 's')
            ->join('s.instructor', 'i')
            ->join('i.profile', 'p')
            ->join('s.branchOffice', 'b')
            ->where($qb->expr()->between('s.dateStart', ':from', ':to'))
            ->andWhere('r.isAvailable = :isAvailable')
            ->andWhere('s.status = :statusClosed')
            ->groupBy('b.id')
            ->addGroupBy('b.name')
            ->addGroupBy('i.id')
            ->addGroupBy('p.firstname')
            ->addGroupBy('p.paternalSurname')
            ->addGroupBy('p.maternalSurname')
            ->orderBy('b.name', 'ASC')
            ->addOrderBy('p.firstname', 'ASC')
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->setParameter('isAvailable', true)
            ->setParameter('statusClosed', Session::STATUS_CLOSED)
        ;

        return $qb->getQuery()->getScalarResult();
    }

    /**
     * Returns a map of sessionId => count of available (confirmed) reservations for each given session.
     *
     * @param int[] $sessionIds
     * @return array<int, int>
     */
    public function getAvailableCountForSessions(array $sessionIds): array
    {
        if ([] === $sessionIds) {
            return [];
        }

        $result = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.session) as sessionId', 'COUNT(r.id) as cnt')
            ->where('r.session IN (:sessionIds)')
            ->andWhere('r.isAvailable = :isAvailable')
            ->groupBy('r.session')
            ->setParameter('sessionIds', $sessionIds)
            ->setParameter('isAvailable', true)
            ->getQuery()
            ->getScalarResult()
        ;

        $map = [];
        foreach ($result as $row) {
            $map[(int) $row['sessionId']] = (int) $row['cnt'];
        }

        return $map;
    }

    /**
     * @return Reservation[]
     */
    public function getAvailableForDate(\DateTimeInterface $date): array
    {
        $start = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0, 0);
        $end = \DateTimeImmutable::createFromInterface($date)->setTime(23, 59, 59);

        $qb = $this->createQueryBuilder('r');

        $qb
            ->addSelect('u', 's', 'd', 'i', 'ip', 'bo', 'er')
            ->join('r.user', 'u')
            ->join('r.session', 's')
            ->join('s.discipline', 'd')
            ->join('s.instructor', 'i')
            ->join('i.profile', 'ip')
            ->join('s.branchOffice', 'bo')
            ->join('s.exerciseRoom', 'er')
            ->where('r.isAvailable = :isAvailable')
            ->andWhere('u.enabled = :userEnabled')
            ->andWhere('s.status IN (:sessionStatuses)')
            ->andWhere('s.dateStart BETWEEN :start AND :end')
            ->setParameter('isAvailable', true)
            ->setParameter('userEnabled', true)
            ->setParameter('sessionStatuses', [Session::STATUS_OPEN, Session::STATUS_FULL])
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('u.id', 'ASC')
            ->addOrderBy('s.timeStart', 'ASC')
            ->addOrderBy('r.id', 'ASC')
        ;

        return $qb->getQuery()->getResult();
    }

    public function getRatedSessionsReportQueryBuilder(
        array $filters = [],
        string $selectedAverageType = '',
        ?float $requiredAverage = null,
    ): QueryBuilder
    {
        $generalNumeratorExpression = 'SUM(CASE WHEN r.ratingExercise IS NOT NULL THEN r.ratingExercise ELSE 0 END) + SUM(CASE WHEN r.ratingInstructor IS NOT NULL THEN r.ratingInstructor ELSE 0 END) + SUM(CASE WHEN r.ratingClassType IS NOT NULL THEN r.ratingClassType ELSE 0 END)';
        $generalDenominatorExpression = 'SUM(CASE WHEN r.ratingExercise IS NOT NULL THEN 1 ELSE 0 END) + SUM(CASE WHEN r.ratingInstructor IS NOT NULL THEN 1 ELSE 0 END) + SUM(CASE WHEN r.ratingClassType IS NOT NULL THEN 1 ELSE 0 END)';

        $qb = $this->getEntityManager()->createQueryBuilder();

        $qb
            ->select('s.id AS sessionId')
            ->addSelect('AVG(r.ratingExercise) AS ratingExerciseAverage')
            ->addSelect('AVG(r.ratingInstructor) AS ratingInstructorAverage')
            ->addSelect('AVG(r.ratingClassType) AS ratingClassTypeAverage')
            ->addSelect(sprintf('(%s) / (%s) AS ratingGeneralAverage', $generalNumeratorExpression, $generalDenominatorExpression))
            ->from(Session::class, 's')
            ->innerJoin('s.reservations', 'r')
            ->where('r.isAvailable = :isAvailable')
            ->andWhere('s.status = :statusClosed')
            ->andWhere('r.ratedAt IS NOT NULL')
            ->andWhere('r.ratingExercise IS NOT NULL OR r.ratingInstructor IS NOT NULL OR r.ratingClassType IS NOT NULL')
            ->groupBy('s.id')
            ->setParameter('isAvailable', true)
            ->setParameter('statusClosed', Session::STATUS_CLOSED)
            ->orderBy('s.dateStart', 'DESC')
            ->addOrderBy('s.timeStart', 'DESC')
        ;

        if (!empty($filters['date_start']) && $filters['date_start'] instanceof \DateTimeInterface) {
            $dateStart = \DateTimeImmutable::createFromInterface($filters['date_start'])->setTime(0, 0, 0);
            $qb
                ->andWhere('s.dateStart >= :dateStart')
                ->setParameter('dateStart', $dateStart)
            ;
        }

        if (!empty($filters['date_end']) && $filters['date_end'] instanceof \DateTimeInterface) {
            $dateEnd = \DateTimeImmutable::createFromInterface($filters['date_end'])->setTime(23, 59, 59);
            $qb
                ->andWhere('s.dateStart <= :dateEnd')
                ->setParameter('dateEnd', $dateEnd)
            ;
        }

        if (!empty($filters['branch_office'])) {
            $qb
                ->andWhere('s.branchOffice = :branchOffice')
                ->setParameter('branchOffice', (int) $filters['branch_office'])
            ;
        }

        if (!empty($filters['instructor'])) {
            $qb
                ->andWhere('s.instructor = :instructor')
                ->setParameter('instructor', (int) $filters['instructor'])
            ;
        }

        if (!empty($filters['discipline'])) {
            $qb
                ->andWhere('s.discipline = :discipline')
                ->setParameter('discipline', (int) $filters['discipline'])
            ;
        }

        if (!empty($filters['exercise_room'])) {
            $qb
                ->andWhere('s.exerciseRoom = :exerciseRoom')
                ->setParameter('exerciseRoom', (int) $filters['exercise_room'])
            ;
        }

        if (!empty($filters['schedule'])) {
            $scheduleNormalized = null;

            foreach (['H:i', 'H:i:s'] as $format) {
                $schedule = \DateTimeImmutable::createFromFormat('!' . $format, (string) $filters['schedule']);
                $errors = \DateTimeImmutable::getLastErrors();

                $hasParseErrors = is_array($errors)
                    && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);

                if (false !== $schedule && !$hasParseErrors) {
                    $scheduleNormalized = $schedule->format('H:i:s');
                    break;
                }
            }

            if (null !== $scheduleNormalized) {
                $qb
                    ->andWhere('s.timeStart = :schedule')
                    ->setParameter('schedule', $scheduleNormalized)
                ;
            }
        }

        if (null !== $requiredAverage) {
            // HAVING in the outer query breaks KNP Paginator's COUNT walker, because KNP
            // generates a SELECT COUNT(...) wrapper and CountWalker rejects HAVING.
            // Workaround: move the aggregate filter into a WHERE IN subquery so the outer
            // query has no HAVING. CountWalker handles a plain grouped SELECT just fine.
            $subGenNumerator   = 'SUM(CASE WHEN r2.ratingExercise IS NOT NULL THEN r2.ratingExercise ELSE 0 END)'
                . ' + SUM(CASE WHEN r2.ratingInstructor IS NOT NULL THEN r2.ratingInstructor ELSE 0 END)'
                . ' + SUM(CASE WHEN r2.ratingClassType IS NOT NULL THEN r2.ratingClassType ELSE 0 END)';
            $subGenDenominator = 'SUM(CASE WHEN r2.ratingExercise IS NOT NULL THEN 1 ELSE 0 END)'
                . ' + SUM(CASE WHEN r2.ratingInstructor IS NOT NULL THEN 1 ELSE 0 END)'
                . ' + SUM(CASE WHEN r2.ratingClassType IS NOT NULL THEN 1 ELSE 0 END)';

            $subHavingExpr = match ($selectedAverageType) {
                'exercise'   => 'AVG(r2.ratingExercise)',
                'instructor' => 'AVG(r2.ratingInstructor)',
                'class_type' => 'AVG(r2.ratingClassType)',
                default      => sprintf('(%s) / (%s)', $subGenNumerator, $subGenDenominator),
            };

            $subQb = $this->getEntityManager()->createQueryBuilder();
            $subQb
                ->select('s2.id')
                ->from(Session::class, 's2')
                ->innerJoin('s2.reservations', 'r2')
                ->where('r2.isAvailable = :isAvailable')
                ->andWhere('s2.status = :statusClosed')
                ->andWhere('r2.ratedAt IS NOT NULL')
                ->andWhere('r2.ratingExercise IS NOT NULL OR r2.ratingInstructor IS NOT NULL OR r2.ratingClassType IS NOT NULL')
                ->groupBy('s2.id')
                ->having(sprintf('%s >= :requiredAverage', $subHavingExpr))
            ;

            $qb
                ->andWhere($qb->expr()->in('s.id', $subQb->getDQL()))
                ->setParameter('requiredAverage', $requiredAverage)
            ;
        }

        return $qb;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRatedSessionsReport(array $filters = []): array
    {
        return $this->getRatedSessionsReportQueryBuilder($filters)
            ->getQuery()
            ->getResult()
        ;
    }

    public function getGroupedByDay(?\DateTime $from = null): array
    {
        $qb = $this->createQueryBuilder('r');

        $qb
            ->select('COUNT(r.id) as reservations')
            ->addSelect('DAYNAME(s.dateStart) as day')
            ->addSelect('b.id as studioId', 'b.name as studio')
            ->join('r.session', 's')
            ->join('s.branchOffice', 'b')
            ->where('r.isAvailable = :isAvailable')
            ->andWhere('b.public = :public')
            ->groupBy('s.branchOffice')
            ->addGroupBy('day')
            ->orderBy('reservations', 'DESC')
            ->setParameter('isAvailable', true)
            ->setParameter('public', true)
        ;

        if ($from) {
            $qb
                ->andWhere('s.dateStart >= :from')
                ->setParameter('from', $from->format('Y-m-d'));
        }

        return $qb->getQuery()->getScalarResult();
    }

    public function getGroupedBySchedule(?\DateTime $from = null): array
    {
        $qb = $this->createQueryBuilder('r');

        $qb
            ->select('COUNT(r.id) as reservations')
            ->addSelect('s.timeStart as schedule')
            ->addSelect('b.id as studioId', 'b.name as studio')
            ->join('r.session', 's')
            ->join('s.branchOffice', 'b')
            ->where('r.isAvailable = :isAvailable')
            ->andWhere('b.public = :public')
            ->groupBy('s.branchOffice')
            ->addGroupBy('s.timeStart')
            ->orderBy('reservations', 'DESC')
            ->setParameter('isAvailable', true)
            ->setParameter('public', true)
        ;

        if ($from) {
            $qb
                ->andWhere('s.dateStart >= :from')
                ->setParameter('from', $from->format('Y-m-d'));
        }

        return $qb->getQuery()->getScalarResult();
    }

    /**
     * @return array{
     *   sessionId:int,
     *   ratedReservations:int,
     *   averages:array{general:?float,exercise:?float,instructor:?float,class_type:?float},
     *   distribution:array{exercise:array<int,int>,instructor:array<int,int>,class_type:array<int,int>},
     *   ratings:array<int, array<string, mixed>>
     * }
     */
    public function getSessionRatingBreakdown(int $sessionId): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.id AS reservationId')
            ->addSelect('r.placeNumber AS placeNumber')
            ->addSelect('u.id AS userId')
            ->addSelect('u.name AS userName')
            ->addSelect('u.lastname AS userLastname')
            ->addSelect('r.ratingExercise AS ratingExercise')
            ->addSelect('r.ratingInstructor AS ratingInstructor')
            ->addSelect('r.ratingClassType AS ratingClassType')
            ->addSelect('r.ratedAt AS ratedAt')
            ->join('r.session', 's')
            ->leftJoin('r.user', 'u')
            ->where('s.id = :sessionId')
            ->andWhere('r.isAvailable = :isAvailable')
            ->andWhere('s.status = :statusClosed')
            ->andWhere('r.ratedAt IS NOT NULL')
            ->andWhere('r.ratingExercise IS NOT NULL OR r.ratingInstructor IS NOT NULL OR r.ratingClassType IS NOT NULL')
            ->orderBy('r.ratedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setParameter('sessionId', $sessionId)
            ->setParameter('isAvailable', true)
            ->setParameter('statusClosed', Session::STATUS_CLOSED)
            ->getQuery()
            ->getArrayResult()
        ;

        $distribution = [
            'exercise' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
            'instructor' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
            'class_type' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
        ];

        $exerciseSum = 0.0;
        $exerciseCount = 0;
        $instructorSum = 0.0;
        $instructorCount = 0;
        $classTypeSum = 0.0;
        $classTypeCount = 0;
        $generalSum = 0.0;
        $generalCount = 0;

        foreach ($rows as &$row) {
            $ratings = [];

            if (null !== $row['ratingExercise']) {
                $rating = (int) $row['ratingExercise'];
                $distribution['exercise'][$rating] = ($distribution['exercise'][$rating] ?? 0) + 1;
                $exerciseSum += $rating;
                ++$exerciseCount;
                $ratings[] = $rating;
            }

            if (null !== $row['ratingInstructor']) {
                $rating = (int) $row['ratingInstructor'];
                $distribution['instructor'][$rating] = ($distribution['instructor'][$rating] ?? 0) + 1;
                $instructorSum += $rating;
                ++$instructorCount;
                $ratings[] = $rating;
            }

            if (null !== $row['ratingClassType']) {
                $rating = (int) $row['ratingClassType'];
                $distribution['class_type'][$rating] = ($distribution['class_type'][$rating] ?? 0) + 1;
                $classTypeSum += $rating;
                ++$classTypeCount;
                $ratings[] = $rating;
            }

            $row['userDisplay'] = trim(sprintf('%s %s', (string) ($row['userName'] ?? ''), (string) ($row['userLastname'] ?? '')));
            if ('' === $row['userDisplay']) {
                $row['userDisplay'] = $row['userId'] ? sprintf('Usuario #%d', (int) $row['userId']) : 'Usuario';
            }

            $row['ratingGeneral'] = [] !== $ratings
                ? round(array_sum($ratings) / count($ratings), 1)
                : null;

            if (null !== $row['ratingGeneral']) {
                $generalSum += (float) $row['ratingGeneral'];
                ++$generalCount;
            }
        }
        unset($row);

        return [
            'sessionId' => $sessionId,
            'ratedReservations' => count($rows),
            'averages' => [
                'general' => $generalCount > 0 ? round($generalSum / $generalCount, 1) : null,
                'exercise' => $exerciseCount > 0 ? round($exerciseSum / $exerciseCount, 1) : null,
                'instructor' => $instructorCount > 0 ? round($instructorSum / $instructorCount, 1) : null,
                'class_type' => $classTypeCount > 0 ? round($classTypeSum / $classTypeCount, 1) : null,
            ],
            'distribution' => $distribution,
            'ratings' => $rows,
        ];
    }

    public function getStudiosGroupedByCustomer(\DateTime $from): array
    {
        $fromStart = \DateTimeImmutable::createFromInterface($from)->setTime(0, 0, 0);

        $qb = $this->createQueryBuilder('r');

        $qb
            ->select('b.id')
            ->distinct()
            ->join('r.session', 's')
            ->join('s.branchOffice', 'b')
            ->where('r.createdAt >= :from')
            ->andWhere('r.isAvailable = :isAvailable')
            ->andWhere('b.public = :public')
            ->orderBy('b.name', 'ASC')
            ->setParameter('from', $fromStart)
            ->setParameter('isAvailable', true)
            ->setParameter('public', true)
        ;

        return $qb->getQuery()->getSingleColumnResult();
    }

    public function getGroupedByCustomer(int $studioId, \DateTime $from): array
    {
        $fromStart = \DateTimeImmutable::createFromInterface($from)->setTime(0, 0, 0);

        $qb = $this->createQueryBuilder('r');

        $qb
            ->select('COUNT(r.id) as reservations')
            ->addSelect('u.email', 'CONCAT(u.name, \' \', u.lastname) as customer')
            ->addSelect('b.id as studioId', 'b.name as studio')
            ->join('r.session', 's')
            ->join('s.branchOffice', 'b')
            ->join('r.user', 'u')
            ->join('r.transaction', 't')
            ->where('r.createdAt >= :from')
            ->andWhere('s.branchOffice = :studio')
            ->andWhere('r.isAvailable = :isAvailable')
            ->andWhere('t.chargeMethod != :notChargeMethod')
            ->groupBy('u.id')
            ->orderBy('reservations', 'DESC')
            ->setParameter('from', $fromStart)
            ->setParameter('studio', $studioId)
            ->setParameter('isAvailable', true)
            ->setParameter('notChargeMethod', Transaction::CHARGE_METHOD_FREE)
            ->setMaxResults(5)
        ;

        return $qb->getQuery()->getScalarResult();
    }

    public function getGroupedByCustomerForStudios(\DateTime $from): array
    {
        $fromStart = \DateTimeImmutable::createFromInterface($from)->setTime(0, 0, 0);

        $qb = $this->createQueryBuilder('r');

        $qb
            ->select('COUNT(r.id) as reservations')
            ->addSelect('u.id as userId', 'u.email', 'CONCAT(u.name, \' \', u.lastname) as customer')
            ->addSelect('b.id as studioId', 'b.name as studio')
            ->join('r.session', 's')
            ->join('s.branchOffice', 'b')
            ->join('r.user', 'u')
            ->join('r.transaction', 't')
            ->where('r.createdAt >= :from')
            ->andWhere('r.isAvailable = :isAvailable')
            ->andWhere('b.public = :public')
            ->andWhere('t.chargeMethod != :notChargeMethod')
            ->groupBy('b.id')
            ->addGroupBy('b.name')
            ->addGroupBy('u.id')
            ->addGroupBy('u.email')
            ->addGroupBy('u.name')
            ->addGroupBy('u.lastname')
            ->orderBy('b.name', 'ASC')
            ->addOrderBy('reservations', 'DESC')
            ->setParameter('from', $fromStart)
            ->setParameter('isAvailable', true)
            ->setParameter('public', true)
            ->setParameter('notChargeMethod', Transaction::CHARGE_METHOD_FREE)
        ;

        return $qb->getQuery()->getScalarResult();
    }

    public function getGroupedByExerciseRoom(?\DateTime $from = null): array
    {
        $qb = $this->createQueryBuilder('r');

        $qb
            ->select('COUNT(r.id) as reservations')
            ->addSelect('e.id as exerciseRoomId')
            ->join('r.session', 's')
            ->join('s.exerciseRoom', 'e')
            ->where('s.dateStart >= :from')
            ->andWhere('s.status != :statusCancel')
            ->andWhere('r.isAvailable = :isAvailable')
            ->groupBy('s.exerciseRoom')
            ->setParameter('statusCancel', Session::STATUS_CANCEL)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('isAvailable', true)
        ;

        return $qb->getQuery()->getScalarResult();
    }

    /**
     * Encuentra reservaciones activas con placeNumber > $capacity.
     * Utilizado para detectar conflictos cuando se reduce la capacidad de una sesión.
     *
     * @param Session $session  Sesión a evaluar
     * @param int     $capacity Nueva capacidad; se buscan reservas con placeNumber MAYOR a este valor
     *
     * @return Reservation[] Array de reservaciones afectadas
     */
    public function findActiveBySessionAboveCapacity(Session $session, int $capacity): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('u', 't')
            ->join('r.user', 'u')
            ->join('r.transaction', 't')
            ->where('r.session = :session')
            ->andWhere('r.isAvailable = :isAvailable')
            ->andWhere('r.placeNumber > :capacity')
            ->setParameter('session', $session)
            ->setParameter('isAvailable', true)
            ->setParameter('capacity', $capacity)
            ->orderBy('r.placeNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Encuentra reservaciones activas en sesión con lugares específicos
     * Utilizado para detectar conflictos cuando se deshabilitan asientos
     * 
     * @param Session $session Sesión a evaluar
     * @param array $placeNumbers Asientos a verificar [1, 3, 5]
     * @return Reservation[] Array de reservaciones afectadas
     */
    public function findActiveBySessionAndPlaces(Session $session, array $placeNumbers): array
    {
        if (empty($placeNumbers)) {
            return [];
        }

        return $this->createQueryBuilder('r')
            ->addSelect('u', 't')  // Eager load de User y Transaction para evitar N+1
            ->join('r.user', 'u')
            ->join('r.transaction', 't')
            ->where('r.session = :session')
            ->andWhere('r.isAvailable = :isAvailable')
            ->andWhere('r.placeNumber IN (:places)')
            ->setParameter('session', $session)
            ->setParameter('isAvailable', true)
            ->setParameter('places', $placeNumbers)
            ->orderBy('r.placeNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
