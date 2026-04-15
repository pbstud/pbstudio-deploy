<?php

namespace App\Repository;

use App\Entity\SessionAudit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SessionAudit>
 */
class SessionAuditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SessionAudit::class);
    }

    /**
     * Obtiene auditoría de cambios en una sesión
     */
    public function findBySession($session): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.session = :session')
            ->setParameter('session', $session)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Obtiene auditoría por administrador
     */
    public function findByAdminUser($user): array
    {
        $identifier = $user;
        if (is_object($user) && method_exists($user, 'getUserIdentifier')) {
            $identifier = $user->getUserIdentifier();
        }

        return $this->createQueryBuilder('a')
            ->where('a.adminUserIdentifier = :adminUserIdentifier')
            ->setParameter('adminUserIdentifier', (string) $identifier)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Obtiene eventos vinculados por flujo de cambio.
     */
    public function findByChangeFlowId(string $changeFlowId): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.changeFlowId = :changeFlowId')
            ->setParameter('changeFlowId', $changeFlowId)
            ->orderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Verifica si una reservación ya tuvo flujo de cambio registrado en auditoría.
     */
    public function hasReservationBeenChanged(int $reservationId): bool
    {
        $count = (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.reservationId = :reservationId')
            ->andWhere('a.auditType IN (:auditTypes)')
            ->setParameter('reservationId', $reservationId)
            ->setParameter('auditTypes', [
                'user_changed',
                'user_changed_from',
                'user_changed_to',
            ])
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
