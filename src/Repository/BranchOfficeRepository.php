<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BranchOffice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BranchOffice>
 *
 * @method BranchOffice|null find($id, $lockMode = null, $lockVersion = null)
 * @method BranchOffice|null findOneBy(array $criteria, array $orderBy = null)
 * @method BranchOffice[]    findAll()
 * @method BranchOffice[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BranchOfficeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BranchOffice::class);
    }

    public function getQueryAll(): Query
    {
        $qb = $this->createQueryBuilder('bo');
        $qb->orderBy('bo.id', 'DESC');

        return $qb->getQuery();
    }

    /**
     * @return BranchOffice[]
     */
    public function getAll(): array
    {
        $qb = $this->createQueryBuilder('bo');

        $qb->orderBy('bo.name');

        return $qb->getQuery()->getResult();
    }

    /**
     * @return QueryBuilder
     */
    public function queryPublic(): QueryBuilder
    {
        $qb = $this->createQueryBuilder('bo');

        $qb
            ->where('bo.public = :public')
            ->andWhere('bo.isActive = :is_active')
            ->orderBy('bo.name')

            ->setParameter('public', true)
            ->setParameter('is_active', true)
        ;

        return $qb;
    }

    /**
     * @return BranchOffice[]
     */
    public function getPublic(): array
    {
        return $this->queryPublic()->getQuery()->getResult();
    }

    public function getOneBySlug(string $slug): ?BranchOffice
    {
        $qb = $this->createQueryBuilder('bo');

        $qb
            ->where('bo.slug = :slug')
            ->andWhere('bo.public = :public')
            ->andWhere('bo.isActive = :is_active')
            ->setParameter('slug', $slug)
            ->setParameter('public', true)
            ->setParameter('is_active', true)
        ;

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function getFirstPublic(): BranchOffice
    {
        $qb = $this->queryPublic();
        $qb->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }
}
