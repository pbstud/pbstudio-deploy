<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AchievementConditionCatalog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AchievementConditionCatalog>
 *
 * @method AchievementConditionCatalog|null find($id, $lockMode = null, $lockVersion = null)
 * @method AchievementConditionCatalog|null findOneBy(array $criteria, array $orderBy = null)
 * @method AchievementConditionCatalog[]    findAll()
 * @method AchievementConditionCatalog[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AchievementConditionCatalogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AchievementConditionCatalog::class);
    }
}
