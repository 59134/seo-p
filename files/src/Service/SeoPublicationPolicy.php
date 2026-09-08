<?php

namespace App\Service;

final class SeoPublicationPolicy
{
    /** @return array{blocking: string[], review: string[], advisory: string[]} */
    public static function classify(array $missingData): array
    {
        $result = ['blocking' => [], 'review' => [], 'advisory' => []];
        foreach ($missingData as $item) {
            if (!is_string($item) || trim($item) === '') {
                continue;
            }
            $item = trim($item);
            $key = self::classifyIssue($item);
            $result[$key][] = $item;
        }
        return array_map(static fn (array $items): array => array_values(array_unique($items)), $result);
    }

    private static function classifyIssue(string $item): string
    {
        $text = self::normalize($item);

        // Explicit contradictions take precedence over optional details in the same line.
        $critical = [
            '~\bgeneration claude\b|\berreur claude\b~',
            '~\b(service|prestation|activite) (?:reellement )?(?:non (?:confirme|propose|verifie|prouve|valide|documente|disponible)|inconnu)~',
            '~\b(zone non|ville non couverte|secteur non couvert|couverture non|hors (?:de la )?zone)\b~',
            '~\b(entreprise inconnue|nom entreprise|nom de l entreprise)\b~',
            '~\b(?:information|affirmation|donnee|preuve)s? (?:locale?s? )?(?:inventee?s?|fausse?s?|non verifiee?s? (?:presente?s?|utilisee?s?))\b~',
            '~\b(?:contenu|page|texte|json) (?:inutilisable|inexploitable|invalide|vide|tronque)\b~',
            '~\bdonnees metier insuffisantes\b~',
        ];
        foreach ($critical as $pattern) {
            if (preg_match($pattern, $text)) {
                return 'blocking';
            }
        }

        // Editorial doubts require human review; wording alone is not proof of duplication.
        $review = [
            '~\b(?:contenu|page|texte) (?:entierement |totalement |trop )?(?:generique|interchangeable|duplique|similaire|sans valeur propre)\b~',
            '~\b(?:aucune|absence de) (?:matiere|valeur|utilite) (?:locale|specifique)\b~',
            '~\babsence totale de (?:faits locaux|donnees locales|matiere locale)\b~',
            '~\bdeja utilise(?:e?s)? sur d autres pages\b~',
            '~\b(?:risque de (?:duplication|similarite)|preuves? metier insuffisantes?)\b~',
        ];
        foreach ($review as $pattern) {
            if (preg_match($pattern, $text)) {
                return 'review';
            }
        }

        if (preg_match('/^\s*\[relecture\]/i', $item)) {
            return 'review';
        }

        // Reclassify known legacy local alerts, including their old [bloquant] prefix.
        if (preg_match('~\b(?:faits? loca(?:l|ux)|donnees? locales?|contexte local)\b~', $text)) {
            $technicalDetail = '~\b(?:statistiques?|donnees (?:chiffrees|statistiques|techniques)|precisions techniques|etude technique|parc de chauffage|types? d energie|anciennete des installations|(?:types?|typologie) de bati|contraintes locales|(?:usage|etat|composition|materiaux) (?:du|de la|des))\b~';
            if (preg_match($technicalDetail, $text)) {
                return 'advisory';
            }
            return 'review';
        }

        if (preg_match('/^\s*\[bloquant\]/i', $item)) {
            return 'blocking';
        }
        if (preg_match('~\bpreuves? metier\b~', $text)) {
            return 'review';
        }

        return 'advisory';
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = strtr($text, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe']);
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '');
    }
}
