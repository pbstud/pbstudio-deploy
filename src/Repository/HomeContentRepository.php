<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HomeContent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HomeContent>
 */
class HomeContentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HomeContent::class);
    }

    /**
     * Devuelve el unico registro de configuracion del home, o null si no existe.
     */
    public function findSingle(): ?HomeContent
    {
        return $this->findOneBy([]);
    }
}
