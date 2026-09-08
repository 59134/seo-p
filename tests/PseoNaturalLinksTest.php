<?php

use App\Service\SeoSiteLinkProvider;
use App\Twig\SeoProgrammaticExtension;
use PHPUnit\Framework\TestCase;

final class PseoNaturalLinksTest extends TestCase
{
    private function extension(): SeoProgrammaticExtension
    {
        return new SeoProgrammaticExtension($this->createMock(SeoSiteLinkProvider::class));
    }

    /** @dataProvider anchors */
    public function testInlineLinkPreservesTheParagraphOrFallsBackToCta(string $body, string $anchor, bool $inline): void
    {
        $link = ['url' => '/isolation', 'label' => 'Isolation', 'section' => 2, 'placement' => 'inline', 'anchor' => $anchor];
        $result = $this->extension()->sectionContent($body, [$link], 2);
        self::assertSame(strip_tags($body), implode('', array_column($result['parts'], 'text')));
        $linked = array_values(array_filter($result['parts'], static fn (array $part): bool => isset($part['url'])));
        self::assertCount($inline ? 1 : 0, $linked);
        self::assertSame($inline ? [] : [$link], $result['ctas']);
        if ($inline) {
            self::assertSame(['text' => $anchor, 'url' => '/isolation'], $linked[0]);
        }
    }

    public static function anchors(): array
    {
        return [
            ['Associer isolation et placo facilite le chantier.', 'isolation et placo', true],
            ["Une rénovation électrique améliore le confort.\nLa suite est conservée.", 'rénovation électrique', true],
            ['Préparer les travaux (isolation) et le chantier.', '(isolation)', true],
            ['Pose de cloisons et peinture.', 'isolation', false],
            ['Isolation puis isolation.', 'isolation', true],
            ['isolation puis isolation.', 'isolation', false],
            ['Préparer un chantier.', 'chant', false],
            ['Préparer un chantier.', '', false],
            ['<strong>Isolation</strong> thermique.', 'Isolation', true],
            ['', 'Isolation', false],
        ];
    }

    public function testMultipleLinksKeepTheirTextOrderAndOverlapsBecomeCtas(): void
    {
        $links = [
            ['url' => '/peinture', 'section' => 1, 'placement' => 'inline', 'anchor' => 'peinture'],
            ['url' => '/isolation', 'section' => 1, 'placement' => 'inline', 'anchor' => 'isolation thermique'],
            ['url' => '/thermique', 'section' => 1, 'placement' => 'inline', 'anchor' => 'thermique'],
            ['url' => '/ailleurs', 'section' => 2, 'placement' => 'cta'],
        ];
        $body = 'Associer isolation thermique et peinture dans le projet.';
        $result = $this->extension()->sectionContent($body, $links, 1);
        self::assertSame($body, implode('', array_column($result['parts'], 'text')));
        self::assertSame(['/isolation', '/peinture'], array_column($result['parts'], 'url'));
        self::assertSame([$links[2]], $result['ctas']);
    }

    public function testRenderedLegacyLinksAreCtasAndInlineLinksStayWithinTheSentence(): void
    {
        $twig = new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__) . '/files/templates'), ['strict_variables' => true]);
        $twig->addExtension($this->extension());
        $body = "Prévoir l'isolation thermique avec la peinture.\nDemander les détails.";
        $html = $twig->render('pages/seo_programmatic/_section_content.html.twig', [
            'body' => $body,
            'section_number' => 1,
            'links' => [
                ['url' => '/isolation', 'label' => 'Isolation', 'section' => 1, 'placement' => 'inline', 'anchor' => 'isolation thermique'],
                ['url' => '/bain', 'label' => 'Salles de bain', 'section' => 1, 'context' => 'Pour un projet plus large incluant'],
                ['url' => '/electricite', 'label' => 'Electricite', 'section' => 1, 'placement' => 'inline', 'anchor' => 'introuvable', 'cta_label' => 'Découvrir nos travaux électriques'],
                ['url' => '/peinture', 'label' => 'Peinture', 'section' => 1, 'placement' => 'cta', 'cta_label' => '<img src=x onerror=alert(1)>Voir la peinture'],
            ],
        ]);
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);
        $paragraph = $xpath->query('//p')->item(0);
        self::assertSame($body, $paragraph->textContent);
        self::assertSame(1, $xpath->query('//p/a[@href="/isolation"]')->length);
        self::assertSame('isolation thermique', $xpath->query('//p/a')->item(0)->textContent);
        self::assertSame(3, $xpath->query('//div[@class="seo-section-actions"]/a')->length);
        self::assertStringContainsString('Découvrir : Salles de bain', $html);
        self::assertStringContainsString('Découvrir nos travaux électriques', $html);
        self::assertStringNotContainsString('Pour un projet plus large incluant', $html);
        self::assertSame(0, $xpath->query('//img | //script')->length);
    }
}
