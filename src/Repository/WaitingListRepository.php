<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Session;
use App\Entity\User;
use App\Entity\WaitingList;
use Carbon\Carbon;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WaitingList>
 *
 * @method WaitingList|null find($id, $lockMode = null, $lockVersion = null)
 * @method WaitingList|null findOneBy(array $criteria, array $orderBy = null)
 * @method WaitingList[]    findAll()
 * @method WaitingList[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class WaitingListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WaitingList::class);
    }

    public function getByUser(User $user, bool $count = false): array|int
    {
        $qb = $this->createQueryBuilder('wl');

        $qb
            ->addSelect('s', 'd', 'i', 'p')
            ->join('wl.session', 's')
            ->join('s.discipline', 'd')
            ->join('s.instructor', 'i')
            ->join('i.profile', 'p')
            ->where('wl.user = :user')
            ->andWhere('wl.isAvailable = :isAvailable')
            ->andWhere('s.status = :sessionStatus')
            ->orderBy('s.dateStart', 'ASC')
            ->addOrderBy('s.timeStart', 'ASC')
            ->setParameter('user', $user)
            ->setParameter('isAvailable', true)
            ->setParameter('sessionStatus', Session::STATUS_FULL)
        ;

        if ($count) {
            $qb->select('COUNT(wl.session)');

            return $qb->getQuery()->getSingleScalarResult();
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return WaitingList[]
     */
    public function getAvailableBySession(Session $session): array
    {
        $qb = $this->createQueryBuilder('wl');

        $qb
            ->addSelect('u')
            ->join('wl.user', 'u')
            ->where('wl.session = :session')
            ->andWhere('wl.isAvailable = :isAvailable')
            ->setParameter('session', $session)
            ->setParameter('isAvailable', true)
            ->orderBy('wl.createdAt', 'ASC')
        ;

        return $qb->getQuery()->getResult();
    }

    public function getOneBySession(int $sessionId, User $user): ?WaitingList
    {
        $qb = $this->createQueryBuilder('wl');

        $qb
            ->where('wl.session = :sessionId')
            ->andWhere('wl.user = :user')
            ->setMaxResults(1)
            ->setParameter('sessionId', $sessionId)
            ->setParameter('user', $user)
        ;

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function hasConsumedEntryForUserAndSession(User $user, Session $session): bool
    {
        $qb = $this->createQueryBuilder('wl');

        $qb
            ->select('1')
            ->where('wl.user = :user')
            ->andWhere('wl.session = :session')
            ->andWhere('wl.isAvailable = :isAvailable')
            ->setParameter('user', $user)
            ->setParameter('session', $session)
            ->setParameter('isAvailable', false)
            ->setMaxResults(1)
        ;

        return null !== $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return WaitingList[]
     */
    public function getAvailableForExpire(): array
    {
        $expirationDateTime = Carbon::now()->addHours(WaitingList::EXPIRATION_HOURS);
        $expirationDate = $expirationDateTime->toDateString();
        $expirationTime = $expirationDateTime->format('H:i:s');

        $qb = $this->createQueryBuilder('wl');

        $qb
            ->addSelect('s', 'u')
            ->join('wl.session', 's')
            ->join('wl.user', 'u')
            ->where('wl.isAvailable = :isAvailable')
            ->andWhere('(s.dateStart < :expirationDate OR (s.dateStart = :expirationDate AND s.timeStart <= :expirationTime))')
            ->setParameter('isAvailable', true)
            ->setParameter('expirationDate', $expirationDate)
            ->setParameter('expirationTime', $expirationTime)
            ->orderBy('wl.createdAt', 'ASC')
        ;

        return $qb->getQuery()->getResult();
    }
}
