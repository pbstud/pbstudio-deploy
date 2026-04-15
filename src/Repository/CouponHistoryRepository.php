<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CouponHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CouponHistory>
 *
 * @method CouponHistory|null find($id, $lockMode = null, $lockVersion = null)
 * @method CouponHistory|null findOneBy(array $criteria, array $orderBy = null)
 * @method CouponHistory[]    findAll()
 * @method CouponHistory[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CouponHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CouponHistory::class);
    }
}
