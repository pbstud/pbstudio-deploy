<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Transaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 *
 * @method User|null find($id, $lockMode = null, $lockVersion = null)
 * @method User|null findOneBy(array $criteria, array $orderBy = null)
 * @method User[]    findAll()
 * @method User[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * @return User[]
     */
    public function findForBackendList(?string $search = null): array
    {
        return $this->querySearch($search)->getQuery()->getResult();
    }

    public function findWithFilters(array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('u');
        $qb
            ->addSelect('bo')
            ->leftJoin('u.branchOffice', 'bo')
            ->orderBy('u.id', 'DESC')
            ->distinct(); // Issue #49: Evitar duplicados en búsqueda integrada

        if (!empty($filters['id'])) {
            $qb
                ->andWhere('u.id = :id')
                ->setParameter('id', $filters['id'])
            ;
        }

        // Issue #49: Estrategia híbrida para búsquedas por nombre/apellido.
        if (!empty($filters['name'])) {
            $this->applyNameLastnameFilter($qb, (string) $filters['name'], 'name');
        }

        if (!empty($filters['lastname'])) {
            $this->applyNameLastnameFilter($qb, (string) $filters['lastname'], 'lastname');
        }

        if (!empty($filters['email'])) {
            $normalizedEmail = trim($filters['email']);
            $qb
                ->andWhere($qb->expr()->like('u.email', ':email'))
                ->setParameter('email', '%'.$normalizedEmail.'%')
            ;
        }

        if (!empty($filters['branch_office'])) {
            $qb
                ->andWhere('bo.id = :branchOffice')
                ->setParameter('branchOffice', (int) $filters['branch_office'])
            ;
        }

        $dateStart = !empty($filters['date_start']) ? \DateTime::createFromFormat('d/m/Y', $filters['date_start']) : null;
        if ($dateStart) {
            $qb
                ->andWhere($qb->expr()->gte('u.createdAt', ':dateStart'))
                ->setParameter('dateStart', $dateStart->format('Y-m-d 00:00:00'))
            ;
        }

        $dateEnd = !empty($filters['date_end']) ? \DateTime::createFromFormat('d/m/Y', $filters['date_end']) : null;
        if ($dateEnd) {
            $qb
                ->andWhere($qb->expr()->lte('u.createdAt', ':dateEnd'))
                ->setParameter('dateEnd', $dateEnd->format('Y-m-d 23:59:59'))
            ;
        }

        if (isset($filters['enabled']) && '' !== $filters['enabled']) {
            $qb
                ->andWhere($qb->expr()->eq('u.enabled', ':enabled'))
                ->setParameter('enabled', $filters['enabled'])
            ;
        }

        if (!empty($filters['package_enabled'])) {
            $sqb = $this->_em->createQueryBuilder()
                ->select('t.id')
                ->from(Transaction::class, 't')
                ->where('t.user = u')
                ->andWhere('t.status = :statusPaid')
                ->andWhere('t.isExpired = :notExpired')
                ->andWhere('t.haveSessionsAvailable = :haveSessionsAvailable')
            ;

            $qb
                ->andWhere($qb->expr()->exists($sqb->getDQL()))
                ->setParameter('statusPaid', Transaction::STATUS_PAID)
                ->setParameter('notExpired', false)
                ->setParameter('haveSessionsAvailable', true)
            ;
        }

        return $qb;
    }

    public function export(array $filters, ?bool $enabled = null): array
    {
        $qb = $this->findWithFilters($filters);

        $qb
            ->resetDQLPart('select')
            ->select('u.id', 'u.name', 'u.lastname', 'u.phone', 'u.birthday', 'u.email', 'u.enabled')
            ->addSelect('bo.name as branchOffice')
        ;

        if (is_bool($enabled)) {
            $qb
                ->andWhere('u.enabled = :enabled')
                ->setParameter('enabled', $enabled)
            ;
        }

        return $qb->getQuery()->getScalarResult();
    }

    /**
     * Obtiene el total de usuarios activos.
     */
    public function getTotalActiveUsers(): int
    {
        $qb = $this->createQueryBuilder('u');

        $qb
            ->select('COUNT(u)')
            ->where('u.enabled = :enabled')
            ->setParameter('enabled', true)
        ;

        try {
            $result = $qb->getQuery()->getSingleScalarResult();
        } catch (\Exception $e) {
            $result = 0;
        }

        return $result;
    }

    /**
     * @return User[]
     */
    public function getLastUsers(int $limit = 3): array
    {
        $qb = $this->createQueryBuilder('u');
        $qb
            ->where('u.enabled = :enabled')
            ->setParameter('enabled', true)
            ->orderBy('u.createdAt', 'DESC')
            ->setMaxResults($limit)
        ;

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Transaction[]
     */
    public function getUnlimitedTransactionsAvailable(User $user): array
    {
        $qb = $this
            ->getEntityManager()
            ->getRepository(Transaction::class)
            ->createQueryBuilder('t')
        ;

        $qb
            ->select(['t', 'p'])
            ->join('t.package', 'p')
            ->where('t.user = :user')
            ->andWhere('t.status = :status')
            ->andWhere('t.isExpired = :isExpired')
            ->andWhere('t.packageIsUnlimited = :packageIsUnlimited')
            ->setParameters([
                'user' => $user,
                'status' => Transaction::STATUS_PAID,
                'isExpired' => false,
                'packageIsUnlimited' => true,
            ])
            ->orderBy('t.createdAt', 'ASC')
        ;

        return $qb->getQuery()->getResult();
    }

    public function findByEmail(string $email): ?User
    {
        $qb = $this->createQueryBuilder('u');

        $qb
            ->where('u.email = :email')
            ->setParameter('email', $email)
        ;

        try {
            $result = $qb->getQuery()->getOneOrNullResult();
        } catch (\Exception $e) {
            $result = null;
        }

        return $result;
    }

    public function findByConfirmationToken(string $token): ?User
    {
        $qb = $this->createQueryBuilder('u');

        $qb
            ->where('u.confirmationToken = :token')
            ->setParameter('token', $token)
        ;

        try {
            $result = $qb->getQuery()->getOneOrNullResult();
        } catch (\Exception $e) {
            $result = null;
        }

        return $result;
    }

    public function getTotal(?\DateTime $from = null): int
    {
        $qb = $this->createQueryBuilder('u');

        $qb
            ->select('COUNT(u)')
            ->where('u.enabled = :enabled')
            ->setParameter('enabled', true)
        ;

        if ($from) {
            $qb
                ->andWhere('DATE(u.createdAt) >= :from')
                ->setParameter('from', $from->format('Y-m-d'));
        }

        return $qb->getQuery()->getSingleScalarResult();
    }

    public function getTotalWithTransactionActive(): int
    {
        $qb = $this->createQueryBuilder('u');
        $sqb = $this->_em->createQueryBuilder()
            ->select('t.id')
            ->from(Transaction::class, 't')
            ->where('t.user = u')
            ->andWhere('t.status = :statusPaid')
            ->andWhere('t.isExpired = :notExpired')
            ->andWhere('t.haveSessionsAvailable = :haveSessionsAvailable')
        ;

        $qb
            ->select('COUNT(u)')
            ->where('u.enabled = :enabled')
            ->andWhere($qb->expr()->exists($sqb->getDQL()))
            ->setParameter('enabled', true)
            ->setParameter('statusPaid', Transaction::STATUS_PAID)
            ->setParameter('notExpired', false)
            ->setParameter('haveSessionsAvailable', true)
        ;

        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return User[]
     */
    public function findEnabledWithBirthdayToday(): array
    {
        $today = new \DateTimeImmutable('today');
        $month = (int) $today->format('n');
        $day = (int) $today->format('j');

        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT u.id
             FROM user u
             WHERE u.enabled = 1
               AND u.birthday IS NOT NULL
               AND MONTH(u.birthday) = :month
               AND DAY(u.birthday) = :day',
            [
                'month' => $month,
                'day' => $day,
            ]
        );

        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));

        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('u')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('u.name', 'ASC')
            ->addOrderBy('u.lastname', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Usuarios activos con cumpleaños en la ventana indicada.
     *
     * Ventanas soportadas:
     *   - 'this_week'  : lunes a domingo de la semana actual (ISO)
     *   - 'this_month' : mes actual (1-31)
     *   - 'next_month' : mes siguiente
     *
     * La comparación ignora el año (usa MONTH/DAY en SQL), soportando
     * cruce de año para 'this_week' (e.g., semana del 29/12 al 04/01).
     *
     * @return array<int, array{id:int, name:?string, lastname:?string, email:string, birthday:\DateTimeInterface|null}>
     */
    public function getUsersWithBirthdayInWindow(string $window): array
    {
        $today = new \DateTimeImmutable('today');

        switch ($window) {
            case 'this_week':
                // Lunes al domingo de la semana ISO actual
                $monday = $today->modify('monday this week');
                $sunday = $today->modify('sunday this week');
                break;
            case 'this_month':
                $monday = $today->modify('first day of this month');
                $sunday = $today->modify('last day of this month');
                break;
            case 'next_month':
                $monday = $today->modify('first day of next month');
                $sunday = $today->modify('last day of next month');
                break;
            default:
                return [];
        }

        $fromMonth = (int) $monday->format('n');
        $fromDay   = (int) $monday->format('j');
        $toMonth   = (int) $sunday->format('n');
        $toDay     = (int) $sunday->format('j');

        $conn = $this->getEntityManager()->getConnection();

        // Cruce de año: e.g., 28/12 → 04/01 — se invierte la condición
        if ($fromMonth > $toMonth) {
            $sql = '
                SELECT u.id, u.name, u.lastname, u.email, u.birthday
                FROM user u
                WHERE u.enabled = 1
                  AND u.birthday IS NOT NULL
                  AND (
                    (MONTH(u.birthday) > :fromMonth)
                    OR (MONTH(u.birthday) = :fromMonth AND DAY(u.birthday) >= :fromDay)
                    OR (MONTH(u.birthday) < :toMonth)
                    OR (MONTH(u.birthday) = :toMonth AND DAY(u.birthday) <= :toDay)
                  )
                ORDER BY MONTH(u.birthday) ASC, DAY(u.birthday) ASC
            ';
        } else {
            $sql = '
                SELECT u.id, u.name, u.lastname, u.email, u.birthday
                FROM user u
                WHERE u.enabled = 1
                  AND u.birthday IS NOT NULL
                  AND (
                    (MONTH(u.birthday) > :fromMonth OR (MONTH(u.birthday) = :fromMonth AND DAY(u.birthday) >= :fromDay))
                    AND
                    (MONTH(u.birthday) < :toMonth OR (MONTH(u.birthday) = :toMonth AND DAY(u.birthday) <= :toDay))
                  )
                ORDER BY MONTH(u.birthday) ASC, DAY(u.birthday) ASC
            ';
        }

        $rows = $conn->fetchAllAssociative($sql, [
            'fromMonth' => $fromMonth,
            'fromDay'   => $fromDay,
            'toMonth'   => $toMonth,
            'toDay'     => $toDay,
        ]);

        // Normalizar birthday a string dd/mm para el template
        return array_map(static function (array $row): array {
            $row['birthdayFormatted'] = $row['birthday']
                ? (new \DateTimeImmutable($row['birthday']))->format('d/m')
                : null;

            return $row;
        }, $rows);
    }

    /**
     * Recalcula y persiste históricos de aniversario por usuario activo.
     */
    public function recalculateAnniversarySnapshotForEnabledUsers(\DateTimeInterface $snapshotAt): int
    {
        $connection = $this->getEntityManager()->getConnection();

        $rows = $connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    u.id AS userId,
                    u.created_at AS userCreatedAt,
                    tx.first_paid_at AS firstPaidAt,
                    cls.first_attended_at AS firstAttendedAt
                FROM `user` u
                LEFT JOIN (
                    SELECT t.user_id AS user_id, MIN(t.created_at) AS first_paid_at
                    FROM `transaction` t
                    WHERE t.status = :statusPaid
                    GROUP BY t.user_id
                ) tx ON tx.user_id = u.id
                LEFT JOIN (
                    SELECT r.user_id AS user_id, MIN(s.date_start) AS first_attended_at
                    FROM reservation r
                    INNER JOIN session s ON s.id = r.session_id
                    WHERE r.attended = 1
                    GROUP BY r.user_id
                ) cls ON cls.user_id = u.id
                WHERE u.enabled = 1
            SQL,
            ['statusPaid' => Transaction::STATUS_PAID]
        );

        if ([] === $rows) {
            return 0;
        }

        $now = \DateTimeImmutable::createFromInterface($snapshotAt);
        $weekStart = $now->modify('monday this week');
        $weekEnd = $now->modify('sunday this week');
        $monthStart = $now->modify('first day of this month');
        $monthEnd = $now->modify('last day of this month');
        $updated = 0;

        $connection->beginTransaction();
        try {
            foreach ($rows as $row) {
                $userId = (int) ($row['userId'] ?? 0);
                if ($userId <= 0) {
                    continue;
                }

                $transactionYears = 0;
                $firstPaidAt = null;
                if (!empty($row['firstPaidAt'])) {
                    $firstPaidAt = new \DateTimeImmutable((string) $row['firstPaidAt']);
                    $transactionYears = max(0, $firstPaidAt->diff($now)->y);
                }

                $classYears = 0;
                $firstAttendedAt = null;
                if (!empty($row['firstAttendedAt'])) {
                    $firstAttendedAt = new \DateTimeImmutable((string) $row['firstAttendedAt']);
                    $classYears = max(0, $firstAttendedAt->diff($now)->y);
                }

                $userCreatedAt = null;
                if (!empty($row['userCreatedAt'])) {
                    $userCreatedAt = new \DateTimeImmutable((string) $row['userCreatedAt']);
                }

                $windowHistory = $this->buildAnniversaryWindowHistory(
                    $firstPaidAt,
                    $firstAttendedAt,
                    $userCreatedAt,
                    $now,
                    $weekStart,
                    $weekEnd,
                    $monthStart,
                    $monthEnd
                );

                $connection->executeStatement(
                    'UPDATE `user`
                     SET anniversary_transaction_history = :transactionHistory,
                         anniversary_class_history = :classHistory,
                         anniversary_window_history = :windowHistory
                     WHERE id = :userId',
                    [
                        'transactionHistory' => json_encode($this->buildYearHistory($transactionYears), JSON_THROW_ON_ERROR),
                        'classHistory' => json_encode($this->buildYearHistory($classYears), JSON_THROW_ON_ERROR),
                        'windowHistory' => json_encode($windowHistory, JSON_THROW_ON_ERROR),
                        'userId' => $userId,
                    ]
                );
                ++$updated;
            }

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $updated;
    }

    /**
     * @return array<int, array{id:int, name:?string, lastname:?string, email:string, anniversaryTransactionYears:int, anniversaryClassYears:int, thisWeekEvents:array<int,array{type:string,year:int}>, thisMonthEvents:array<int,array{type:string,year:int}>}>
     */
    public function getUsersWithAnniversarySnapshot(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        $sql = '
            SELECT
                u.id,
                u.name,
                u.lastname,
                u.email,
                u.anniversary_transaction_history AS anniversaryTransactionHistory,
                                u.anniversary_class_history AS anniversaryClassHistory,
                                u.anniversary_window_history AS anniversaryWindowHistory
            FROM user u
            WHERE u.enabled = 1
                            AND JSON_LENGTH(u.anniversary_window_history) > 0
            ORDER BY
                GREATEST(
                    CASE
                        WHEN JSON_LENGTH(u.anniversary_transaction_history) > 0 THEN CAST(
                            JSON_UNQUOTE(JSON_EXTRACT(u.anniversary_transaction_history, CONCAT("$[", JSON_LENGTH(u.anniversary_transaction_history) - 1, "]")))
                            AS UNSIGNED
                        )
                        ELSE 0
                    END,
                    CASE
                        WHEN JSON_LENGTH(u.anniversary_class_history) > 0 THEN CAST(
                            JSON_UNQUOTE(JSON_EXTRACT(u.anniversary_class_history, CONCAT("$[", JSON_LENGTH(u.anniversary_class_history) - 1, "]")))
                            AS UNSIGNED
                        )
                        ELSE 0
                    END
                ) DESC,
                u.name ASC,
                u.lastname ASC
            LIMIT '.$limit;

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql);

        return array_map(static function (array $row): array {
            $txHistory = json_decode((string) ($row['anniversaryTransactionHistory'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
            $classHistory = json_decode((string) ($row['anniversaryClassHistory'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
            $windowHistory = json_decode((string) ($row['anniversaryWindowHistory'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);

            $txHistory = array_values(array_filter(array_map('intval', is_array($txHistory) ? $txHistory : []), static fn (int $year): bool => $year > 0));
            $classHistory = array_values(array_filter(array_map('intval', is_array($classHistory) ? $classHistory : []), static fn (int $year): bool => $year > 0));
            $windowHistory = is_array($windowHistory) ? $windowHistory : [];

            $thisWeekEvents = [];
            $thisMonthEvents = [];
            foreach ($windowHistory as $event) {
                if (!is_array($event)) {
                    continue;
                }

                $window = (string) ($event['window'] ?? '');
                $type = (string) ($event['type'] ?? '');
                $year = (int) ($event['year'] ?? 0);
                $date = (string) ($event['date'] ?? '');

                if (!in_array($type, ['transaction', 'class', 'joined'], true) || $year <= 0) {
                    continue;
                }

                $normalized = ['type' => $type, 'year' => $year, 'date' => $date];
                if ('this_week' === $window) {
                    $thisWeekEvents[] = $normalized;
                }

                if ('this_month' === $window) {
                    $thisMonthEvents[] = $normalized;
                }
            }

            $row['anniversaryTransactionHistory'] = $txHistory;
            $row['anniversaryClassHistory'] = $classHistory;
            $row['anniversaryWindowHistory'] = $windowHistory;
            $row['anniversaryTransactionYears'] = [] === $txHistory ? 0 : max($txHistory);
            $row['anniversaryClassYears'] = [] === $classHistory ? 0 : max($classHistory);
            $row['thisWeekEvents'] = $thisWeekEvents;
            $row['thisMonthEvents'] = $thisMonthEvents;

            return $row;
        }, $rows);
    }

    /**
     * @return int[]
     */
    private function buildYearHistory(int $years): array
    {
        if ($years <= 0) {
            return [];
        }

        return range(1, $years);
    }

    /**
     * @return array<int, array{window:string, type:string, year:int, date:string}>
     */
    private function buildAnniversaryWindowHistory(
        ?\DateTimeImmutable $firstPaidAt,
        ?\DateTimeImmutable $firstAttendedAt,
        ?\DateTimeImmutable $userCreatedAt,
        \DateTimeImmutable $now,
        \DateTimeImmutable $weekStart,
        \DateTimeImmutable $weekEnd,
        \DateTimeImmutable $monthStart,
        \DateTimeImmutable $monthEnd,
    ): array {
        $history = [];
        $currentYear = (int) $now->format('Y');

        if ($firstPaidAt instanceof \DateTimeImmutable) {
            $txYear = $currentYear - (int) $firstPaidAt->format('Y');
            if ($txYear >= 1) {
                $txDate = $firstPaidAt->format('d/m');
                if ($this->isMonthDayInWindow($firstPaidAt, $weekStart, $weekEnd)) {
                    $history[] = ['window' => 'this_week', 'type' => 'transaction', 'year' => $txYear, 'date' => $txDate];
                }
                if ($this->isMonthDayInWindow($firstPaidAt, $monthStart, $monthEnd)) {
                    $history[] = ['window' => 'this_month', 'type' => 'transaction', 'year' => $txYear, 'date' => $txDate];
                }
            }
        }

        if ($firstAttendedAt instanceof \DateTimeImmutable) {
            $clsYear = $currentYear - (int) $firstAttendedAt->format('Y');
            if ($clsYear >= 1) {
                $clsDate = $firstAttendedAt->format('d/m');
                if ($this->isMonthDayInWindow($firstAttendedAt, $weekStart, $weekEnd)) {
                    $history[] = ['window' => 'this_week', 'type' => 'class', 'year' => $clsYear, 'date' => $clsDate];
                }
                if ($this->isMonthDayInWindow($firstAttendedAt, $monthStart, $monthEnd)) {
                    $history[] = ['window' => 'this_month', 'type' => 'class', 'year' => $clsYear, 'date' => $clsDate];
                }
            }
        }

        if ($userCreatedAt instanceof \DateTimeImmutable) {
            $joinedYear = $currentYear - (int) $userCreatedAt->format('Y');
            if ($joinedYear >= 1) {
                $joinedDate = $userCreatedAt->format('d/m');
                if ($this->isMonthDayInWindow($userCreatedAt, $weekStart, $weekEnd)) {
                    $history[] = ['window' => 'this_week', 'type' => 'joined', 'year' => $joinedYear, 'date' => $joinedDate];
                }
                if ($this->isMonthDayInWindow($userCreatedAt, $monthStart, $monthEnd)) {
                    $history[] = ['window' => 'this_month', 'type' => 'joined', 'year' => $joinedYear, 'date' => $joinedDate];
                }
            }
        }

        return $history;
    }

    private function isMonthDayInWindow(\DateTimeInterface $sourceDate, \DateTimeInterface $from, \DateTimeInterface $to): bool
    {
        $source = (int) $sourceDate->format('md');
        $fromMd = (int) $from->format('md');
        $toMd = (int) $to->format('md');

        if ($fromMd <= $toMd) {
            return $source >= $fromMd && $source <= $toMd;
        }

        return $source >= $fromMd || $source <= $toMd;
    }

    /**
     * @return array<int, array{id:int, name:?string, lastname:?string, email:string}>
     */
    public function searchEnabledForSelect(string $term, int $limit = 20): array
    {
        $term = preg_replace('/\s+/', ' ', trim($term)) ?? '';

        if ('' === $term) {
            return [];
        }

        $limit = max(1, min(50, $limit));

        $qb = $this->createQueryBuilder('u');

        $qb
            ->select('u.id', 'u.name', 'u.lastname', 'u.email')
            ->where('u.enabled = :enabled')
            ->setParameter('enabled', true)
            ->setMaxResults($limit)
            ->orderBy('u.name', 'ASC')
            ->addOrderBy('u.lastname', 'ASC')
        ;

        $searchExpr = $qb->expr()->orX(
            $qb->expr()->like('u.name', ':term'),
            $qb->expr()->like('u.lastname', ':term'),
            $qb->expr()->like("CONCAT(COALESCE(u.name, ''), ' ', COALESCE(u.lastname, ''))", ':term'),
            $qb->expr()->like("CONCAT(COALESCE(u.lastname, ''), ' ', COALESCE(u.name, ''))", ':term')
        );

        $qb->setParameter('term', '%'.$term.'%');

        if (ctype_digit($term)) {
            $searchExpr->add($qb->expr()->eq('u.id', ':termId'));
            $qb->setParameter('termId', (int) $term);
        }

        $qb->andWhere($searchExpr);

        $termTokens = array_values(array_unique(array_filter(explode(' ', $term), static function (string $token): bool {
            return '' !== $token;
        })));

        if (count($termTokens) > 1) {
            foreach ($termTokens as $index => $token) {
                $tokenParam = sprintf('termToken%s', $index);
                $tokenExpr = $qb->expr()->orX(
                    $qb->expr()->like('u.name', ':'.$tokenParam),
                    $qb->expr()->like('u.lastname', ':'.$tokenParam)
                );

                $qb
                    ->andWhere($tokenExpr)
                    ->setParameter($tokenParam, '%'.$token.'%')
                ;
            }
        }

        return $qb->getQuery()->getArrayResult();
    }

    private function querySearch(?string $search = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('u');
        $qb->orderBy('u.id', 'DESC');

        if ($search) {
            $qb
                ->andWhere($qb->expr()->orX(
                    $qb->expr()->eq('u.id', (int) $search),
                    $qb->expr()->like('u.name', $qb->expr()->literal('%'.$search.'%')),
                    $qb->expr()->like('u.email', $qb->expr()->literal('%'.$search.'%'))
                ))
            ;
        }

        return $qb;
    }

    private function applyNameLastnameFilter(QueryBuilder $qb, string $rawValue, string $paramPrefix): void
    {
        $normalizedValue = preg_replace('/\s+/', ' ', trim($rawValue)) ?? '';

        if ('' === $normalizedValue) {
            return;
        }

        $tokens = array_values(array_filter(explode(' ', $normalizedValue), static function (string $token): bool {
            return '' !== $token;
        }));

        $isMultiWord = count($tokens) > 1;
        $fullPattern = $isMultiWord
            ? '% '.$normalizedValue.' %'
            : '%'.$normalizedValue.'%';

        $fullMatchExpr = $qb->expr()->orX(
            $qb->expr()->like("CONCAT(' ', COALESCE(u.name, ''), ' ')", ':'.$paramPrefix.'_full'),
            $qb->expr()->like("CONCAT(' ', COALESCE(u.lastname, ''), ' ')", ':'.$paramPrefix.'_full'),
            $qb->expr()->like("CONCAT(' ', COALESCE(u.name, ''), ' ', COALESCE(u.lastname, ''), ' ')", ':'.$paramPrefix.'_full'),
            $qb->expr()->like("CONCAT(' ', COALESCE(u.lastname, ''), ' ', COALESCE(u.name, ''), ' ')", ':'.$paramPrefix.'_full')
        );

        $qb->setParameter($paramPrefix.'_full', $fullPattern);

        if (!$isMultiWord) {
            $qb->andWhere($fullMatchExpr);

            return;
        }

        $tokenAndExpr = $qb->expr()->andX();

        foreach ($tokens as $index => $token) {
            $tokenParam = sprintf('%s_token_%d', $paramPrefix, $index);

            $tokenAndExpr->add(
                $qb->expr()->orX(
                    $qb->expr()->like("CONCAT(' ', COALESCE(u.name, ''), ' ', COALESCE(u.lastname, ''), ' ')", ':'.$tokenParam),
                    $qb->expr()->like("CONCAT(' ', COALESCE(u.lastname, ''), ' ', COALESCE(u.name, ''), ' ')", ':'.$tokenParam)
                )
            );

            $qb->setParameter($tokenParam, '% '.$token.' %');
        }

        $qb->andWhere(
            $qb->expr()->orX(
                $tokenAndExpr,
                $fullMatchExpr
            )
        );
    }
}
