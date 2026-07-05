<?php

namespace App\Repository;

use App\Entity\SeoFact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeoFact>
 */
class SeoFactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeoFact::class);
    }

    public function add(SeoFact $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(SeoFact $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return SeoFact[]
     */
    public function findActiveForPrompt(string $locale = 'fr'): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.valid = :valid')
            ->andWhere('f.locale = :locale OR f.locale = :fallback')
            ->setParameter('valid', true)
            ->setParameter('locale', $locale)
            ->setParameter('fallback', 'fr')
            ->orderBy('f.priority', 'DESC')
            ->addOrderBy('f.type', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
