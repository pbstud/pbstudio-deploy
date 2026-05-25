<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Achievement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Achievement>
 *
 * @method Achievement|null find($id, $lockMode = null, $lockVersion = null)
 * @method Achievement|null findOneBy(array $criteria, array $orderBy = null)
 * @method Achievement[]    findAll()
 * @method Achievement[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AchievementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Achievement::class);
    }

    /**
     * Returns all active achievements whose conditionKey is in the given list.
     * Used by AchievementEvaluatorService to find which achievements to evaluate.
     *
     * @param  string[]      $conditionKeys
     * @return Achievement[]
     */
    public function findActiveByConditionKeys(array $conditionKeys): array
    {
        if ([] === $conditionKeys) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->where('a.active = true')
            ->andWhere('a.conditionKey IN (:keys)')
            ->setParameter('keys', $conditionKeys)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the count of achievements in the given ID list that are challenge-type
     * (i.e. have difficulty IS NOT NULL).
     *
     * Used by CommunityConditionResolver to calculate how many challenges a user
     * has completed, by cross-referencing the user's earned achievement IDs against
     * the achievement table.
     *
     * @param int[] $ids Achievement IDs from $user->getEarnedAchievements()
     */
    public function countEarnedChallenges(array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.id IN (:ids)')
            ->andWhere('a.difficulty IS NOT NULL')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function paginate(bool $sorted = false, ?string $search = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.id', 'DESC');

        if ($search !== null && $search !== '') {
            $qb->andWhere('LOWER(a.name) LIKE LOWER(:search) OR LOWER(a.categoryKey) LIKE LOWER(:search) OR LOWER(a.conditionKey) LIKE LOWER(:search)')
               ->setParameter('search', '%' . $search . '%');
        }

        return $qb;
    }
}
