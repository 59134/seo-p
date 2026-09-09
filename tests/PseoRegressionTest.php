<?php

use App\Entity\Categorie;
use App\Entity\Module;
use App\Entity\SeoPage;
use App\Entity\SeoPromptTemplate;
use App\Entity\SeoSeed;
use App\Repository\CategorieRepository;
use App\Repository\ConfigAdminRepository;
use App\Repository\CoordonneeRepository;
use App\Repository\ModuleRepository;
use App\Repository\SeoFactRepository;
use App\Repository\SeoPageRepository;
use App\Repository\SeoPromptTemplateRepository;
use App\Service\SeoEditorialAdvisor;
use App\Service\SeoPromptBuilder;
use App\Service\SeoSeedExpander;
use App\Service\SeoSiteLinkProvider;
use App\Twig\SeoProgrammaticExtension;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

final class PseoRegressionTest extends TestCase
{
    private function router(string $base = ''): RouterInterface
    {
        $context = new RequestContext($base, 'GET', 'example.test', 'https');
        $routes = new RouteCollection();
        $routes->add('contact', new Route('/contact', ['_locale' => 'fr'], [], [], '', [], ['GET']));
        $routes->add('contact.en', new Route('/en/contact', ['_locale' => 'en', '_canonical_route' => 'contact']));
        $routes->add('app_categorie', new Route('/{id}-{slug}', ['_locale' => 'fr'], ['id' => '\\d+']));
        $routes->add('admin_editor', new Route('/admin/file-editor'));
        $routes->add('seo_programmatic_page', new Route('/{slug}', ['_locale' => 'fr'], ['slug' => '[a-z0-9-]+']));
        $router = $this->createMock(RouterInterface::class);
        $router->method('getContext')->willReturn($context);
        $router->method('getRouteCollection')->willReturn($routes);
        $generator = new UrlGenerator($routes, $context);
        $router->method('generate')->willReturnCallback([$generator, 'generate']);
        return $router;
    }

    /** @dataProvider urls */
    public function testUrlNormalization(string $url, ?string $expected, string $base = ''): void
    {
        self::assertSame($expected, SeoSiteLinkProvider::normalizeUrl($url, 'example.test', $base));
    }

    public static function urls(): array
    {
        return [
            ['https://example.test/prestations/formules', '/prestations/formules'],
            ['https://EXAMPLE.test/contact', '/contact'],
            ['http://example.test/contact', '/contact'],
            ['contact', '/cms/contact', '/cms'],
            ['/cms/en/contact', '/cms/en/contact', '/cms'],
            ['/contact', null, '/cms'],
            ['/cms-other/contact', null, '/cms'],
            ['https://outside.test/contact', null],
            ['https://example.test.evil.test/contact', null],
            ['https://example.test:8080/contact', null],
            ['https://user@example.test/contact', null],
            ['//evil.test/contact', null],
            ['javascript:alert(1)', null],
            ['data:text/html,test', null],
            ['/contact?next=https://evil.test', null],
            ['/contact#fragment', null],
            ['/foo/../admin', null],
            ['/foo/%2e%2e/admin', null],
            ['/%252e%252e/admin', null],
            ['/%20%20', null],
            ['/%0aadmin', null],
            ['/foo\\bar', null],
            ['/foo//bar', null],
            ['/foo%3fsecret', null],
            ['', null],
        ];
    }

