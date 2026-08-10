<?php

namespace App\Repository;

use App\Entity\SeoPromptTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeoPromptTemplate>
 */
class SeoPromptTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeoPromptTemplate::class);
    }

    public function add(SeoPromptTemplate $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(SeoPromptTemplate $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findActive(string $type = 'service_city', string $locale = 'fr'): ?SeoPromptTemplate
    {
        return $this->createQueryBuilder('t')
            ->addSelect('CASE WHEN t.locale = :locale THEN 0 ELSE 1 END AS HIDDEN localePriority')
            ->andWhere('t.active = :active')
            ->andWhere('t.type = :type')
            ->andWhere('t.locale = :locale OR t.locale = :fallback')
            ->setParameter('active', true)
            ->setParameter('type', $type)
            ->setParameter('locale', $locale)
            ->setParameter('fallback', 'fr')
            ->orderBy('localePriority', 'ASC')
            ->addOrderBy('t.updated_at', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
