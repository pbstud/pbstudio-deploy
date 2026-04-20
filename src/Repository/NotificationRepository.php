<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 *
 * @method Notification|null find($id, $lockMode = null, $lockVersion = null)
 * @method Notification|null findOneBy(array $criteria, array $orderBy = null)
 * @method Notification[]    findAll()
 * @method Notification[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function countUnread(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function existsUnreadByType(User $user, string $type): bool
    {
        $count = (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->andWhere('n.type = :type')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('type', $type)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function existsByTypeCreatedToday(User $user, string $type): bool
    {
        $today = new \DateTimeImmutable('today midnight');
        $tomorrow = $today->modify('+1 day');

        $count = (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->andWhere('n.type = :type')
            ->andWhere('n.createdAt >= :today')
            ->andWhere('n.createdAt < :tomorrow')
            ->setParameter('user', $user)
            ->setParameter('type', $type)
            ->setParameter('today', $today)
            ->setParameter('tomorrow', $tomorrow)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function findByUser(User $user, int $page = 1, int $limit = 20): array
    {
        $limit = min(50, max(1, $limit));
        $page  = max(1, $page);

        return $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function markAsRead(int $id, User $user): Notification
    {
        $notification = $this->findOneBy(['id' => $id, 'user' => $user]);

        if (null === $notification) {
            throw new \DomainException(sprintf(
                'Notification %d not found or does not belong to user %d.',
                $id,
                $user->getId()
            ));
        }

        if (!$notification->isRead()) {
            $notification->markAsRead();
            $this->getEntityManager()->flush();
        }

        return $notification;
    }

    public function existsRecentDuplicate(User $user, string $type, string $resourceKey, int $windowSeconds = 300): bool
    {
        $since = new \DateTimeImmutable(sprintf('-%d seconds', $windowSeconds));

        $count = (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(1)
             FROM notification
             WHERE user_id = :userId
               AND type = :type
               AND JSON_UNQUOTE(JSON_EXTRACT(payload, :jsonPath)) = :resourceKey
               AND created_at >= :since',
            [
                'userId'      => $user->getId(),
                'type'        => $type,
                'jsonPath'    => '$.resource_key',
                'resourceKey' => $resourceKey,
                'since'       => $since->format('Y-m-d H:i:s'),
            ]
        );

        return $count > 0;
    }

    /**
     * Verifica si ya existe una notificación con el resource_key dado (sin límite de tiempo).
     * Usado para deduplicación permanente, ej. recordatorios de clase.
     */
    public function existsByResourceKey(User $user, string $type, string $resourceKey): bool
    {
        $count = (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(1)
             FROM notification
             WHERE user_id = :userId
               AND type = :type
               AND JSON_UNQUOTE(JSON_EXTRACT(payload, :jsonPath)) = :resourceKey',
            [
                'userId'      => $user->getId(),
                'type'        => $type,
                'jsonPath'    => '$.resource_key',
                'resourceKey' => $resourceKey,
            ]
        );

        return $count > 0;
    }

    public function countAll(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function markAllAsRead(User $user): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE notification SET read_at = NOW() WHERE user_id = :userId AND read_at IS NULL',
            ['userId' => $user->getId()]
        );
    }

    public function markAsUnread(int $id, User $user): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE notification SET read_at = NULL WHERE id = :id AND user_id = :userId',
            ['id' => $id, 'userId' => $user->getId()]
        );
    }

    public function deleteById(int $id, User $user): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM notification WHERE id = :id AND user_id = :userId',
            ['id' => $id, 'userId' => $user->getId()]
        );
    }

    /**
     * Ejecuta una accion masiva sobre un conjunto de IDs del usuario.
     *
     * @param int[]  $ids
     * @param string $action 'read' | 'unread' | 'delete'
     *
     * @throws \InvalidArgumentException si $action no es valida o $ids esta vacio
     */
    public function bulkAction(array $ids, User $user, string $action): int
    {
        if (!in_array($action, ['read', 'unread', 'delete'], true)) {
            throw new \InvalidArgumentException(sprintf('Bulk action "%s" is not valid.', $action));
        }

        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id) => $id > 0));

        if ([] === $ids) {
            return 0;
        }

        $conn        = $this->getEntityManager()->getConnection();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params       = [...$ids, $user->getId()];
        $types        = [...array_fill(0, count($ids), \PDO::PARAM_INT), \PDO::PARAM_INT];

        $sql = match ($action) {
            'read'   => "UPDATE notification SET read_at = NOW() WHERE id IN ($placeholders) AND user_id = ? AND read_at IS NULL",
            'unread' => "UPDATE notification SET read_at = NULL    WHERE id IN ($placeholders) AND user_id = ?",
            'delete' => "DELETE FROM notification                   WHERE id IN ($placeholders) AND user_id = ?",
        };

        return (int) $conn->executeStatement($sql, $params, $types);
    }

    public function save(Notification $notification, bool $flush = false): void
    {
        $this->getEntityManager()->persist($notification);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