    public function testLinksAreAllowlistedEscapableAndDeduplicated(): void
    {
        $provider = new SeoSiteLinkProvider($this->createMock(EntityManagerInterface::class), $this->router());
        $catalog = ['/contact' => ['url' => '/contact', 'label' => 'Contact', 'summary' => '']];
        $links = $provider->filterLinks([
            ['url' => 'https://evil.test/contact', 'label' => 'Outside'],
            ['url' => '/admin/file-editor', 'label' => 'Private'],
            ['url' => '/unknown', 'label' => 'Unknown'],
            ['url' => '/contact', 'label' => '<b>Contacter</b>', 'section' => 2, 'context' => '<script>alert(1)</script>Avant la demande'],
            ['url' => 'https://example.test/contact', 'label' => 'Duplicate'],
            ['url' => ['bad'], 'label' => 'Invalid'],
        ], $catalog, 3);
        self::assertCount(1, $links);
        self::assertSame('/contact', $links[0]['url']);
        self::assertSame('Contacter', $links[0]['label']);
        self::assertSame(2, $links[0]['section']);
        self::assertStringNotContainsString('<', $links[0]['context']);
        self::assertSame([], $provider->filterLinks($links, $catalog, 3, '/contact'));
        $legacy = $provider->filterLinks([['url' => '/contact', 'label' => 'Contact']], $catalog, 0);
        self::assertSame([['url' => '/contact', 'label' => 'Contact']], $legacy);
        $invalid = $provider->filterLinks([['url' => '/contact', 'label' => 'Contact', 'section' => 99]], $catalog, 3);
        self::assertArrayNotHasKey('section', $invalid[0]);
        $inline = $provider->filterLinks([['url' => '/contact', 'label' => 'Contact', 'section' => 1, 'placement' => 'inline', 'anchor' => 'notre équipe', 'cta_label' => '<b>Contacter notre équipe</b>']], $catalog, 3);
        self::assertSame('inline', $inline[0]['placement']);
        self::assertSame('notre équipe', $inline[0]['anchor']);
        self::assertSame('Contacter notre équipe', $inline[0]['cta_label']);
        $malformed = $provider->filterLinks([['url' => '/contact', 'label' => 'Contact', 'section' => 1, 'placement' => ['inline'], 'anchor' => ['bad'], 'cta_label' => str_repeat('x', 151)]], $catalog, 3);
        self::assertSame('cta', $malformed[0]['placement']);
        self::assertArrayNotHasKey('anchor', $malformed[0]);
        self::assertArrayNotHasKey('cta_label', $malformed[0]);
    }

