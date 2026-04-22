<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Package;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Package>
 *
 * @method Package|null find($id, $lockMode = null, $lockVersion = null)
 * @method Package|null findOneBy(array $criteria, array $orderBy = null)
 * @method Package[]    findAll()
 * @method Package[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PackageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Package::class);
    }

    public function findWithFilters(array $filters, bool $sorted = false): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p');

        if (!$sorted) {
            $qb
                ->addSelect('CASE WHEN p.isActive = true THEN 0 ELSE 1 END AS HIDDEN sort_group')
                ->orderBy('sort_group', 'ASC')
                ->addOrderBy('p.id', 'DESC');
        } else {
            $qb->orderBy('p.id', 'DESC');
        }

        foreach ($this->getFilterMap($filters, 'p') as $key => $filter) {
            $qb
                ->andWhere(sprintf('%s = :%s', $filter['field'], $key))
                ->setParameter($key, $filter['value'])
            ;
        }

        return $qb;
    }

    /**
     * @return Package[]
     */
    public function getAllActive(): array
    {
        $qb = $this->createQueryBuilder('p');

        $qb
            ->where('p.isActive = :active')
            ->orderBy('p.type', 'desc')
            ->addOrderBy('p.totalClasses')

            ->setParameter('active', true)
        ;

        return $qb->getQuery()->getResult();
    }

    private function getFilterMap(array $filters, string $alias): array
    {
        $map = [
            'total_classes' => 'p.totalClasses',
            'status' => 'p.isActive',
        ];

        $valid = [];
        foreach ($filters as $key => $value) {
            if ('' !== $value) {
                $valid[$key] = [
                    'field' => $map[$key] ?? sprintf('%s.%s', $alias, $key),
                    'value' => $value,
                ];
            }
        }

        return $valid;
    }
}
