<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Coupon;
use App\Entity\Package;
use App\Entity\Transaction;
use App\Entity\User;
use App\Util\PackageSessionType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transaction>
 *
 * @method Transaction|null find($id, $lockMode = null, $lockVersion = null)
 * @method Transaction|null findOneBy(array $criteria, array $orderBy = null)
 * @method Transaction[]    findAll()
 * @method Transaction[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    public function findForBackendList(array $filters = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t');

        $qb
            ->addSelect('u', 'bo', 'c')
            ->join('t.user', 'u')
            ->join('t.branchOffice', 'bo')
            ->leftJoin('t.coupon', 'c')
            ->orderBy('t.id', 'DESC')
        ;

        $this->applyFilters($qb, $filters);

        return $qb;
    }

    public function getSumFilterList(array $filters = []): float
    {
        $qb = $this->createQueryBuilder('t');

        $qb
            ->select('COALESCE(SUM(t.total), 0) AS total')
        ;

        $allowUserSearch = $this->needsUserJoinForSearch($filters);

        if ($allowUserSearch) {
            $qb->join('t.user', 'u');
        }

        $this->applyFilters($qb, $filters, $allowUserSearch);

        return (float) $qb->getQuery()->getSingleScalarResult();
    }

    public function getAllByUser(User $user): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t');

        $qb
            ->addSelect('p')
            ->leftJoin('t.package', 'p')
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC')
        ;

        return $qb;
    }

    public function getAllCompletedByUser(User $user)
    {
        $qb = $this->getAllByUser($user)
            ->andWhere('t.status = :status')
            ->setParameter('status', Transaction::STATUS_PAID)
        ;

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Package[]
     */
    public function getLastCompleted(int $limit = 3): array
    {
        $qb = $this
            ->createQueryBuilder('t')
            ->where('t.status = :status')
            ->setParameter('status', Transaction::STATUS_PAID)
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)
        ;

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Transaction[]
     */
    public function getLastCompletedByUser(User $user, int $limit = 3): array
    {
        $qb = $this->getAllByUser($user)
            ->andWhere('t.status = :status')
            ->setParameter('status', Transaction::STATUS_PAID)
            ->setMaxResults($limit)
        ;

        return $qb->getQuery()->getResult();
    }

    public function getTotalAmount(?\DateTime $from = null, bool $forceDiscount = false, bool $withDiscount = false): float
    {
        $qb = $this->createQueryBuilder('t');

        $qb
            ->select('SUM(t.total)')
            ->where('t.status = :status')
            ->andWhere('t.chargeMethod != :notChargeMethod')
            ->setParameter('status', Transaction::STATUS_PAID)
            ->setParameter('notChargeMethod', Transaction::CHARGE_METHOD_FREE)
        ;

        if ($from) {
            $fromStart = \DateTimeImmutable::createFromInterface($from)->setTime(0, 0, 0);

            $qb
                ->andWhere('t.createdAt >= :from')
                ->setParameter('from', $fromStart);
        }

        if ($forceDiscount && $withDiscount) {
            $qb
                ->andWhere($qb->expr()->orX(
                    $qb->expr()->gt('t.discount', 0),
                    $qb->expr()->isNotNull('t.packageSpecialPrice'),
                    $qb->expr()->isNotNull('t.couponDiscount')
                ))
            ;
        }

        if ($forceDiscount && !$withDiscount) {
            $qb
                ->andWhere($qb->expr()->eq('t.discount', 0))
                ->andWhere($qb->expr()->isNull('t.packageSpecialPrice'))
                ->andWhere($qb->expr()->isNull('t.couponDiscount'))
            ;
        }

        return (float) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Obtiene el monto total procesado por un rango de fechas.
     *
     * @param \DateTime $startDate
     * @param \DateTime $endDate
     *
     * @return mixed
     */
    public function getTotalAmountForRageDate($startDate, $endDate)
    {
        $qb = $this
            ->createQueryBuilder('t')
            ->select('SUM(t.total)')
            ->where('t.status = :status')
            ->andWhere('t.createdAt >= :startDate')
            ->andWhere('t.createdAt <= :endDate')
            ->andWhere('t.chargeMethod != :notChargeMethod')
            ->setParameters([
                'status' => Transaction::STATUS_PAID,
                'startDate' => $startDate->format('Y-m-d 00:00:00'),
                'endDate' => $endDate->format('Y-m-d 23:59:59'),
                'notChargeMethod' => 'payment.free',
            ])
        ;

        return $qb->getQuery()->getSingleScalarResult();
    }

    public function findFirstTransactionIndividualAvailableByUser(User $user)
    {
        $qb = $this
            ->findTransactionAvailableByUser($user)
            ->andWhere('t.packageType = :packageType')
            ->andWhere('t.haveSessionsAvailable = :haveSessionsAvailable')
            ->setParameter('packageType', PackageSessionType::TYPE_INDIVIDUAL)
            ->setParameter('haveSessionsAvailable', true)
            ->setMaxResults(1)
        ;

        return $qb->getQuery()->getSingleResult();
    }

    /**
     * Obtiene la primera transacción con clases disponibles del usuario, siempre y cuando la fecha de la reservación
     * sea mayor o igual a la fecha de expiración de la tarjeta.
     *
     * @throws NoResultException
     */
    public function findFirstTransactionIndividualAvailableByUserAndExpirationAt(
        User $user,
        \DateTimeInterface $sessionDateStart,
        bool $excludeUnlimited = false,
        ?bool $onlyUnlimited = null
    ) {
        $qb = $this
            ->findTransactionAvailableByUser($user)
            ->andWhere('t.packageType = :packageType')
            ->andWhere('t.haveSessionsAvailable = :haveSessionsAvailable')
            ->andWhere('t.expirationAt >= :expirationAt')
            ->setParameter('packageType', PackageSessionType::TYPE_INDIVIDUAL)
            ->setParameter('haveSessionsAvailable', true)
            ->setParameter('expirationAt', $sessionDateStart)
            ->setMaxResults(1)
        ;

        if (null !== $onlyUnlimited) {
            $qb
                ->andWhere('t.packageIsUnlimited = :packageUnlimited')
                ->setParameter('packageUnlimited', $onlyUnlimited)
            ;
        } elseif ($excludeUnlimited) {
            $qb
                ->andWhere('t.packageIsUnlimited = :packageUnlimited')
                ->setParameter('packageUnlimited', false)
            ;
        }

        return $qb->getQuery()->getSingleResult();
    }

    public function findFirstTransactionGroupAvailableByUser(User $user)
    {
        $qb = $this
            ->findTransactionAvailableByUser($user)
            ->andWhere('t.packageType = :packageType')
            ->andWhere('t.haveSessionsAvailable = :haveSessionsAvailable')
            ->setParameter('packageType', PackageSessionType::TYPE_GROUP)
            ->setParameter('haveSessionsAvailable', true)
            ->setMaxResults(1)
        ;

        return $qb->getQuery()->getSingleResult();
    }

    /**
     * Obtiene la primera transacción con clases disponibles del usuario, siempre y cuando la fecha
     * de expiración sea mayor o igual a la fecha de reservación.
     */
    public function findFirstTransactionGroupAvailableByUserAndExpirationAt(
        User $user,
        \DateTimeInterface $sessionDateStart,
        bool $excludeUnlimited = false,
        ?bool $onlyUnlimited = null
    ) {
        $qb = $this
            ->findTransactionAvailableByUser($user)
            ->andWhere('t.packageType = :packageType')
            ->andWhere('t.haveSessionsAvailable = :haveSessionsAvailable')
            ->andWhere('t.expirationAt >= :expirationAt')
            ->setParameter('packageType', PackageSessionType::TYPE_GROUP)
            ->setParameter('haveSessionsAvailable', true)
            ->setParameter('expirationAt', $sessionDateStart)
            ->setMaxResults(1)
        ;

        if (null !== $onlyUnlimited) {
            $qb
                ->andWhere('t.packageIsUnlimited = :packageUnlimited')
                ->setParameter('packageUnlimited', $onlyUnlimited)
            ;
        } elseif ($excludeUnlimited) {
            $qb
                ->andWhere('t.packageIsUnlimited = :packageUnlimited')
                ->setParameter('packageUnlimited', false)
            ;
        }

        return $qb->getQuery()->getSingleResult();
    }

    /**
     * Devuelve todas las transacciones elegibles para una sesion, ordenadas por vigencia ascendente.
     * Incluye ilimitados si $includeUnlimited = true; incluye normales si $includeRegular = true.
     *
     * @return Transaction[]
     */
    public function findAllTransactionsAvailableByUserAndExpirationAt(
        User $user,
        \DateTimeInterface $sessionDateStart,
        string $packageType,
        bool $includeUnlimited = true,
        bool $includeRegular = true,
    ): array {
        $qb = $this
            ->findTransactionAvailableByUser($user)
            ->andWhere('t.packageType = :packageType')
            ->andWhere('t.haveSessionsAvailable = :haveSessionsAvailable')
            ->andWhere('t.expirationAt >= :expirationAt')
            ->setParameter('packageType', $packageType)
            ->setParameter('haveSessionsAvailable', true)
            ->setParameter('expirationAt', $sessionDateStart)
        ;

        if ($includeUnlimited && !$includeRegular) {
            $qb->andWhere('t.packageIsUnlimited = true');
        } elseif (!$includeUnlimited && $includeRegular) {
            $qb->andWhere('t.packageIsUnlimited = false');
        }
        // si ambos son true no filtra; si ambos false devuelve vacío (no debería pasar)

        return $qb->getQuery()->getResult();
    }

    /**
     * Busca una transaccion especifica por ID validando que pertenece al usuario y esta activa.
     */
    public function findTransactionByIdForUser(int $id, User $user): ?Transaction
    {
        return $this->findOneBy([
            'id' => $id,
            'user' => $user,
            'status' => Transaction::STATUS_PAID,
            'isExpired' => false,
            'haveSessionsAvailable' => true,
        ]);
    }

    /**
     * @return Transaction[]
     */
    public function findAllTransactionAvailableByUser(User $user): array
    {
        $qb = $this->findTransactionAvailableByUser($user);
        $qb
            ->addSelect('p')
            ->join('t.package', 'p')
        ;

        return $qb->getQuery()->getResult();
    }

    public function getCountSessionsAvailableByUser(User $user): int
    {
        $qb = $this
            ->findTransactionAvailableByUser($user)
            ->select('SUM(t.packageTotalClasses) total')
        ;

        try {
            $total = (int) $qb->getQuery()->getSingleScalarResult();
        } catch (\Exception $e) {
            $total = 0;
        }

        return $total;
    }

    public function hasTransactionsByUser(User $user): bool
    {
        $qb = $this->createQueryBuilder('t');

        $qb
            ->select($qb->expr()->count('t.id'))
            ->where('t.user = :user')
            ->andWhere('t.status = :status')

            ->setParameter('user', $user)
            ->setParameter('status', Transaction::STATUS_PAID)
        ;

        try {
            $total = (int) $qb->getQuery()->getSingleScalarResult();
        } catch (\Exception $e) {
            $total = 0;
        }

        return $total > 0;
    }

    /**
     * @return Transaction[]
     */
    public function getExpired(): array
    {
        $currentDate = new \DateTime();

        $qb = $this->createQueryBuilder('t');

        $qb
            ->where('t.status = :status')
            ->andWhere('t.isExpired = false')
            ->andWhere('t.expirationAt < :current_date')
            ->orderBy('t.id')
            ->setParameter('status', Transaction::STATUS_PAID)
            ->setParameter('current_date', $currentDate->format('Y-m-d'))
        ;

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Transaction[]
     */
    public function getExpiringInWindow(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $qb = $this->createQueryBuilder('t');

        $qb
            ->addSelect('u', 'p')
            ->join('t.user', 'u')
            ->leftJoin('t.package', 'p')
            ->where('t.status = :status')
            ->andWhere('t.isExpired = :isExpired')
            ->andWhere('u.enabled = :userEnabled')
            ->andWhere('t.expirationAt IS NOT NULL')
            ->andWhere('t.expirationAt BETWEEN :start AND :end')
            ->setParameter('status', Transaction::STATUS_PAID)
            ->setParameter('isExpired', false)
            ->setParameter('userEnabled', true)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('t.expirationAt', 'ASC')
            ->addOrderBy('t.id', 'ASC')
        ;

        return $qb->getQuery()->getResult();
    }

    public function getByCoupon(Coupon $coupon)
    {
        $qb = $this->createQueryBuilder('t');

        $qb
            ->addSelect('u', 'bo')
            ->join('t.user', 'u')
            ->join('t.branchOffice', 'bo')
            ->where('t.coupon = :coupon')
            ->orderBy('t.id', 'DESC')
            ->setParameter('coupon', $coupon)
        ;

        return $qb->getQuery()->getResult();
    }

    public function paginateByUser(User $user): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t');

        $qb
            ->where('t.user = :user')
            ->orderBy('t.id', 'DESC')
            ->setParameter('user', $user)
        ;

        return $qb;
    }

    public function getGroupedByPackage(\DateTime $from): array
    {
        $fromStart = \DateTimeImmutable::createFromInterface($from)->setTime(0, 0, 0);

        $qb = $this->createQueryBuilder('t');

        $qb
            ->select('COUNT(t.id) as transactions')
            ->addSelect('IFELSE(t.packageTotalClasses > 0, t.packageTotalClasses, \'ilimitado\') as package')
            ->addSelect('b.id as studioId', 'b.name as studio')
            ->join('t.branchOffice', 'b')
            ->join('t.package', 'p')
            ->where('t.createdAt >= :from')
            ->andWhere('t.status = :status')
            ->andWhere('b.public = :public')
            ->groupBy('b.id')
            ->addGroupBy('b.name')
            ->addGroupBy('p.id')
            ->addGroupBy('t.packageTotalClasses')
            ->orderBy('transactions', 'DESC')
            ->setParameter('status', Transaction::STATUS_PAID)
            ->setParameter('from', $fromStart)
            ->setParameter('public', true)
        ;

        return $qb->getQuery()->getScalarResult();
    }

    public function getTotalByChargeMethod(string $chargeMethod, ?\DateTime $from = null): float
    {
        $qb = $this->createQueryBuilder('t');

        $qb
            ->select('SUM(t.total)')
            ->where('t.status = :status')
            ->andWhere('t.chargeMethod = :chargeMethod')
            ->setParameter('status', Transaction::STATUS_PAID)
            ->setParameter('chargeMethod', $chargeMethod)
        ;

        if ($from) {
            $fromStart = \DateTimeImmutable::createFromInterface($from)->setTime(0, 0, 0);

            $qb
                ->andWhere('t.createdAt >= :from')
                ->setParameter('from', $fromStart);
        }

        return (float) $qb->getQuery()->getSingleScalarResult();
    }

    private function findTransactionAvailableByUser(User $user): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t');

        $qb
            ->where('t.user = :user')
            ->andWhere('t.status = :status')
            ->andWhere('t.isExpired = :isExpired')
            ->setParameters([
                'status' => Transaction::STATUS_PAID,
                'isExpired' => false,
                'user' => $user,
            ])
            ->orderBy('t.expirationAt', 'ASC')
            ->addOrderBy('t.createdAt', 'ASC')
            ->addOrderBy('t.id', 'ASC')
        ;

        return $qb;
    }

    private function applyFilters(QueryBuilder $qb, array $filters, bool $allowUserSearch = true): void
    {
        $search = trim((string) ($filters['filter_search'] ?? ''));

        if ('' !== $search) {
            $searchExpression = $qb->expr()->orX();

            if (ctype_digit($search)) {
                $searchExpression->add($qb->expr()->eq('t.id', ':searchId'));
                $qb->setParameter('searchId', (int) $search);
            }

            if ($allowUserSearch) {
                $searchExpression->add($qb->expr()->like('u.name', ':searchTerm'));
                $searchExpression->add($qb->expr()->like('u.email', ':searchTerm'));
                $qb->setParameter('searchTerm', '%'.$search.'%');
            }

            if (\count($searchExpression->getParts()) > 0) {
                $qb->andWhere($searchExpression);
            }
        }

        if (!empty($filters['filter_date_start']) && !empty($filters['filter_date_end'])) {
            $dateStart = \DateTime::createFromFormat('d/m/Y', $filters['filter_date_start']);
            $dateEnd = \DateTime::createFromFormat('d/m/Y', $filters['filter_date_end']);
            $qb
                ->andWhere('t.createdAt BETWEEN :date_start AND :date_end')
                ->setParameter('date_start', $dateStart->format('Y-m-d 00:00:00'))
                ->setParameter('date_end', $dateEnd->format('Y-m-d 23:59:59'))
            ;
        } elseif (!empty($filters['filter_date_start'])) {
            $dateStart = \DateTime::createFromFormat('d/m/Y', $filters['filter_date_start']);
            $qb
                ->andWhere('t.createdAt >= :date_start')
                ->setParameter('date_start', $dateStart->format('Y-m-d 00:00:00'))
            ;
        } elseif (!empty($filters['filter_date_end'])) {
            $dateEnd = \DateTime::createFromFormat('d/m/Y', $filters['filter_date_end']);
            $qb
                ->andWhere('t.createdAt <= :date_end')
                ->setParameter('date_end', $dateEnd->format('Y-m-d 23:59:59'))
            ;
        }

        if (!empty($filters['filter_status'])) {
            $qb
                ->andWhere('t.status = :status')
                ->setParameter('status', $filters['filter_status'])
            ;
        }

        if (!empty($filters['filter_branchOffice'])) {
            $qb
                ->andWhere('t.branchOffice = :branchOffice')
                ->setParameter('branchOffice', $filters['filter_branchOffice'])
            ;
        }

        if (!empty($filters['filter_package'])) {
            $qb
                ->andWhere('t.package = :package')
                ->setParameter('package', $filters['filter_package'])
            ;
        }

        if (!empty($filters['filter_charge_method'])) {
            $qb
                ->andWhere('t.chargeMethod = :chargeMethod')
                ->setParameter('chargeMethod', $filters['filter_charge_method'])
            ;
        } else {
            $qb
                ->andWhere('t.chargeMethod != :notChargeMethod')
                ->setParameter('notChargeMethod', Transaction::CHARGE_METHOD_FREE)
            ;
        }

        if (!empty($filters['filter_discount'])) {
            $discountType = (int) $filters['filter_discount'];

            if (Transaction::WITH_DISCOUNT === $discountType) {
                $qb
                    ->andWhere($qb->expr()->orX(
                        $qb->expr()->gt('t.discount', 0),
                        $qb->expr()->isNotNull('t.packageSpecialPrice'),
                        $qb->expr()->isNotNull('t.couponDiscount')
                    ))
                ;
            }

            if (Transaction::WITHOUT_DISCOUNT === $discountType) {
                $qb
                    ->andWhere($qb->expr()->eq('t.discount', 0))
                    ->andWhere($qb->expr()->isNull('t.packageSpecialPrice'))
                    ->andWhere($qb->expr()->isNull('t.couponDiscount'))
                ;
            }
        }
    }

    private function needsUserJoinForSearch(array $filters): bool
    {
        $search = trim((string) ($filters['filter_search'] ?? ''));

        return '' !== $search && !ctype_digit($search);
    }
}
