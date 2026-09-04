<?php

namespace App\Twig;

use App\Entity\SeoPage;
use App\Entity\SeoSeed;
use App\Service\SeoSiteLinkProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SeoProgrammaticExtension extends AbstractExtension
{
    public function __construct(private SeoSiteLinkProvider $siteLinks)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('seo_elfsight_reviews_app_id', [$this, 'getElfsightReviewsAppId']),
            new TwigFunction('seo_internal_links', [$this->siteLinks, 'forPage']),
            new TwigFunction('seo_related_anchor', [$this, 'relatedAnchor']),
        ];
    }

    public function relatedAnchor(SeoPage $source, SeoPage $target): string
    {
        $keyword = SeoSeed::cleanPlainTextLine($target->getCleanMainKeyword() ?: $target->getCleanH1());
        if ($keyword === '' || $target->getLocale() !== 'fr') {
            return $keyword;
        }
        $variants = [$keyword];
        $h1 = SeoSeed::cleanPlainTextLine($target->getCleanH1());
        $city = (string) $target->getSeed()?->getCity();
        if ($h1 !== '' && mb_strlen($h1) <= 100 && $city !== '' && mb_stripos($h1, $city) !== false) {
            $variants[] = $h1;
        }
        $variants[] = 'Découvrir : ' . $keyword;
        $variants[] = 'Voir la page : ' . $keyword;
        $variants = array_values(array_unique($variants));
        // Stable for a source/target pair, independent of visit time and database ordering.
        $hash = hash('sha256', $source->getLocale() . '|' . $source->getSlug() . '|' . $target->getSlug());
        return $variants[hexdec(substr($hash, 0, 6)) % count($variants)];
    }

    public function getElfsightReviewsAppId(): ?string
    {
        $appId = trim((string) ($_ENV['SEO_ELFSIGHT_REVIEWS_APP_ID'] ?? $_SERVER['SEO_ELFSIGHT_REVIEWS_APP_ID'] ?? ''));
        $appId = preg_replace('/^elfsight-app-/i', '', $appId) ?? '';

        if ($appId === '' || !preg_match('/^[a-z0-9-]{8,80}$/i', $appId)) {
            return null;
        }

        return $appId;
    }
}
