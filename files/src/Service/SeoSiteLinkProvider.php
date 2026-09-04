<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\Categorie;
use App\Entity\Module;
use App\Entity\SeoPage;
use App\Entity\SeoSeed;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/** Builds a local allowlist; never crawls URLs or executes their controllers. */
class SeoSiteLinkProvider
{
    private const PUBLIC_ROUTES = [
        'app_main' => null,
        'contact' => 'Contact',
        'prestations' => 'Prestation',
        'equipes' => 'Equipe',
        'avantApres' => 'BeforeAfter',
        'partenaire' => 'Partenaire',
        'cartes' => 'Carte',
        'albums' => 'AlbumCategorie',
        'temoignage' => 'Temoignage',
        'galerie' => 'Galerie',
        'blog' => 'Blog',
    ];

    public function __construct(private EntityManagerInterface $entityManager, private RouterInterface $router)
    {
    }

    /** @return array<string, array{url: string, label: string, summary: string}> */
    public function catalog(string $locale, ?string $serviceUrl = null, bool $withContent = false): array
    {
        $modules = [];
        foreach ($this->entityManager->getRepository(Module::class)->findBy(['valid' => true]) as $module) {
            $modules[$module->getName()] = true;
        }

        $links = [];
        $categories = isset($modules['Categorie'])
            ? $this->entityManager->getRepository(Categorie::class)->createQueryBuilder('c')
                ->leftJoin('c.translations', 't')->addSelect('t')
                ->andWhere('c.valid = :valid')->andWhere('c.clickable = :valid')
                ->setParameter('valid', true)->orderBy('c.position', 'ASC')->getQuery()->getResult()
            : [];

        $summaries = $withContent && $categories ? $this->categorySummaries($categories, $locale) : [];
        foreach ($categories as $category) {
            if (!$this->visibleParents($category) || trim((string) $category->getUrl()) !== '') {
                continue;
            }
            $url = $this->generate('app_categorie', [
                'id' => $category->getId(), 'slug' => strtolower((string) $category->getSlug()), '_locale' => $locale,
            ]);
            if (!$url) {
                continue;
            }
            $translation = $category->getTranslations()->get($locale);
            $label = SeoSeed::cleanPlainTextLine($translation?->getName() ?: $category->getName());
            if ($label !== '') {
                $summary = $summaries[$category->getId()] ?? SeoSeed::cleanPlainTextLine($category->getMetaDesc());
                $links[$url] = ['url' => $url, 'label' => $label, 'summary' => mb_substr($summary, 0, 300)];
            }
        }

        foreach ($categories as $category) {
            if (!$category->getUrl() || !$this->visibleParents($category)) {
                continue;
            }
            $url = $this->normalize($category->getUrl());
            if (!$url || isset($links[$url]) || !$this->isPublicDestination($url, $locale, $modules)) {
                continue;
            }
            $translation = $category->getTranslations()->get($locale);
            $label = SeoSeed::cleanPlainTextLine($translation?->getName() ?: $category->getName());
            if ($label !== '') {
                $links[$url] = ['url' => $url, 'label' => $label, 'summary' => ''];
            }
        }

        $url = $serviceUrl ? $this->normalize($serviceUrl) : null;
        if ($url && !isset($links[$url]) && $this->isPublicDestination($url, $locale, $modules)) {
            $links[$url] = ['url' => $url, 'label' => '', 'summary' => ''];
        }

        return $links;
    }

    public function normalize(string $url): ?string
    {
        $context = $this->routingContext();
        return self::normalizeUrl($url, $context->getHost(), $context->getBaseUrl(), $context->getHttpPort(), $context->getHttpsPort());
    }

