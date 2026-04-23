<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TransactionFreezeLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TransactionFreezeLog>
 */
class TransactionFreezeLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TransactionFreezeLog::class);
    }

    /**
     * Returns all freeze/unfreeze log entries for a given transaction, newest first.
     *
     * @return TransactionFreezeLog[]
     */
    public function findByTransaction(int $transactionId): array
    {
        return $this->createQueryBuilder('tfl')
            ->join('tfl.transaction', 't')
            ->where('t.id = :txId')
            ->setParameter('txId', $transactionId)
            ->orderBy('tfl.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
