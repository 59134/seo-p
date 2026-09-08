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
            new TwigFunction('seo_section_content', [$this, 'sectionContent']),
        ];
    }

    /** Splits plain text into escapable parts; never accepts generated link HTML. */
    public function sectionContent(string $body, array $links, int $section): array
    {
        $body = strip_tags($body);
        $ranges = [];
        $ctas = [];
        foreach ($links as $link) {
            if (($link['section'] ?? 0) !== $section) {
                continue;
            }
            $anchor = $link['anchor'] ?? '';
            $matched = false;
            if (($link['placement'] ?? '') === 'inline' && is_string($anchor) && $anchor !== '' && mb_strlen($anchor) <= 150) {
                // An exact, unique whole phrase avoids linking the wrong occurrence.
                $pattern = '~(?<![\p{L}\p{N}])' . preg_quote($anchor, '~') . '(?![\p{L}\p{N}])~u';
                if (preg_match_all($pattern, $body, $matches, PREG_OFFSET_CAPTURE) === 1) {
                    $start = $matches[0][0][1];
                    $end = $start + strlen($anchor);
                    $overlap = false;
                    foreach ($ranges as $range) {
                        if ($start < $range['end'] && $end > $range['start']) {
                            $overlap = true;
                            break;
                        }
                    }
                    if (!$overlap) {
                        $ranges[] = ['start' => $start, 'end' => $end, 'url' => $link['url']];
                        $matched = true;
                    }
                }
            }
            if (!$matched) {
                $ctas[] = $link;
            }
        }

        usort($ranges, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $parts = [];
        $offset = 0;
        foreach ($ranges as $range) {
            if ($range['start'] > $offset) {
                $parts[] = ['text' => substr($body, $offset, $range['start'] - $offset)];
            }
            $parts[] = ['text' => substr($body, $range['start'], $range['end'] - $range['start']), 'url' => $range['url']];
            $offset = $range['end'];
        }
        $parts[] = ['text' => substr($body, $offset)];

        return ['parts' => $parts, 'ctas' => $ctas];
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
