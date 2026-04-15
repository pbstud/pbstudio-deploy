<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Coupon;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Coupon>
 *
 * @method Coupon|null find($id, $lockMode = null, $lockVersion = null)
 * @method Coupon|null findOneBy(array $criteria, array $orderBy = null)
 * @method Coupon[]    findAll()
 * @method Coupon[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CouponRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Coupon::class);
    }

    public function paginate(): QueryBuilder
    {
        return $this->createQueryBuilder('c');
    }

    /**
     * Devuelve un cupón por código, sin filtrar por fecha o límite de usos.
     */
    public function getByCode(string $code): ?Coupon
    {
        $qb = $this->createQueryBuilder('c');

        $qb
            ->addSelect('p')
            ->leftJoin('c.packages', 'p')
            ->where('c.code = :code')
            ->setParameter('code', $code);

        try {
            $result = $qb->getQuery()->getOneOrNullResult();
        } catch (\Exception) {
            $result = null;
        }

        return $result;
    }

    public function getActiveByCode(string $code): ?Coupon
    {
        $currentDate = new \DateTime('today');
        $qb = $this->createQueryBuilder('c');

        $qb
            ->addSelect('p')
            ->leftJoin('c.packages', 'p')
            ->where('c.code = :code')
            ->andWhere('c.usesTotal > c.used')
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('c.dateStart'),
                $qb->expr()->lte('c.dateStart', ':currentDate'),
            ))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('c.dateEnd'),
                $qb->expr()->gte('c.dateEnd', ':currentDate'),
            ))
            ->setParameter('code', $code)
            ->setParameter('currentDate', $currentDate)
        ;

        try {
            $result = $qb->getQuery()->getOneOrNullResult();
        } catch (\Exception) {
            $result = null;
        }

        return $result;
    }
}
