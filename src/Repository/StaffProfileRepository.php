<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StaffProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StaffProfile>
 *
 * @method StaffProfile|null find($id, $lockMode = null, $lockVersion = null)
 * @method StaffProfile|null findOneBy(array $criteria, array $orderBy = null)
 * @method StaffProfile[]    findAll()
 * @method StaffProfile[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class StaffProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StaffProfile::class);
    }
}
