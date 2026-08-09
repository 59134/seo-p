<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SeoProgrammaticExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('seo_elfsight_reviews_app_id', [$this, 'getElfsightReviewsAppId']),
        ];
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
