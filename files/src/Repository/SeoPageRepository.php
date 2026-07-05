<?php

namespace App\Repository;

use App\Entity\SeoPage;
use App\Entity\SeoSeed;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeoPage>
 */
class SeoPageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeoPage::class);
    }

    public function add(SeoPage $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(SeoPage $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findPublishedBySlug(string $slug, string $locale = 'fr'): ?SeoPage
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.slug = :slug')
            ->andWhere('p.locale = :locale')
            ->andWhere('p.status = :status')
            ->andWhere('p.indexable = :indexable')
            ->setParameter('slug', $slug)
            ->setParameter('locale', $locale)
            ->setParameter('status', SeoPage::STATUS_PUBLISHED)
            ->setParameter('indexable', true)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return SeoPage[]
     */
    public function findIndexablePages(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status = :status')
            ->andWhere('p.indexable = :indexable')
            ->setParameter('status', SeoPage::STATUS_PUBLISHED)
            ->setParameter('indexable', true)
            ->orderBy('p.qualityScore', 'DESC')
            ->addOrderBy('p.updated_at', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return SeoPage[]
     */
    public function findForAntiDuplicationPrompt(SeoSeed $seed, int $limit = 8): array
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->leftJoin('p.seed', 's')
            ->addSelect('s')
            ->andWhere('p.locale = :locale')
            ->andWhere('p.status != :archived')
            ->setParameter('locale', $seed->getLocale())
            ->setParameter('archived', SeoPage::STATUS_ARCHIVED)
            ->orderBy('p.qualityScore', 'DESC')
            ->addOrderBy('p.updated_at', 'DESC')
            ->setMaxResults($limit);

        if ($seed->getId()) {
            $queryBuilder
                ->andWhere('s.id IS NULL OR s.id != :seedId')
                ->setParameter('seedId', $seed->getId());
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * @return SeoPage[]
     */
    public function findRelatedPublishedPages(SeoPage $page, ?int $limit = null, bool $publicOnly = true): array
    {
        $queryBuilder = $this->createRelatedBaseQueryBuilder($page, $limit, $publicOnly);
        $seed = $page->getSeed();
        $sourceKeyword = $this->findSourceKeyword($seed);
        $sourceSeedId = $this->findSourceSeedId($seed);
        $sourceChildren = $this->extractPageKeywords($seed?->getPageKeywords() ?? []);
        $service = $seed?->getService();

        if ($sourceKeyword || $sourceSeedId) {
            $orConditions = [];

            if ($sourceKeyword) {
                $orConditions[] = 's.mainKeyword = :sourceKeyword';
                $orConditions[] = 's.notes LIKE :sourceNote';
                $queryBuilder
                    ->setParameter('sourceKeyword', $sourceKeyword)
                    ->setParameter('sourceNote', '%Seed cree automatiquement depuis le seed source% "' . $sourceKeyword . '"%');
            }

            if ($sourceSeedId) {
                $orConditions[] = 's.id = :sourceSeedId';
                $orConditions[] = 's.notes LIKE :sourceIdNote';
                $queryBuilder
                    ->setParameter('sourceSeedId', $sourceSeedId)
                    ->setParameter('sourceIdNote', '%Seed cree automatiquement depuis le seed source #' . $sourceSeedId . '%');
            }

            if ($sourceChildren) {
                $orConditions[] = 's.mainKeyword IN (:sourceChildren)';
                $queryBuilder->setParameter('sourceChildren', $sourceChildren);
            }

            $queryBuilder->andWhere($queryBuilder->expr()->orX(...$orConditions));

            $results = $queryBuilder->getQuery()->getResult();

            if ($results) {
                return $results;
            }

            $queryBuilder = $this->createRelatedBaseQueryBuilder($page, $limit, $publicOnly);
        }

        if ($service) {
            $queryBuilder
                ->andWhere('s.service = :service')
                ->setParameter('service', $service);

            $results = $queryBuilder->getQuery()->getResult();

            if ($results) {
                return $results;
            }
        }

        return $this->findKeywordRelatedPages($page, $limit, $publicOnly);
    }

    private function findSourceSeedId(?SeoSeed $seed): ?int
    {
        if (!$seed) {
            return null;
        }

        $notes = (string) $seed->getNotes();

        if (preg_match('/Seed cree automatiquement depuis le seed source #(\d+)/', $notes, $matches)) {
            return (int) $matches[1];
        }

        if ($seed->getPageKeywords()) {
            return $seed->getId();
        }

        return null;
    }

    private function createRelatedBaseQueryBuilder(SeoPage $page, ?int $limit, bool $publicOnly): \Doctrine\ORM\QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->leftJoin('p.seed', 's')
            ->addSelect('s')
            ->andWhere('p.id != :pageId')
            ->andWhere('p.locale = :locale')
            ->setParameter('pageId', $page->getId())
            ->setParameter('locale', $page->getLocale())
            ->orderBy('p.qualityScore', 'DESC')
            ->addOrderBy('p.publishedAt', 'DESC')
            ->addOrderBy('p.updated_at', 'DESC');

        if ($limit !== null && $limit > 0) {
            $queryBuilder->setMaxResults($limit);
        }

        if ($publicOnly) {
            $queryBuilder
                ->andWhere('p.status = :status')
                ->andWhere('p.indexable = :indexable')
                ->setParameter('status', SeoPage::STATUS_PUBLISHED)
                ->setParameter('indexable', true);
        } else {
            $queryBuilder
                ->andWhere('p.status != :archived')
                ->setParameter('archived', SeoPage::STATUS_ARCHIVED);
        }

        return $queryBuilder;
    }

    private function findSourceKeyword(?SeoSeed $seed): ?string
    {
        if (!$seed) {
            return null;
        }

        $notes = (string) $seed->getNotes();

        if (preg_match('/Seed cree automatiquement depuis le seed source(?: #\d+)? "([^"]+)"/', $notes, $matches)) {
            return trim($matches[1]) ?: null;
        }

        if ($seed->getPageKeywords()) {
            return $seed->getMainKeyword();
        }

        return null;
    }

    /**
     * @return SeoPage[]
     */
    private function findKeywordRelatedPages(SeoPage $page, ?int $limit, bool $publicOnly): array
    {
        $currentTokens = $this->keywordTokens($page);

        if (count($currentTokens) < 2) {
            return [];
        }

        $candidates = $this->createRelatedBaseQueryBuilder($page, 80, $publicOnly)
            ->getQuery()
            ->getResult();

        $matches = [];

        foreach ($candidates as $candidate) {
            $candidateTokens = $this->keywordTokens($candidate);
            $score = count(array_intersect($currentTokens, $candidateTokens));

            if ($score >= 2) {
                $matches[] = [
                    'page' => $candidate,
                    'score' => $score,
                    'quality' => $candidate->getQualityScore(),
                ];
            }
        }

        usort($matches, static function (array $a, array $b): int {
            return [$b['score'], $b['quality']] <=> [$a['score'], $a['quality']];
        });

        $pages = array_map(static fn (array $match): SeoPage => $match['page'], $matches);

        return $limit !== null && $limit > 0 ? array_slice($pages, 0, $limit) : $pages;
    }

    /**
     * @return string[]
     */
    private function keywordTokens(SeoPage $page): array
    {
        $seed = $page->getSeed();
        $texts = [
            $page->getMainKeyword(),
            $seed?->getMainKeyword(),
            $seed?->getService(),
            $this->findSourceKeyword($seed),
        ];

        $cityTokens = $this->tokensFromText((string) $seed?->getCity(), []);
        $tokens = [];

        foreach ($texts as $text) {
            $tokens = array_merge($tokens, $this->tokensFromText((string) $text, $cityTokens));
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param string[] $cityTokens
     *
     * @return string[]
     */
    private function tokensFromText(string $text, array $cityTokens): array
    {
        $normalized = $this->normalizeKeywordText($text);

        if ($normalized === '') {
            return [];
        }

        $stopWords = [
            'avec', 'chez', 'dans', 'des', 'du', 'les', 'par', 'pour', 'sur', 'une',
            'aux', 'dans', 'depuis', 'vers', 'ville', 'local', 'locale',
        ];
        $tokens = preg_split('/\s+/', $normalized) ?: [];
        $tokens = array_filter($tokens, static function (string $token) use ($stopWords, $cityTokens): bool {
            return strlen($token) > 2
                && !in_array($token, $stopWords, true)
                && !in_array($token, $cityTokens, true);
        });

        return array_values(array_unique($tokens));
    }

    private function normalizeKeywordText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        $text = strtolower((string) $text);

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $text) ?: '');
    }

    /**
     * @param string[] $pageKeywords
     *
     * @return string[]
     */
    private function extractPageKeywords(array $pageKeywords): array
    {
        $keywords = [];

        foreach ($pageKeywords as $line) {
            $parts = array_map('trim', explode('|', (string) $line));
            $keyword = $parts[0] ?? '';

            if ($keyword !== '') {
                $keywords[] = $keyword;
            }
        }

        return array_values(array_unique($keywords));
    }
}
