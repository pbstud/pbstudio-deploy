<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Staff;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<Staff>
 *
 * @implements PasswordUpgraderInterface<Staff>
 *
 * @method Staff|null find($id, $lockMode = null, $lockVersion = null)
 * @method Staff|null findOneBy(array $criteria, array $orderBy = null)
 * @method Staff[]    findAll()
 * @method Staff[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class StaffRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Staff::class);
    }

    /**
     * @return Staff[]
     */
    public function findByRole($role, $isActive = null): array
    {
        $qb = $this->createQueryBuilder('s');

        $qb
            ->addSelect('p')
            ->leftJoin('s.profile', 'p')
            ->where('s.roles LIKE :roles')
            ->andWhere('s.deleted = :deleted')

            ->setParameter('roles', '%"'.$role.'"%')
            ->setParameter('deleted', false)
        ;

        if (is_bool($isActive)) {
            $qb
                ->andWhere('s.isActive = :isActive')
                ->setParameter('isActive', $isActive)
            ;
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Staff[]
     */
    public function getAllStaff(): array
    {
        $qb = $this->createQueryBuilder('s');

        $qb
            ->where($qb->expr()->like('s.roles', $qb->expr()->literal('%ROLE_ADMIN%')))
            ->orWhere($qb->expr()->like('s.roles', $qb->expr()->literal('%ROLE_COLLABORATOR%')))
        ;

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Staff[]
     */
    public function getAllInstructors(): array
    {
        return $this->findByRole('ROLE_INSTRUCTOR');
    }

    /**
     * @return Staff[]
     */
    public function getAllActiveInstructors(): array
    {
        return $this->findByRole('ROLE_INSTRUCTOR', true);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof Staff) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }
}
