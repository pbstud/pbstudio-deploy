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

    public function paginate(bool $sorted = false): QueryBuilder
    {
        $qb = $this->createQueryBuilder('d');

        if (!$sorted) {
            $qb
                ->addSelect('CASE WHEN d.isActive = true THEN 0 ELSE 1 END AS HIDDEN sort_group')
                ->orderBy('sort_group', 'ASC')
                ->addOrderBy('d.id', 'DESC');
        } else {
            $qb->orderBy('d.id', 'DESC');
        }

        return $qb;
    }

    /**
     * @return Discipline[]
     */
    public function getAllActives(): array
    {
        $qb = $this->createQueryBuilder('d');

        $qb
            ->where('d.isActive = :is_active')
            ->orderBy('d.id', 'ASC')
            ->setParameter('is_active', true)
        ;


        return $qb->getQuery()->getResult();
    }
}
