<?php

namespace App\Repository;

use App\Entity\SeoSeed;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeoSeed>
 */
class SeoSeedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeoSeed::class);
    }

    public function add(SeoSeed $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(SeoSeed $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return SeoSeed[]
     */
    public function findReadyForGeneration(int $minimumCompleteness = 50): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.valid = :valid')
            ->andWhere('s.dataCompletenessScore >= :minimumCompleteness')
            ->setParameter('valid', true)
            ->setParameter('minimumCompleteness', $minimumCompleteness)
            ->orderBy('s.priority', 'DESC')
            ->addOrderBy('s.businessValue', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
