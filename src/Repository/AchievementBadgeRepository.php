<?php

namespace App\Repository;

use App\Entity\AchievementBadge;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AchievementBadge>
 */
class AchievementBadgeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AchievementBadge::class);
    }

    /**
     * Retorna los badges activos agrupados por badgeGroup, en orden sortOrder.
     *
     * @return array<string, AchievementBadge[]>  ['Progresión estándar' => [...], ...]
     */
    public function findAllActiveGrouped(): array
    {
        $badges = $this->createQueryBuilder('b')
            ->where('b.isActive = true')
            ->orderBy('b.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($badges as $badge) {
            $grouped[$badge->getBadgeGroup()][] = $badge;
        }

        return $grouped;
    }

    /**
     * Array plano de todos los badges activos indexados por badgeKey.
     * Útil para lookups rápidos en el controller.
     *
     * @return array<string, AchievementBadge>
     */
    public function findAllActiveIndexed(): array
    {
        $badges = $this->createQueryBuilder('b')
            ->where('b.isActive = true')
            ->orderBy('b.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($badges as $badge) {
            $indexed[$badge->getBadgeKey()] = $badge;
        }

        return $indexed;
    }
}
