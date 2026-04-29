<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GiftCardHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GiftCardHistory>
 */
class GiftCardHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GiftCardHistory::class);
    }

    /**
     * @return GiftCardHistory[]
     */
    public function findByGiftCard(int $giftCardId): array
    {
        return $this->createQueryBuilder('gch')
            ->join('gch.giftCard', 'gc')
            ->andWhere('gc.id = :giftCardId')
            ->andWhere('gch.action != :hiddenAction')
            ->setParameter('giftCardId', $giftCardId)
            ->setParameter('hiddenAction', GiftCardHistory::ACTION_CREATED_FROM_BACKEND)
            ->orderBy('gch.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
