<?php

use App\Entity\SeoPage;
use App\Entity\SeoSeed;
use App\Service\OpeningHoursNormalizer;
use App\Twig\StructuredDataExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class StructuredDataTest extends TestCase
{
    /** @dataProvider hours */
    public function testOnlyCompleteRecognizedSchedulesArePublished(?string $input, array $expected): void
    {
        self::assertSame($expected, (new OpeningHoursNormalizer())->normalize($input));
    }

    public static function hours(): array
    {
        return [
            ["\r\n\r\nDu lundi au vendredi de 9h - 18h\r\n\r\n", ['Mo-Fr 09:00-18:00']],
            ['<p>Du lundi au vendredi de 9h - 18h</p>', ['Mo-Fr 09:00-18:00']],
            ['Lundi au vendredi : 9h30 à 12h et 14h à 18h', ['Mo-Fr 09:30-12:00', 'Mo-Fr 14:00-18:00']],
            ['<p>Lundi : 9h-18h</p><p>Samedi : 10h-12h</p>', ['Mo 09:00-18:00', 'Sa 10:00-12:00']],
            ['Mo-Fr 09:00-18:00; Sa 10:00-12:00', ['Mo-Fr 09:00-18:00', 'Sa 10:00-12:00']],
            ['Tu,Th 16:00-20:00', ['Tu,Th 16:00-20:00']],
            ['Lundi et mercredi de 9h à 17h', ['Mo,We 09:00-17:00']],
            ['DU LUNDI AU VENDREDI DE 9H - 18H', ['Mo-Fr 09:00-18:00']],
            ['Du lundi au vendredi de 9h&nbsp;–&nbsp;18h', ['Mo-Fr 09:00-18:00']],
            ['Sa 22:00-02:00', ['Sa 22:00-02:00']],
            ['<p>Mo 09:00-18:00</p><p>Mo 09:00-18:00</p>', ['Mo 09:00-18:00']],
            [null, []], ['', []], ['<p>&nbsp;</p>', []],
            ['Sur rendez-vous', []],
            ['Du lundi au vendredi de 9h - 18h sur rendez-vous', []],
            ["Mo-Fr 09:00-18:00\nSauf jours fériés", []],
            ['Du lundi au vendredi de 9h - 18h sauf le mercredi', []],
            ["Mo 09:00-18:00\nSamedi : selon disponibilité", []],
            ['Mo 29:00-18:00', []], ['Mo 09:90-18:00', []],
            ['Mo 09:-18:', []], ['Mo 9-18', []], ['Mo 00:00-00:00', []],
            ['Mo-Fr-Sa 09:00-18:00', []],
        ];
    }

    private function twig(?string $hours): Environment
    {
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['base.html.twig' => '{% block extra_schema %}{% endblock %}']),
            new FilesystemLoader(dirname(__DIR__) . '/files/templates'),
        ]), ['strict_variables' => true]);
        $twig->addExtension(new StructuredDataExtension(new OpeningHoursNormalizer()));
        // The complete PSEO template is compiled even when only its schema block is rendered.
        $provider = $this->createMock(App\Service\SeoSiteLinkProvider::class);
        $twig->addExtension(new App\Twig\SeoProgrammaticExtension($provider));
        $twig->addFilter(new TwigFilter('replaceAll', static fn ($text) => str_replace('%VILLE%', 'Cambrai', $text ?? '')));
        $twig->addFunction(new TwigFunction('config', static fn () => ['raisonSociale' => 'JLV "Picavet" & Fils </script><script>window.jsonLdLeak=1</script>']));
        $twig->addFunction(new TwigFunction('coordonnees', static fn () => ['horaires' => $hours, 'tel1' => '03 00 00 00 00', 'adresse' => "1 rue Exemple\nBâtiment B", 'ville' => 'Cambrai', 'cp' => '59400', 'pays' => 'FR']));
        $twig->addFunction(new TwigFunction('designFront', static fn () => ['logo' => ['logo' => 'logo.webp']]));
        $twig->addFunction(new TwigFunction('pathImage', static fn () => new Doctrine\Common\Collections\ArrayCollection(['elementFront_images' => 'uploads'])));
        $twig->addFunction(new TwigFunction('asset', static fn ($path) => '/client/public/' . $path));
        $twig->addFunction(new TwigFunction('path', static fn ($route, $params = []) => '/client/public/' . ($params['slug'] ?? '')));
        $twig->addFunction(new TwigFunction('absolute_url', static fn ($path) => str_starts_with($path, 'https://') ? $path : 'https://example.test' . $path));
        return $twig;
    }

    private function decodeScript(string $html): array
    {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $scripts = $dom->getElementsByTagName('script');
        self::assertSame(1, $scripts->length);
        self::assertSame('application/ld+json', $scripts->item(0)->getAttribute('type'));
        $json = $scripts->item(0)->textContent;
        self::assertStringNotContainsString('<', $json);
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function item(string $question, string $answer, bool $valid = true): array
    {
        return ['valid' => $valid, 'getTranslations' => ['fr' => ['question' => $question, 'reponse' => $answer]]];
    }

    public function testCommonGraphEncodesAllValuesAndCombinesOnlyVisibleFaqItems(): void
    {
        $twig = $this->twig("\r\nDu lundi au vendredi de 9h - 18h\r\n");
        $html = $twig->render('_partials/_structured_data.html.twig', [
            'app' => ['request' => ['locale' => 'fr', 'baseUrl' => '/client/public', 'pathInfo' => '/service']],
            'categorie' => ['getMetaDesc' => "Une allée à %VILLE% : \"gravier\"\net bordures."],
            'faq' => [
                ['valid' => true, 'faqItems' => [$this->item('Quelle "allée" choisir ?', "<p>Gravier &amp; bordures.</p><p>Deuxième ligne 🌿</p>"), $this->item('Inactive', 'Non visible', false)]],
                ['valid' => false, 'faqItems' => [$this->item('Groupe inactif', 'Non visible')]],
                ['valid' => true, 'faqItems' => [$this->item('Quel entretien ?', "Une réponse\navec un chemin C:\\exemple."), $this->item('Vide ?', ''), ['valid' => true, 'getTranslations' => ['en' => ['question' => 'English', 'reponse' => 'Answer']]]]],
            ],
        ]);
        file_put_contents(__DIR__ . '/rendered-structured-data.html', $html);
        $graph = $this->decodeScript($html)['@graph'];
        self::assertCount(3, $graph);
        self::assertSame('https://example.test/client/public/', $graph[0]['url']);
        self::assertStringContainsString('"Picavet" & Fils </script>', $graph[0]['name']);
        self::assertSame("Une allée à Cambrai : \"gravier\"\net bordures.", $graph[1]['description']);
        self::assertSame(['Mo-Fr 09:00-18:00'], $graph[1]['openingHours']);
        self::assertSame('https://example.test/client/public/service#faq', $graph[2]['@id']);
        self::assertCount(2, $graph[2]['mainEntity']);
        self::assertSame('Quelle "allée" choisir ?', $graph[2]['mainEntity'][0]['name']);
        self::assertSame("Gravier & bordures.\nDeuxième ligne 🌿", $graph[2]['mainEntity'][0]['acceptedAnswer']['text']);
    }

    public function testUnknownHoursAndEmptyFaqAreOmitted(): void
    {
        $html = $this->twig('Sur rendez-vous')->render('_partials/_structured_data.html.twig');
        $graph = $this->decodeScript($html)['@graph'];
        self::assertCount(2, $graph);
        self::assertArrayNotHasKey('openingHours', $graph[1]);
        self::assertSame('', $graph[1]['description']);
    }

    public function testInstalledCmsBaseRendersItsRealStructuredDataBlock(): void
    {
        $cmsRoot = getenv('PSEO_CMS_DIR') ?: dirname(__DIR__, 4) . '/cms';
        if (!is_file($cmsRoot . '/templates/base.html.twig') || !str_contains(file_get_contents($cmsRoot . '/templates/base.html.twig'), "include '_partials/_structured_data.html.twig'")) {
            self::markTestSkipped('CMS base integration is not installed in this checkout.');
        }
        $twig = $this->twig('Du lundi au vendredi de 9h - 18h');
        $twig->setLoader(new FilesystemLoader($cmsRoot . '/templates'));
        $twig->addExtension(new Symfony\Bridge\Twig\Extension\TranslationExtension(new Symfony\Component\Translation\Translator('fr')));
        $twig->registerUndefinedFunctionCallback(static fn (string $name) => new TwigFunction($name, static function () use ($name): void {
            throw new RuntimeException('Unexpected call outside the schema block: ' . $name);
        }));
        $html = $twig->load('base.html.twig')->renderBlock('structured_data', [
            'app' => ['request' => ['locale' => 'fr', 'baseUrl' => '/client/public', 'pathInfo' => '/service']],
            'faq' => [['valid' => true, 'faqItems' => [$this->item('Une "question" ?', "Réponse\nmultiligne")]]],
        ]);
        file_put_contents(__DIR__ . '/rendered-cms-structured-data.html', $html);
        $graph = $this->decodeScript($html)['@graph'];
        self::assertSame(['Mo-Fr 09:00-18:00'], $graph[1]['openingHours']);
        self::assertSame('Une "question" ?', $graph[2]['mainEntity'][0]['name']);
    }

    public function testPseoGraphUsesTheSameSafeHoursAndSerialization(): void
    {
        $page = (new SeoPage())->setSeed((new SeoSeed())->setCity('Cambrai')->setService('Paysagiste'))
            ->setSlug('paysagiste-cambrai')->setTitle('Paysagiste "Cambrai"')->setMetaDescription("Une description\navec retours")
            ->setFaq([['question' => 'Quel "matériau" ?', 'answer' => "Gravier\n& bordures"]]);
        foreach (['Du lundi au vendredi de 9h - 18h', 'Sur rendez-vous'] as $hours) {
            $html = $this->twig($hours)->render('pages/seo_programmatic/show.html.twig', ['page' => $page]);
            $graph = $this->decodeScript($html)['@graph'];
            if ($hours === 'Sur rendez-vous') {
                self::assertArrayNotHasKey('openingHours', $graph[0]);
            } else {
                self::assertSame(['Mo-Fr 09:00-18:00'], $graph[0]['openingHours']);
            }
            self::assertSame('FAQPage', $graph[3]['@type']);
            self::assertSame('Quel "matériau" ?', $graph[3]['mainEntity'][0]['name']);
        }
    }
}
