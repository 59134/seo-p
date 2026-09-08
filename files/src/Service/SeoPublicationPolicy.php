<?php

namespace App\Service;

final class SeoPublicationPolicy
{
    /** @return array{blocking: string[], advisory: string[]} */
    public static function classify(array $missingData): array
    {
        $result = ['blocking' => [], 'advisory' => []];
        foreach ($missingData as $item) {
            if (!is_string($item) || trim($item) === '') {
                continue;
            }
            $item = trim($item);
            $key = self::isBlocking($item) ? 'blocking' : 'advisory';
            $result[$key][] = $item;
        }
        return array_map(static fn (array $items): array => array_values(array_unique($items)), $result);
    }

    private static function isBlocking(string $item): bool
    {
        $text = self::normalize($item);

        // Explicit contradictions take precedence over optional details in the same line.
        $critical = [
            '~\bgeneration claude\b|\berreur claude\b~',
            '~\b(service|prestation|activite) (?:reellement )?(?:non (?:confirme|propose|verifie|prouve|valide)|inconnu)~',
            '~\b(zone non|ville non couverte|secteur non couvert|couverture non|hors (?:de la )?zone)\b~',
            '~\b(entreprise inconnue|nom entreprise|nom de l entreprise)\b~',
            '~\b(?:information|affirmation|donnee|preuve)s? (?:locale?s? )?(?:inventee?s?|fausse?s?|non verifiee?s? (?:presente?s?|utilisee?s?))\b~',
            '~\b(?:contenu|page|texte) (?:entierement |totalement |trop )?(?:generique|interchangeable|duplique|sans valeur propre)\b~',
            '~\b(?:aucune|absence de) (?:matiere|valeur|utilite) (?:locale|specifique)\b~',
            '~\babsence totale de (?:faits locaux|donnees locales|matiere locale)\b~',
            '~\bdonnees metier insuffisantes\b~',
        ];
        foreach ($critical as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        if (preg_match('/^\s*\[bloquant\]/i', $item)) {
            return true;
        }
        if (preg_match('/^\s*\[amelioration\]/i', $item)) {
            return false;
        }

        // Legacy free-text alerts only become advisory when a technical gap is explicit.
        if (preg_match('~\bfait(?:s locaux| local)\b~', $text)) {
            $technicalDetail = '~\b(?:statistiques?|donnees (?:chiffrees|statistiques|techniques)|precisions techniques|etude technique|parc de chauffage|types d energie|anciennete des installations|(?:usage|etat|composition|materiaux) (?:du|de la|des))\b~';
            if (preg_match($technicalDetail, $text)) {
                return false;
            }
            return true;
        }

        return (bool) preg_match('~\bpreuves? metier\b~', $text);
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = strtr($text, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe']);
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '');
    }
}