    public function testParentNotesAndIntentDoNotChangeLocationsOrUrls(): void
    {
        $seed = (new SeoSeed())->setMainKeyword('Demenagement Lille')->setService('Demenagement')->setCity('Lille')->setDepartment('Nord')
            ->setIntent('Realisation verifiee a Lille, pas a Roubaix')
            ->setNotes('Adresse a Lille. Source https://example.test/Lille/realisation. Roubaix reste une autre ville.');
        $expander = (new ReflectionClass(SeoSeedExpander::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SeoSeedExpander::class, 'parsePageKeywordLine');
        $data = $method->invoke($expander, 'Demenagement Roubaix | Demenagement | Roubaix | Nord | Preparer sa demande', $seed);
        self::assertSame('Roubaix', $data['city']);
        self::assertStringContainsString($seed->getNotes(), $data['notes']);
        self::assertStringContainsString($seed->getIntent(), $data['intent']);
        self::assertStringContainsString('Preparer sa demande', $data['intent']);
        self::assertStringContainsString('seed source', $data['notes']);
        self::assertSame('Lille', $seed->getCity());
    }

    public function testFaqPlanIsStableAndDiffersAcrossSeeds(): void
    {
        $plans = [];
        foreach (['Lille', 'Roubaix', 'Douai', 'Lens', 'Arras', 'Tourcoing'] as $city) {
            $seed = (new SeoSeed())->setMainKeyword('Demenagement ' . $city);
            $plan = SeoEditorialAdvisor::faqPlan($seed);
            self::assertSame($plan, SeoEditorialAdvisor::faqPlan($seed));
            self::assertCount(3, $plan);
            $plans[] = implode(',', array_keys($plan));
        }
        self::assertGreaterThan(1, count(array_unique($plans)));
    }

    public function testLexicalComparisonDetectsCitySubstitutionNotJustAShortPhrase(): void
    {
        $body = str_repeat('Notre service a Lille accompagne la preparation du demenagement avec un inventaire des objets et des acces. ', 6);
        self::assertSame(1.0, SeoEditorialAdvisor::textualSimilarity($body, str_replace('Lille', 'Roubaix', $body), ['Lille', 'Roubaix']));
        self::assertSame(0.0, SeoEditorialAdvisor::textualSimilarity('Contactez nous', 'Contactez nous'));
        self::assertLessThan(0.8, SeoEditorialAdvisor::textualSimilarity($body, str_repeat('La formule detaille les options et les exclusions afin de choisir une prestation adaptee a la situation familiale. ', 6)));
    }

    public function testWarningsDoNotComparePageWithItself(): void
    {
        $seed = (new SeoSeed())->setMainKeyword('Demenagement Lille')->setCity('Lille');
        $faq = array_map(static fn (string $q): array => ['question' => $q, 'answer' => 'Selon les faits.'], ['Comment preparer a Lille ?', 'Que choisir a Lille ?', 'Comment organiser a Lille ?']);
        $page = (new SeoPage())->setSeed($seed)->setSlug('demenagement-lille')->setFaq($faq);
        $repo = $this->createMock(SeoPageRepository::class);
        $repo->method('findForAntiDuplicationPrompt')->willReturn([$page]);
        self::assertSame([], (new SeoEditorialAdvisor($repo))->warnings(['faq' => $faq], $seed));
        $otherSeed = (new SeoSeed())->setMainKeyword('Demenagement Roubaix')->setCity('Roubaix');
        $warnings = (new SeoEditorialAdvisor($repo))->warnings(['faq' => $faq], $otherSeed);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('FAQ', $warnings[0]);
    }

    public function testRelatedAnchorsAreStableDescriptiveAndDoNotChangeUrls(): void
    {
        $provider = $this->createMock(SeoSiteLinkProvider::class);
        $extension = new SeoProgrammaticExtension($provider);
        $target = (new SeoPage())->setSlug('demenagement-roubaix')->setMainKeyword('Demenagement Roubaix')->setH1('Organiser son demenagement a Roubaix')
            ->setSeed((new SeoSeed())->setCity('Roubaix'));
        $anchors = [];
        for ($i = 1; $i <= 20; $i++) {
            $source = (new SeoPage())->setSlug('ville-' . $i);
            $anchor = $extension->relatedAnchor($source, $target);
            self::assertSame($anchor, $extension->relatedAnchor($source, $target));
            self::assertStringContainsString('Roubaix', $anchor);
            $anchors[] = $anchor;
        }
        self::assertGreaterThan(1, count(array_unique($anchors)));
        self::assertSame('demenagement-roubaix', $target->getSlug());
        $target->setLocale('en');
        self::assertSame('Demenagement Roubaix', $extension->relatedAnchor(new SeoPage(), $target));
    }

    public function testCustomPromptsAreKeptButAlwaysReceiveContextAndSafetyRules(): void
    {
        $custom = (new SeoPromptTemplate())->setSystemPrompt('TON PERSONNALISE')->setUserPrompt('PROMPT PERSONNALISE anti_duplication secondary_keywords template_copy');
        $repo = $this->createMock(SeoPromptTemplateRepository::class);
        $repo->method('findActive')->willReturn($custom);
        $facts = $this->createMock(SeoFactRepository::class);
        $facts->method('findActiveForPrompt')->willReturn([]);
        $pages = $this->createMock(SeoPageRepository::class);
        $pages->method('findForAntiDuplicationPrompt')->willReturn([]);
        $links = $this->createMock(SeoSiteLinkProvider::class);
        $links->method('catalog')->willReturn([]);
        $builder = new SeoPromptBuilder($facts, $repo, $this->createMock(ConfigAdminRepository::class), $this->createMock(CoordonneeRepository::class), $pages, $links);
        $prompt = $builder->build((new SeoSeed())->setCity('Roubaix')->setMainKeyword('Demenagement Roubaix'));
        self::assertStringStartsWith('TON PERSONNALISE', $prompt['system']);
        self::assertStringContainsString('ne transpose', mb_strtolower($prompt['system']));
        self::assertStringContainsString('available_internal_pages', $prompt['user']);
        self::assertStringContainsString('Roubaix', $prompt['user']);
        self::assertStringStartsWith('PROMPT PERSONNALISE', $prompt['user']);
        self::assertSame('TON PERSONNALISE', $custom->getSystemPrompt());
        self::assertStringContainsString('placement="inline"', $prompt['system']);
        self::assertStringContainsString('cta_label', $prompt['system']);
        self::assertStringContainsString('Laisse context vide', $prompt['system']);
        self::assertStringContainsString('[amelioration]', $prompt['system']);
        self::assertStringContainsString('[bloquant]', $prompt['system']);
        self::assertStringContainsString('[relecture]', $prompt['system']);
        self::assertStringContainsString('ne doit jamais minimiser un probleme de veracite', $prompt['system']);
        self::assertStringContainsString('n exige pas une etude technique', $prompt['system']);
        self::assertSame(['inline', 'cta'], $builder->outputTool()['input_schema']['properties']['internal_links']['items']['properties']['placement']['enum']);
        self::assertStringContainsString('H3', $builder->outputTool()['input_schema']['properties']['sections']['items']['properties']['h2']['description']);
    }

    private function category(int $id, string $name, ?string $url = null): Categorie
    {
        $category = (new Categorie())->setName($name)->setSlug(strtolower($name))->setValid(true)->setClickable(true)->setUrl($url);
        (new ReflectionProperty(Categorie::class, 'id'))->setValue($category, $id);
        return $category;
    }

    public function testCatalogRejectsPrivateUnknownAndExternalMenuDestinations(): void
    {
        $categories = [
            $this->category(10, 'Formules'),
            $this->category(11, 'Contact', '/contact'),
            $this->category(12, 'Externe', 'https://external.test/formules'),
            $this->category(13, 'Administration', '/admin/file-editor'),
            $this->category(14, 'Inconnue', '/inconnue'),
            $this->category(15, 'Autre langue', '/en/contact'),
        ];
        $hiddenParent = $this->category(20, 'Parent')->setValid(false);
        $categories[] = $this->category(21, 'Enfant')->setParent($hiddenParent);
        $query = $this->createMock(AbstractQuery::class);
        $query->method('getResult')->willReturn($categories);
        $qb = $this->createMock(QueryBuilder::class);
        foreach (['leftJoin', 'addSelect', 'andWhere', 'setParameter', 'orderBy'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('getQuery')->willReturn($query);
        $categoryRepo = $this->createMock(CategorieRepository::class);
        $categoryRepo->method('createQueryBuilder')->willReturn($qb);
        $modules = $this->createMock(ModuleRepository::class);
        $modules->method('findBy')->willReturn(array_map(static fn (string $name): Module => (new Module())->setName($name)->setValid(true), ['Categorie', 'Contact', 'SeoPage']));
        $pages = $this->createMock(SeoPageRepository::class);
        $pages->method('findPublishedBySlug')->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(static fn (string $class) => match ($class) {
            Categorie::class => $categoryRepo, Module::class => $modules, SeoPage::class => $pages,
        });
        $provider = new SeoSiteLinkProvider($em, $this->router());
        $catalog = $provider->catalog('fr');
        self::assertSame(['/10-formules', '/contact'], array_keys($catalog));
        self::assertSame('Formules', $catalog['/10-formules']['label']);
    }

    public function testDisabledModuleIsNotAValidServiceDestination(): void
    {
        $modules = $this->createMock(ModuleRepository::class);
        $modules->method('findBy')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(Module::class)->willReturn($modules);
        $provider = new SeoSiteLinkProvider($em, $this->router());
        self::assertSame([], $provider->catalog('fr', '/contact'));
    }

    public function testLegacyServiceLinkNeedsNoExtraQueries(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');
        $provider = new SeoSiteLinkProvider($em, $this->router());
        $page = (new SeoPage())->setSeed((new SeoSeed())->setServicePageUrl('/contact'))
            ->setInternalLinks([['url' => 'https://example.test/contact', 'label' => 'Contact']]);
        self::assertSame([], $provider->forPage($page));
    }

    public function testExistingPageSlugAndLegacySectionsSurviveIncompleteOptimization(): void
    {
        $generator = (new ReflectionClass(App\Service\ClaudeSeoGenerator::class))->newInstanceWithoutConstructor();
        $scorer = $this->createMock(App\Service\SeoQualityScorer::class);
        $scorer->method('score')->willReturn(['score' => 80, 'flags' => [], 'missing_data' => [], 'indexable' => true]);
        (new ReflectionProperty($generator, 'qualityScorer'))->setValue($generator, $scorer);
        (new ReflectionProperty($generator, 'siteLinks'))->setValue($generator, new SeoSiteLinkProvider($this->createMock(EntityManagerInterface::class), $this->router()));
        $seed = (new SeoSeed())->setMainKeyword('Demenagement Lille')->setCity('Lille');
        $sections = [['h2' => 'Titre historique', 'body' => 'Contenu historique']];
        $faq = [['question' => 'Question historique ?', 'answer' => 'Reponse historique']];
        $page = (new SeoPage())->setSeed($seed)->setSlug('url-publiee-a-conserver')->setContent($sections)->setFaq($faq);
        (new ReflectionProperty($page, 'id'))->setValue($page, 42);
        $result = (new ReflectionMethod($generator, 'hydratePage'))->invoke($generator, $seed, ['slug' => 'ne-pas-utiliser', 'sections' => [], 'faq' => []], $page, []);
        self::assertSame('url-publiee-a-conserver', $result->getSlug());
        self::assertSame($sections, $result->getContent());
        self::assertSame($faq, $result->getFaq());
        self::assertSame(SeoPage::STATUS_REVIEW, $result->getStatus());
        self::assertFalse($result->isIndexable());
    }

    /** @dataProvider previewModes */
    public function testFullTwigRenderingUsesH3AndEscapesContextualLinks(bool $preview): void
    {
        $page = (new SeoPage())->setSeed((new SeoSeed())->setCity('Lille')->setService('Demenagement'))
            ->setMainKeyword('Demenagement Lille')->setSlug('demenagement-lille')->setH1('Demenagement a Lille')
            ->setContent([
                ['h2' => 'Preparer le projet', 'body' => 'Prevoir une isolation thermique avec la peinture permet de coordonner les travaux.'],
                ['h2' => 'Choisir une formule', 'body' => 'Les options verifiees.'],
                ['h2' => 'Organiser la demande', 'body' => 'Les informations necessaires.'],
            ]);
        $target = (new SeoPage())->setSlug('demenagement-roubaix')->setMainKeyword('Demenagement Roubaix');
        (new ReflectionProperty($target, 'id'))->setValue($target, 8);
        $provider = $this->createMock(SeoSiteLinkProvider::class);
        $provider->method('forPage')->willReturn([
            ['url' => '/10-formules', 'label' => '<img src=x onerror=alert(1)>Formules', 'section' => 2, 'context' => '<script>alert(1)</script>Comparez'],
            ['url' => '/contact', 'label' => 'Contact'],
            ['url' => '/isolation', 'label' => 'Isolation', 'section' => 1, 'placement' => 'inline', 'anchor' => 'isolation thermique'],
            ['url' => '/electricite', 'label' => 'Travaux electriques', 'section' => 3, 'placement' => 'cta', 'cta_label' => 'Decouvrir les travaux electriques'],
        ]);
        $loader = new Twig\Loader\ChainLoader([
            new Twig\Loader\ArrayLoader(['base.html.twig' => '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{margin:0;font-family:Arial,sans-serif}button{font:inherit}</style>{% block stylesheets %}{% endblock %}</head><body>{% block body %}{% endblock %}</body></html>']),
            new Twig\Loader\FilesystemLoader(dirname(__DIR__) . '/files/templates'),
        ]);
        $twig = new Twig\Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new SeoProgrammaticExtension($provider));
        $twig->addExtension(new App\Twig\StructuredDataExtension(new App\Service\OpeningHoursNormalizer()));
        $twig->addFunction(new Twig\TwigFunction('config', static fn () => ['raisonSociale' => 'Entreprise de test', 'lienContact' => '/contact']));
        $twig->addFunction(new Twig\TwigFunction('coordonnees', static fn () => ['ville' => 'Lille', 'tel1' => '']));
        $twig->addFunction(new Twig\TwigFunction('designFront', static fn () => ['logo' => ['logo' => 'test.png']]));
        $twig->addFunction(new Twig\TwigFunction('pathImage', static fn () => new Doctrine\Common\Collections\ArrayCollection(['elementFront_images' => 'images'])));
        $twig->addFunction(new Twig\TwigFunction('asset', static fn (string $path) => $path));
        $twig->addFunction(new Twig\TwigFunction('absolute_url', static fn (string $path) => 'https://example.test/' . ltrim($path, '/')));
        $twig->addFunction(new Twig\TwigFunction('path', static fn (string $name, array $args = []) => $name === 'admin_seo_page_preview' ? '/admin/seo-page/' . $args['id'] . '/preview' : '/' . ($args['slug'] ?? '')));
        $html = $twig->render('pages/seo_programmatic/show.html.twig', ['page' => $page, 'relatedPages' => [$target], 'preview' => $preview]);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        self::assertSame(3, $xpath->query('//article[contains(@class,"seo-content-card")]/h3')->length);
        self::assertSame(0, $xpath->query('//article[contains(@class,"seo-content-card")]/h2')->length);
        self::assertSame(1, $xpath->query('//article[@id="section-2"]//a[@href="/10-formules"]')->length);
        self::assertSame(1, $xpath->query('//article[@id="section-1"]/p/a[@href="/isolation"]')->length);
        self::assertSame(1, $xpath->query('//article[@id="section-3"]//a[@class="seo-section-cta"]')->length);
        self::assertSame(0, $xpath->query('//article//script | //article//img[@onerror]')->length);
        self::assertStringContainsString('href="' . ($preview ? '/admin/seo-page/8/preview' : '/demenagement-roubaix') . '"', $html);
        self::assertStringNotContainsString('Présence locale à', $html);
        file_put_contents(__DIR__ . '/rendered-' . ($preview ? 'preview' : 'public') . '.html', $html);
    }

    public static function previewModes(): array
    {
        return [[false], [true]];
    }

    public function testCliOriginDoesNotMutateRouterContext(): void
    {
        $before = $_ENV['SEO_SITE_URL'] ?? null;
        $_ENV['SEO_SITE_URL'] = 'https://example.test/cms';
        try {
            $router = $this->router();
            $router->getContext()->setHost('localhost');
            $provider = new SeoSiteLinkProvider($this->createMock(EntityManagerInterface::class), $router);
            self::assertSame('/cms/contact', $provider->normalize('https://example.test/cms/contact'));
            self::assertSame('/cms/contact', $provider->normalize('contact'));
            self::assertNull($provider->normalize('https://external.test/cms/contact'));
            self::assertSame('localhost', $router->getContext()->getHost());
            self::assertSame('', $router->getContext()->getBaseUrl());
        } finally {
            if ($before === null) {
                unset($_ENV['SEO_SITE_URL']);
            } else {
                $_ENV['SEO_SITE_URL'] = $before;
            }
        }
    }

    public function testNewServicesCompileWithSymfonyAutowiring(): void
    {
        $container = new Symfony\Component\DependencyInjection\ContainerBuilder();
        foreach ([EntityManagerInterface::class, RouterInterface::class, SeoPageRepository::class, SeoFactRepository::class,
            SeoPromptTemplateRepository::class, ConfigAdminRepository::class, CoordonneeRepository::class,
            Symfony\Contracts\HttpClient\HttpClientInterface::class, App\Service\SeoPageImageResolver::class] as $dependency) {
            $container->register($dependency, $dependency)->setSynthetic(true)->setPublic(true);
        }
        foreach ([SeoSiteLinkProvider::class, SeoEditorialAdvisor::class, SeoPromptBuilder::class,
            App\Service\SeoQualityScorer::class, App\Service\ClaudeSeoGenerator::class, SeoProgrammaticExtension::class,
            App\Service\OpeningHoursNormalizer::class, App\Twig\StructuredDataExtension::class] as $service) {
            $container->register($service, $service)->setAutowired(true)->setPublic(true);
        }
        $container->compile();
        self::assertTrue($container->hasDefinition(SeoPromptBuilder::class));
        self::assertCount(7, $container->getDefinition(App\Service\ClaudeSeoGenerator::class)->getArguments());
    }

    public function testNewDqlExpressionsCompileAgainstCmsMappings(): void
    {
        $cmsRoot = getenv('PSEO_CMS_DIR') ?: dirname(__DIR__, 4) . '/cms';
        $config = Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__) . '/files/src/Entity', $cmsRoot . '/src/Entity'], true);
        $events = new Doctrine\Common\EventManager();
        $localeProvider = $this->createMock(Knp\DoctrineBehaviors\Contract\Provider\LocaleProviderInterface::class);
        $events->addEventSubscriber(new Knp\DoctrineBehaviors\EventSubscriber\TranslatableEventSubscriber($localeProvider, 'LAZY', 'LAZY'));
        $em = Doctrine\ORM\EntityManager::create(['driver' => 'pdo_sqlite', 'memory' => true], $config, $events);
        $queries = [
            'SELECT p, s FROM App\\Entity\\SeoPage p LEFT JOIN p.seed s WHERE p.status IN (:statuses) ORDER BY p.updated_at DESC, p.id DESC',
            'SELECT c, t FROM App\\Entity\\Categorie c LEFT JOIN c.translations t WHERE c.valid = :valid AND c.clickable = :valid ORDER BY c.position ASC',
            'SELECT IDENTITY(a.categorie) AS categoryId, SUBSTRING(COALESCE(t.content, a.content), 1, 1200) AS excerpt FROM App\\Entity\\Article a LEFT JOIN a.translations t WITH t.locale = :locale WHERE a.valid = :valid AND a.categorie IN (:categories) ORDER BY a.position ASC',
            'SELECT p, s, CASE WHEN s.service = :comparisonService THEN 0 ELSE 1 END AS HIDDEN servicePriority FROM App\\Entity\\SeoPage p LEFT JOIN p.seed s WHERE p.locale = :locale ORDER BY servicePriority ASC, p.updated_at DESC, p.id DESC',
        ];
        foreach ($queries as $dql) {
            self::assertNotEmpty($em->createQuery($dql)->getSQL());
        }
    }
}
