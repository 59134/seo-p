<?php

namespace App\Service;

use App\Entity\SeoPage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SeoPageImageResolver
{
    /** @var array<string, array{url: string, alt: ?string, source: string}|null> */
    private array $servicePageCache = [];

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager,
        private ParameterBagInterface $parameters,
        private RequestStack $requestStack
    ) {
    }

    /**
     * @return array{resolved: bool, source: ?string, message: string}
     */
    public function resolve(SeoPage $page, bool $force = false): array
    {
        if (!$force && $page->getImageUrl()) {
            return [
                'resolved' => true,
                'source' => $page->getImageSource(),
                'message' => 'La page possede deja une image SEO.',
            ];
        }

        $candidate = $this->fromLinkedServicePage($page) ?? $this->fromAlbums($page);

        if (!$candidate) {
            return [
                'resolved' => false,
                'source' => null,
                'message' => 'Aucune image pertinente trouvee sur la prestation liee ou dans les albums.',
            ];
        }

        $page
            ->setImageUrl($candidate['url'])
            ->setImageAlt($this->resolveAlt($page, $candidate['alt'] ?? null))
            ->setImageSource($candidate['source']);

        return [
            'resolved' => true,
            'source' => $candidate['source'],
            'message' => $candidate['source'] === 'service_page'
                ? 'Image SEO recuperee depuis la page prestation liee.'
                : 'Image SEO selectionnee dans un album pertinent.',
        ];
    }

    /** @return array{url: string, alt: ?string, source: string}|null */
    private function fromLinkedServicePage(SeoPage $page): ?array
    {
        $servicePageUrl = trim((string) $page->getSeed()?->getServicePageUrl());
        $url = $this->allowedServicePageUrl($servicePageUrl);

        if (!$url) {
            return null;
        }

        if (array_key_exists($url, $this->servicePageCache)) {
            return $this->servicePageCache[$url];
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml',
                    'User-Agent' => 'WebtimeSeoImageResolver/1.0',
                ],
                'max_redirects' => 3,
                'timeout' => 8,
            ]);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                return $this->servicePageCache[$url] = null;
            }

            $html = $response->getContent(false);
            $metadata = $this->extractImageMetadata($html);

            if (!$metadata['url']) {
                return $this->servicePageCache[$url] = null;
            }

            return $this->servicePageCache[$url] = [
                'url' => $this->absoluteUrl($metadata['url'], $url),
                'alt' => $metadata['alt'],
                'source' => 'service_page',
            ];
        } catch (\Throwable) {
            return $this->servicePageCache[$url] = null;
        }
    }

    private function allowedServicePageUrl(string $servicePageUrl): ?string
    {
        if ($servicePageUrl === '') {
            return null;
        }

        $baseUrl = $this->siteBaseUrl();
        if (!$baseUrl) {
            return null;
        }

        if (!preg_match('~^https?://~i', $servicePageUrl)) {
            return rtrim($baseUrl, '/') . '/' . ltrim($servicePageUrl, '/');
        }

        $serviceHost = strtolower((string) parse_url($servicePageUrl, PHP_URL_HOST));
        $baseHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

        return $serviceHost !== '' && $serviceHost === $baseHost ? $servicePageUrl : null;
    }

    private function siteBaseUrl(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            return $request->getSchemeAndHttpHost();
        }

        $configured = trim((string) ($_ENV['SEO_SITE_URL'] ?? $_SERVER['SEO_SITE_URL'] ?? $_ENV['APP_URL'] ?? $_SERVER['APP_URL'] ?? ''));

        return preg_match('~^https?://~i', $configured) ? rtrim($configured, '/') : null;
    }

    /** @return array{url: ?string, alt: ?string} */
    private function extractImageMetadata(string $html): array
    {
        if (!class_exists(\DOMDocument::class)) {
            return ['url' => null, 'alt' => null];
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return ['url' => null, 'alt' => null];
        }

        $values = [];
        foreach ($document->getElementsByTagName('meta') as $meta) {
            $key = strtolower(trim($meta->getAttribute('property') ?: $meta->getAttribute('name')));
            $content = trim(html_entity_decode($meta->getAttribute('content'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($key !== '' && $content !== '') {
                $values[$key] = $content;
            }
        }

        return [
            'url' => $values['og:image'] ?? $values['twitter:image'] ?? null,
            'alt' => $values['og:image:alt'] ?? $values['twitter:image:alt'] ?? null,
        ];
    }

    private function absoluteUrl(string $imageUrl, string $pageUrl): string
    {
        if (preg_match('~^https?://~i', $imageUrl)) {
            return $imageUrl;
        }

        $scheme = (string) parse_url($pageUrl, PHP_URL_SCHEME);
        $host = (string) parse_url($pageUrl, PHP_URL_HOST);
        $port = parse_url($pageUrl, PHP_URL_PORT);
        $origin = $scheme . '://' . $host . ($port ? ':' . $port : '');

        if (str_starts_with($imageUrl, '//')) {
            return $scheme . ':' . $imageUrl;
        }

        if (str_starts_with($imageUrl, '/')) {
            return $origin . $imageUrl;
        }

        $path = (string) parse_url($pageUrl, PHP_URL_PATH);
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/.');

        return $origin . ($directory ? '/' . ltrim($directory, '/') : '') . '/' . ltrim($imageUrl, '/');
    }

    /** @return array{url: string, alt: ?string, source: string}|null */
    private function fromAlbums(SeoPage $page): ?array
    {
        $albumClass = 'App\\Entity\\AlbumCategorie';
        if (!class_exists($albumClass)) {
            return null;
        }

        try {
            $albums = $this->entityManager->getRepository($albumClass)->findBy(['valid' => true], ['position' => 'ASC']);
        } catch (\Throwable) {
            return null;
        }

        $candidates = [];
        $searchText = implode(' ', array_filter([
            $page->getSeed()?->getService(),
            $page->getMainKeyword(),
        ]));

        foreach ($albums as $album) {
            if (!method_exists($album, 'getImageAlbums')) {
                continue;
            }

            $images = [];
            foreach ($album->getImageAlbums() as $image) {
                $name = method_exists($image, 'getName') ? trim((string) $image->getName()) : '';
                if ($name !== '') {
                    $images[] = $name;
                }
            }

            if ($images === []) {
                continue;
            }

            $albumText = implode(' ', array_filter([
                method_exists($album, 'getName') ? $album->getName() : null,
                method_exists($album, 'getContent') ? $album->getContent() : null,
                method_exists($album, 'getCategorie') && $album->getCategorie() && method_exists($album->getCategorie(), 'getName')
                    ? $album->getCategorie()->getName()
                    : null,
            ]));

            $candidates[] = [
                'images' => $images,
                'score' => $this->relevanceScore($searchText, $albumText),
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);
        if ($candidates[0]['score'] <= 0) {
            return null;
        }

        $images = $candidates[0]['images'];
        $hash = (int) sprintf('%u', crc32((string) $page->getSlug()));
        $imageName = $images[$hash % count($images)];
        $mapping = $this->parameters->has('albumCategorie_images')
            ? trim((string) $this->parameters->get('albumCategorie_images'), '/')
            : 'uploads/albums';

        return [
            'url' => '/' . $mapping . '/' . rawurlencode($imageName),
            'alt' => null,
            'source' => 'album',
        ];
    }

    private function relevanceScore(string $searchText, string $candidateText): int
    {
        $candidate = $this->normalize($candidateText);
        $score = 0;

        foreach ($this->keywords($searchText) as $keyword) {
            if (str_contains($candidate, $keyword)) {
                $score += 2;
            }
        }

        return $score;
    }

    /** @return string[] */
    private function keywords(string $text): array
    {
        $stopWords = ['avec', 'dans', 'pour', 'prix', 'coutant', 'installation', 'remplacement', 'service', 'votre'];
        $words = preg_split('/[^a-z0-9]+/', $this->normalize($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            static fn (string $word): bool => strlen($word) >= 4 && !in_array($word, $stopWords, true)
        )));
    }

    private function normalize(string $value): string
    {
        $value = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return strtolower($ascii !== false ? $ascii : $value);
    }

    private function resolveAlt(SeoPage $page, ?string $sourceAlt): string
    {
        foreach ($page->getImageAltSuggestions() as $suggestion) {
            $suggestion = $this->cleanText($suggestion);
            if ($suggestion !== '') {
                return $suggestion;
            }
        }

        $sourceAlt = $this->cleanText($sourceAlt);
        if ($sourceAlt !== '') {
            return $sourceAlt;
        }

        $service = $this->cleanText($page->getSeed()?->getService() ?: $page->getMainKeyword());
        $brand = $this->businessName();

        return trim('Illustration de ' . ($service ?: 'la prestation') . ($brand ? ' proposee par ' . $brand : ''));
    }

    private function businessName(): string
    {
        $configClass = 'App\\Entity\\ConfigAdmin';
        if (!class_exists($configClass)) {
            return '';
        }

        try {
            $config = $this->entityManager->getRepository($configClass)->find(1);

            return $config && method_exists($config, 'getRaisonSociale')
                ? $this->cleanText($config->getRaisonSociale())
                : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function cleanText(?string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
    }
}
