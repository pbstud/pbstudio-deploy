<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Configuration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Configuration>
 *
 * @method Configuration|null find($id, $lockMode = null, $lockVersion = null)
 * @method Configuration|null findOneBy(array $criteria, array $orderBy = null)
 * @method Configuration[]    findAll()
 * @method Configuration[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ConfigurationRepository extends ServiceEntityRepository
{
    private array $config = [];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Configuration::class);
    }

    public function getByModule(string $module): ?Configuration
    {
        if (isset($this->config[$module])) {
            return $this->config[$module];
        }

        $qb = $this->createQueryBuilder('c');

        $qb
            ->where('c.module = :module')
            ->setParameter('module', $module)
        ;

        $result = $qb->getQuery()->getOneOrNullResult();
        $this->config[$module] = $result;

        return $result;
    }

    public function findGeneral(): ?Configuration
    {
        return $this->getByModule('general');
    }

    public function findConekta(): ?Configuration
    {
        return $this->getByModule('conekta');
    }

    public function findSessions(): ?Configuration
    {
        return $this->getByModule('sessions');
    }

    public function findNotice(): ?Configuration
    {
        return $this->getByModule('notice');
    }

    public function findStats(): ?Configuration
    {
        return $this->getByModule('stats');
    }
}
