<?php

namespace App\Service;

final class OpeningHoursNormalizer
{
    /** @return string[] Recognized weekly slots only; never infer an incomplete schedule. */
    public function normalize(?string $html): array
    {
        if ($html === null || trim($html) === '') {
            return [];
        }

        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('~<br\b[^>]*>|</(?:p|div|li)>~i', "\n", $text) ?? $text;
        $text = str_replace(["\xc2\xa0", "\xe2\x80\xaf", "\xe2\x80\x93", "\xe2\x80\x94"], [' ', ' ', '-', '-'], strip_tags($text));
        $lines = preg_split('/[\r\n;]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $days = ['lundi' => 'Mo', 'mardi' => 'Tu', 'mercredi' => 'We', 'jeudi' => 'Th', 'vendredi' => 'Fr', 'samedi' => 'Sa', 'dimanche' => 'Su',
            'mo' => 'Mo', 'tu' => 'Tu', 'we' => 'We', 'th' => 'Th', 'fr' => 'Fr', 'sa' => 'Sa', 'su' => 'Su'];
        $day = '(?:Mo|Tu|We|Th|Fr|Sa|Su)';
        $time = '\d{1,2}(?::\d{2}|h(?:\d{2})?)';
        $slot = $time . '\s*(?:-|a|à)\s*' . $time;
        $dayRange = $day . '(?:\s*(?:-|au|a|à)\s*' . $day . ')?';
        $pattern = '~^(?:du\s+|le\s+)?(?<days>' . $dayRange . '(?:\s*(?:,|et)\s*' . $dayRange . ')*)\s*(?::|de)?\s*(?<slots>' . $slot . '(?:\s*(?:et|,)\s*' . $slot . ')*)\s*$~iu';
        $result = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $line = preg_replace_callback('~\b(' . implode('|', array_keys($days)) . ')\b~iu',
                static fn (array $match): string => $days[mb_strtolower($match[0], 'UTF-8')], $line) ?? $line;
            if (!preg_match($pattern, $line, $matches)) {
                return [];
            }

            $dayRange = preg_replace('~\s*(?:\bau\b|\ba\b|à|-)\s*~iu', '-', $matches['days']);
            $dayRange = preg_replace('~\s*(?:et|,)\s*~iu', ',', $dayRange ?? '');
            preg_match_all('~(' . $time . ')\s*(?:-|a|à)\s*(' . $time . ')~iu', $matches['slots'], $slots, PREG_SET_ORDER);
            foreach ($slots as $times) {
                $opens = $this->normalizeTime($times[1]);
                $closes = $this->normalizeTime($times[2]);
                if ($opens === null || $closes === null || $opens === $closes) {
                    return [];
                }
                $result[] = $dayRange . ' ' . $opens . '-' . $closes;
            }
        }

        return array_values(array_unique($result));
    }

    private function normalizeTime(string $time): ?string
    {
        $parts = preg_split('/[:h]/i', $time);
        $hours = (int) $parts[0];
        $minutes = (int) ($parts[1] ?? 0);
        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hours, $minutes);
    }
}
