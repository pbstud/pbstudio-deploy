<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BranchOffice;
use App\Entity\ExerciseRoom;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExerciseRoom>
 *
 * @method ExerciseRoom|null find($id, $lockMode = null, $lockVersion = null)
 * @method ExerciseRoom|null findOneBy(array $criteria, array $orderBy = null)
 * @method ExerciseRoom[]    findAll()
 * @method ExerciseRoom[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ExerciseRoomRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExerciseRoom::class);
    }

    public function getQueryAll(): Query
    {
        $qb = $this->createQueryBuilder('er');
        $qb->orderBy('er.id', 'DESC');

        return $qb->getQuery();
    }

    /**
     * @return ExerciseRoom[]
     */
    public function getAll(): array
    {
        $qb = $this->createQueryBuilder('er');

        $qb
            ->addSelect('b')
            ->join('er.branchOffice', 'b')
            ->orderBy('b.name')
            ->addOrderBy('er.name')
        ;

        return $qb->getQuery()->getResult();
    }

    public function getActiveByBranchOffice(BranchOffice $branchOffice): array
    {
        $qb = $this->createQueryBuilder('er');

        $qb
            ->where('er.branchOffice = :branchOffice')
            ->andWhere('er.isActive = :isActive')
            ->orderBy('er.name')

            ->setParameter('branchOffice', $branchOffice)
            ->setParameter('isActive', true)
        ;

        return $qb->getQuery()->getResult();
    }

    public function getById(int $id): ?ExerciseRoom
    {
        $qb = $this->createQueryBuilder('er');

        $qb
            ->addSelect('d')
            ->join('er.discipline', 'd')
            ->where('er.id = :id')

            ->setParameter('id', $id)
        ;

        try {
            $result = $qb->getQuery()->getOneOrNullResult();
        } catch (\Exception $e) {
            $result = null;
        }

        return $result;
    }

    /**
     * @return ExerciseRoom[]
     */
    public function getAllActive(): array
    {
        $qb = $this->createQueryBuilder('er');

        $qb
            ->addSelect('bo')
            ->join('er.branchOffice', 'bo')
            ->andWhere('er.isActive = :isActive')
            ->andWhere('bo.isActive = :isActive')
            ->orderBy('bo.name')
            ->addOrderBy('er.name')
            ->setParameter('isActive', true)
        ;

        return $qb->getQuery()->getResult();
    }
}
