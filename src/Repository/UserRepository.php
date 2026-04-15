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
            ->where('u.enabled >= :enabled')
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
            ->where('u.enabled >= :enabled')
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

        $qb->select('COUNT(u)');

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