    public static function normalizeUrl(string $url, string $host, string $baseUrl = '', int $httpPort = 80, int $httpsPort = 443): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || preg_match('~[\x00-\x20\\\\]~', $url) || str_starts_with($url, '//')) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        if (isset($parts['scheme']) || isset($parts['host'])) {
            $scheme = strtolower($parts['scheme'] ?? '');
            if (!in_array($scheme, ['http', 'https'], true) || strcasecmp($parts['host'] ?? '', $host) !== 0) {
                return null;
            }
            if (($parts['port'] ?? ($scheme === 'https' ? 443 : 80)) !== ($scheme === 'https' ? $httpsPort : $httpPort)) {
                return null;
            }
        }
        $path = $parts['path'] ?? '/';
        $baseUrl = rtrim($baseUrl, '/');
        if (!str_starts_with($path, '/')) {
            $path = $baseUrl . '/' . $path;
        }
        if ($baseUrl !== '' && $path !== $baseUrl && !str_starts_with($path, $baseUrl . '/')) {
            return null;
        }
        $decoded = rawurldecode($path);
        if (preg_match('~[\x00-\x20\\\\?#%]|//|(?:^|/)\.{1,2}(?:/|$)~', $decoded)) {
            return null;
        }
        return $path;
    }

    /** Keeps the old label/url format; section and context are optional additions. */
    public function filterLinks(array $links, array $catalog, int $sectionCount, ?string $selfUrl = null): array
    {
        $result = [];
        foreach ($links as $link) {
            if (!is_array($link) || !is_string($link['url'] ?? null)) {
                continue;
            }
            $url = $this->normalize($link['url']);
            if (!$url || $url === $selfUrl || !isset($catalog[$url]) || isset($result[$url])) {
                continue;
            }
            $label = SeoSeed::cleanPlainTextLine(is_string($link['label'] ?? null) ? $link['label'] : $catalog[$url]['label']);
            if ($label === '') {
                continue;
            }
            $item = ['url' => $url, 'label' => mb_substr($label, 0, 150)];
            $section = $link['section'] ?? null;
            if (is_int($section) && $section > 0 && $section <= $sectionCount) {
                $item['section'] = $section;
                $item['context'] = mb_substr(SeoSeed::cleanPlainTextLine(is_string($link['context'] ?? null) ? $link['context'] : ''), 0, 240);
            }
            $result[$url] = $item;
        }
        return array_values($result);
    }

    public function forPage(SeoPage $page): array
    {
        $serviceUrl = $page->getSeed()?->getServicePageUrl();
        $serviceUrl = $serviceUrl ? $this->normalize($serviceUrl) : null;
        // The service pillar already has its own CTA; legacy pages need no catalog for this alone.
        $stored = array_values(array_filter($page->getInternalLinks(), fn (mixed $link): bool => is_array($link)
            && is_string($link['url'] ?? null)
            && ((int) ($link['section'] ?? 0) > 0 || $this->normalize($link['url']) !== $serviceUrl)));
        if (!$stored) {
            return [];
        }
        $catalog = $this->catalog($page->getLocale(), $page->getSeed()?->getServicePageUrl());
        $selfUrl = $this->generate('seo_programmatic_page', ['slug' => $page->getSlug(), '_locale' => $page->getLocale()]);
        $links = $this->filterLinks($stored, $catalog, count($page->getContent()), $selfUrl);
        return array_values(array_filter($links, static fn (array $link): bool => isset($link['section']) || $link['url'] !== $serviceUrl));
    }

    private function categorySummaries(array $categories, string $locale): array
    {
        $rows = $this->entityManager->getRepository(Article::class)->createQueryBuilder('a')
            ->select('IDENTITY(a.categorie) AS categoryId', 'SUBSTRING(COALESCE(t.content, a.content), 1, 1200) AS excerpt')
            ->leftJoin('a.translations', 't', 'WITH', 't.locale = :locale')
            ->andWhere('a.valid = :valid')->andWhere('a.categorie IN (:categories)')
            ->setParameter('locale', $locale)->setParameter('valid', true)->setParameter('categories', $categories)
            ->orderBy('a.position', 'ASC')->getQuery()->getArrayResult();
        $summaries = [];
        foreach ($rows as $row) {
            $id = $row['categoryId'];
            $summaries[$id] = mb_substr(trim(($summaries[$id] ?? '') . ' ' . SeoSeed::cleanPlainTextLine($row['excerpt'])), 0, 300);
        }
        return array_filter($summaries);
    }

    private function generate(string $route, array $parameters): ?string
    {
        try {
            $generator = new UrlGenerator($this->router->getRouteCollection(), $this->routingContext());
            return $this->normalize($generator->generate($route, $parameters));
        } catch (\Symfony\Component\Routing\Exception\ExceptionInterface) {
            return null;
        }
    }

    private function visibleParents(Categorie $category): bool
    {
        $seen = [];
        while ($category = $category->getParent()) {
            $id = $category->getId();
            if (isset($seen[$id]) || !$category->isValid()) {
                return false;
            }
            $seen[$id] = true;
        }
        return true;
    }

    private function isPublicDestination(string $url, string $locale, array $modules): bool
    {
        $context = $this->routingContext();
        $context->setMethod('GET');
        $path = substr($url, strlen(rtrim($context->getBaseUrl(), '/'))) ?: '/';
        try {
            $match = (new UrlMatcher($this->router->getRouteCollection(), $context))->match($path);
        } catch (\Symfony\Component\Routing\Exception\ExceptionInterface) {
            return false;
        }
        if (isset($match['_locale']) && $match['_locale'] !== $locale) {
            return false;
        }
        $route = $match['_canonical_route'] ?? $match['_route'] ?? '';
        if (array_key_exists($route, self::PUBLIC_ROUTES)) {
            $module = self::PUBLIC_ROUTES[$route];
            return $module === null || isset($modules[$module]);
        }
        if ($route === 'seo_programmatic_page' && isset($modules['SeoPage'])) {
            return null !== $this->entityManager->getRepository(SeoPage::class)->findPublishedBySlug($match['slug'], $locale);
        }
        return false;
    }

    private function routingContext(): RequestContext
    {
        $context = clone $this->router->getContext();
        $siteUrl = trim((string) ($_ENV['SEO_SITE_URL'] ?? $_SERVER['SEO_SITE_URL'] ?? ''));
        // CLI generation can lack the public host and installation path.
        if ($context->getHost() === 'localhost' && $siteUrl !== '') {
            $parts = parse_url($siteUrl);
            if (is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])
                && !isset($parts['query']) && !isset($parts['fragment'])) {
                $context->setHost($parts['host']);
                $context->setScheme($parts['scheme']);
                $context->setBaseUrl(rtrim($parts['path'] ?? '', '/'));
                if (isset($parts['port'])) {
                    $parts['scheme'] === 'https' ? $context->setHttpsPort($parts['port']) : $context->setHttpPort($parts['port']);
                }
            }
        }
        return $context;
    }
}
