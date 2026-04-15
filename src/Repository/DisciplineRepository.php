<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Discipline;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Discipline>
 *
 * @method Discipline|null find($id, $lockMode = null, $lockVersion = null)
 * @method Discipline|null findOneBy(array $criteria, array $orderBy = null)
 * @method Discipline[]    findAll()
 * @method Discipline[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DisciplineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Discipline::class);
    }

    public function paginate(): QueryBuilder
    {
        return $this->createQueryBuilder('d');
    }

    /**
     * @return Discipline[]
     */
    public function getAllActives(): array
    {
        $qb = $this->createQueryBuilder('d');

        $qb
            ->where('d.isActive = :is_active')
            ->orderBy('d.name')
            ->setParameter('is_active', true)
        ;


        return $qb->getQuery()->getResult();
    }
}
