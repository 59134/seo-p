<?php

namespace App\Twig;

use App\Service\OpeningHoursNormalizer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class StructuredDataExtension extends AbstractExtension
{
    public function __construct(private OpeningHoursNormalizer $openingHours)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('schema_opening_hours', [$this->openingHours, 'normalize']),
            new TwigFilter('schema_text', [$this, 'plainText']),
        ];
    }

    public function plainText(?string $text): string
    {
        $text = preg_replace('~<br\b[^>]*>|</(?:p|div|li)>~i', "\n", $text ?? '') ?? '';

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
