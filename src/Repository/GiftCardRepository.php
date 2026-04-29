<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GiftCard;
use App\Entity\Transaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GiftCard>
 */
class GiftCardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GiftCard::class);
    }

    public function findOneByCode(string $code): ?GiftCard
    {
        return $this->createQueryBuilder('gc')
            ->andWhere('gc.code = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneRedeemableByCode(string $code): ?GiftCard
    {
        return $this->createQueryBuilder('gc')
            ->andWhere('gc.code = :code')
            ->andWhere('gc.status = :status')
            ->setParameter('code', $code)
            ->setParameter('status', GiftCard::STATUS_GENERATED)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByPurchaseTransactionId(int $transactionId): ?GiftCard
    {
        return $this->createQueryBuilder('gc')
            ->join('gc.purchaseTransaction', 'pt')
            ->andWhere('pt.id = :transactionId')
            ->setParameter('transactionId', $transactionId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByRedemptionTransaction(Transaction $transaction): ?GiftCard
    {
        return $this->createQueryBuilder('gc')
            ->join('gc.redemptionTransaction', 'rt')
            ->andWhere('rt.id = :transactionId')
            ->setParameter('transactionId', $transaction->getId())
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findForBackendList(array $filters = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('gc')
            ->addSelect('package', 'purchaseTx', 'redemptionTx', 'purchaser', 'recipient')
            ->join('gc.package', 'package')
            ->join('gc.purchaseTransaction', 'purchaseTx')
            ->join('gc.purchaserUser', 'purchaser')
            ->leftJoin('gc.redemptionTransaction', 'redemptionTx')
            ->leftJoin('gc.recipientUser', 'recipient')
            ->orderBy('gc.id', 'DESC');

        $search = trim((string) ($filters['search'] ?? ''));
        if ('' !== $search) {
            $qb
                ->andWhere(
                    "gc.code LIKE :search
                    OR purchaser.email LIKE :search
                    OR recipient.email LIKE :search
                    OR purchaser.name LIKE :search
                    OR purchaser.lastname LIKE :search
                    OR recipient.name LIKE :search
                    OR recipient.lastname LIKE :search
                    OR CONCAT(COALESCE(purchaser.name, ''), ' ', COALESCE(purchaser.lastname, '')) LIKE :search
                    OR CONCAT(COALESCE(recipient.name, ''), ' ', COALESCE(recipient.lastname, '')) LIKE :search"
                )
                ->setParameter('search', '%' . $search . '%');
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ('' !== $status) {
            $this->applyStatusFilter($qb, $status);
        }

        $origin = trim((string) ($filters['origin'] ?? ''));
        if ('' !== $origin) {
            $qb
                ->andWhere('gc.originChannel = :origin')
                ->setParameter('origin', $origin);
        }

        $dateStartRaw = trim((string) ($filters['date_start'] ?? ''));
        if ('' !== $dateStartRaw) {
            $dateStart = $this->parseFilterDate($dateStartRaw);
            if (null !== $dateStart) {
                $qb
                    ->andWhere('gc.purchasedAt >= :dateStart')
                    ->setParameter('dateStart', $dateStart->setTime(0, 0)->format('Y-m-d H:i:s'));
            }
        }

        $dateEndRaw = trim((string) ($filters['date_end'] ?? ''));
        if ('' !== $dateEndRaw) {
            $dateEnd = $this->parseFilterDate($dateEndRaw);
            if (null !== $dateEnd) {
                $qb
                    ->andWhere('gc.purchasedAt <= :dateEnd')
                    ->setParameter('dateEnd', $dateEnd->setTime(23, 59, 59)->format('Y-m-d H:i:s'));
            }
        }

        return $qb;
    }

    private function parseFilterDate(string $rawDate): ?\DateTimeImmutable
    {
        $normalized = preg_replace('/[^0-9]+/', '/', trim($rawDate));
        if (null === $normalized || '' === trim($normalized, '/')) {
            return null;
        }

        $parts = explode('/', trim($normalized, '/'));
        if (3 !== \count($parts)) {
            return null;
        }

        [$day, $month, $year] = $parts;

        if (!ctype_digit($day) || !ctype_digit($month) || !ctype_digit($year)) {
            return null;
        }

        $dayInt = (int) $day;
        $monthInt = (int) $month;
        $yearInt = (int) $year;

        if (!checkdate($monthInt, $dayInt, $yearInt)) {
            return null;
        }

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $yearInt, $monthInt, $dayInt));
    }

    private function applyStatusFilter(QueryBuilder $qb, string $status): void
    {
        if (GiftCard::STATUS_FROZEN === $status) {
            $qb
                ->andWhere('(purchaseTx.status = :frozenStatus OR redemptionTx.status = :frozenStatus)')
                ->setParameter('frozenStatus', Transaction::STATUS_FROZEN);

            return;
        }

        if (GiftCard::STATUS_CANCELLED === $status) {
            $qb
                ->andWhere('(gc.status = :giftCancelledStatus OR purchaseTx.status = :transactionCancelledStatus OR redemptionTx.status = :transactionCancelledStatus)')
                ->setParameter('giftCancelledStatus', GiftCard::STATUS_CANCELLED)
                ->setParameter('transactionCancelledStatus', Transaction::STATUS_CANCEL);

            return;
        }

        $qb
            ->andWhere('gc.status = :status')
            ->setParameter('status', $status);
    }
}
