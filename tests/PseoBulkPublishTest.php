<?php

use App\Controller\Admin\DashboardController;
use App\Controller\Admin\SeoPageCrudController;
use App\Controller\Admin\SeoWorkflowController;
use App\Entity\Module;
use App\Entity\SeoPage;
use App\Repository\ModuleRepository;
use App\Repository\SeoPageRepository;
use App\Service\SeoQualityScorer;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\MainMenuDto;
use EasyCorp\Bundle\EasyAdminBundle\EventListener\AdminRouterSubscriber;
use EasyCorp\Bundle\EasyAdminBundle\Factory\AdminContextFactory;
use EasyCorp\Bundle\EasyAdminBundle\Factory\ControllerFactory;
use EasyCorp\Bundle\EasyAdminBundle\Factory\EntityFactory;
use EasyCorp\Bundle\EasyAdminBundle\Factory\MenuFactory;
use EasyCorp\Bundle\EasyAdminBundle\Provider\AdminContextProvider;
use EasyCorp\Bundle\EasyAdminBundle\Registry\CrudControllerRegistry;
use EasyCorp\Bundle\EasyAdminBundle\Registry\DashboardControllerRegistry;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Twig\EasyAdminTwigExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\AppVariable;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ContainerControllerResolver;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class PseoBulkDashboard extends AbstractDashboardController
{
    public function index(): Response
    {
        return new Response('Dashboard fixture');
    }
}

final class PseoBulkPublishTest extends TestCase
{
    private const DIRECT_URL = 'https://example.test/admin/seo-page/bulk-publish';
    private HttpKernel $kernel;
    private Session $session;
    private Environment $twig;
    private array $pages = [];
    private int $flushes = 0;
    private bool $allowed = true;
    private bool $moduleEnabled = true;
    private array $recalculatedScores = [];

    protected function setUp(): void
    {
        $stack = new RequestStack();
        $this->session = new Session(new MockArraySessionStorage());
        $context = new RequestContext('', 'GET', 'example.test', 'https');
        $routes = new RouteCollection();
        $routes->add('admin', new Route('/admin', ['_controller' => PseoBulkDashboard::class . '::index']));
        $route = (new ReflectionMethod(SeoWorkflowController::class, 'bulkPublish'))
            ->getAttributes(Symfony\Component\Routing\Annotation\Route::class)[0]->newInstance();
        $routes->add($route->getName(), new Route($route->getPath(), [
            '_controller' => SeoWorkflowController::class . '::bulkPublish',
        ], [], [], '', [], $route->getMethods()));
        $routes->add('admin_seo_page_preview', new Route('/admin/seo-page/{id}/preview'));
        $routes->add('admin_seo_page_publish', new Route('/admin/seo-page/{id}/publish', ['_controller' => SeoWorkflowController::class . '::publish'], [], [], '', [], ['POST']));
        $generator = new UrlGenerator($routes, $context);
        $matcher = new UrlMatcher($routes, $context);
        $provider = new AdminContextProvider($stack);
        $registry = new DashboardControllerRegistry(__DIR__ . '/fixtures', [], []);
        // Bind the CMS dashboard name to our database-free dashboard fixture.
        (new ReflectionProperty($registry, 'controllerFqcnToRouteMap'))->setValue($registry, [
            DashboardController::class => 'admin', PseoBulkDashboard::class => 'admin',
        ]);
        $adminUrls = new AdminUrlGenerator($provider, $generator, $registry);

        $repository = $this->createMock(SeoPageRepository::class);
        $repository->method('findBy')->willReturnCallback(function (array $criteria): array {
            return array_values(array_filter($this->pages, static function (SeoPage $page) use ($criteria): bool {
                return isset($criteria['id'])
                    ? in_array($page->getId(), $criteria['id'], true)
                    : in_array($page->getStatus(), $criteria['status'], true);
            }));
        });
        $repository->method('findForBulkPublication')->willReturnCallback(fn (): array => array_values(array_filter($this->pages,
            static fn (SeoPage $page): bool => in_array($page->getStatus(), [SeoPage::STATUS_DRAFT, SeoPage::STATUS_REVIEW], true))));
        $modules = $this->createMock(ModuleRepository::class);
        $modules->method('findOneBy')->willReturnCallback(fn () => $this->moduleEnabled ? new Module() : null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(SeoPage::class)->willReturn($repository);
        $em->method('flush')->willReturnCallback(function (): void { ++$this->flushes; });
        $scorer = $this->createMock(SeoQualityScorer::class);
        $scorer->method('scorePage')->willReturnCallback(fn (SeoPage $page): array => [
            'score' => $this->recalculatedScores[$page->getId()] ?? $page->getQualityScore(), 'flags' => [],
        ]);
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->with('m_edit', SeoPage::class)->willReturnCallback(fn () => $this->allowed);
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool =>
            in_array($token->getId(), ['seo_page_bulk_publish', 'seo_page_publish'], true) && $token->getValue() === 'test-token'
        );
        $container = new Container();
        $container->set('router', $generator);
        $container->set('request_stack', $stack);
        $container->set('security.authorization_checker', $authorization);
        $container->set('security.csrf.token_manager', $csrf);
        $controller = new SeoWorkflowController($em, $adminUrls, $repository, $modules, $scorer);
        $controller->setContainer($container);
        $container->set(SeoWorkflowController::class, $controller);
        $container->set(PseoBulkDashboard::class, new PseoBulkDashboard());

        $loader = new FilesystemLoader(dirname(__DIR__) . '/files/templates');
        $loader->addPath(__DIR__ . '/vendor/easycorp/easyadmin-bundle/src/Resources/views', 'EasyAdmin');
        $this->twig = new Environment($loader, ['strict_variables' => true]);
        $this->twig->addExtension(new TranslationExtension(new Translator('fr')));
        $this->twig->addExtension(new EasyAdminTwigExtension(new ServiceLocator([
            AdminUrlGenerator::class => static fn () => $adminUrls,
        ]), $provider, $csrf));
        $this->twig->addFunction(new TwigFunction('asset', static fn (string $path) => '/assets/' . $path));
        $this->twig->addFunction(new TwigFunction('path', [$generator, 'generate']));
        $this->twig->addFunction(new TwigFunction('csrf_token', static fn () => 'test-token'));
        $this->twig->addFunction(new TwigFunction('is_granted', static fn () => false));
        $app = new AppVariable();
        $app->setRequestStack($stack);
        $this->twig->addGlobal('app', $app);
        $container->set('twig', $this->twig);

        // CRUD/entity loading and sidebar queries are outside this route's scope.
        $factory = new AdminContextFactory(__DIR__ . '/fixtures', null,
            (new ReflectionClass(MenuFactory::class))->newInstanceWithoutConstructor(),
            new CrudControllerRegistry([], [], [], []),
            (new ReflectionClass(EntityFactory::class))->newInstanceWithoutConstructor()
        );
        $resolver = new ContainerControllerResolver($container);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new RouterListener($matcher, $stack, $context));
        $subscriber = new AdminRouterSubscriber($factory, new ControllerFactory($resolver), $resolver, $generator, $matcher);
        // FrameworkBundle normally registers these event-class aliases.
        $dispatcher->addListener('kernel.request', [$subscriber, 'onKernelRequest']);
        $dispatcher->addListener('kernel.controller', [$subscriber, 'onKernelController'], 128);
        $dispatcher->addListener('kernel.request', static function (RequestEvent $event): void {
            if ($ea = $event->getRequest()->attributes->get(EA::CONTEXT_REQUEST_ATTRIBUTE)) {
                (new ReflectionProperty($ea, 'mainMenuDto'))->setValue($ea, new MainMenuDto([]));
            }
        }, -1);
        $this->kernel = new HttpKernel($dispatcher, $resolver, $stack, new ArgumentResolver());
    }

    private function request(string $url, string $method = 'GET', array $data = [], array $attributes = []): Response
    {
        $request = Request::create($url, $method, $data);
        $request->attributes->add($attributes);
        $request->setSession($this->session);
        $this->twig->resetGlobals();
        return $this->kernel->handle($request, HttpKernel::MAIN_REQUEST, false);
    }

    private function page(int $id, int $score = 85, string $status = SeoPage::STATUS_DRAFT): SeoPage
    {
        $page = (new SeoPage())->setMainKeyword('Service Lille')->setSlug('service-lille')
            ->setQualityScore($score)->setStatus($status)->setIndexable(false);
        (new ReflectionProperty($page, 'id'))->setValue($page, $id);
        $this->pages[] = $page;
        return $page;
    }

    private function assertAdminRedirect(Response $response, int $status): string
    {
        self::assertSame($status, $response->getStatusCode());
        $location = $response->headers->get('Location');
        self::assertSame('/admin', parse_url($location, PHP_URL_PATH));
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('admin_seo_page_bulk_publish', $query[EA::ROUTE_NAME] ?? null);
        self::assertArrayNotHasKey(EA::CRUD_CONTROLLER_FQCN, $query);
        return $location;
    }

    public function testDirectGetRecoversContextAndRendersTheRealEasyAdminTemplate(): void
    {
        $this->page(1);
        $this->page(2, 60);
        $url = $this->assertAdminRedirect($this->request(self::DIRECT_URL), 302);
        $response = $this->request($url);
        self::assertSame(200, $response->getStatusCode());
        file_put_contents(__DIR__ . '/rendered-bulk.html', $response->getContent());
        self::assertStringContainsString('seo-bulk-publish-form', $response->getContent());
        self::assertStringContainsString('1 page(s)', $response->getContent());
        $dom = new DOMDocument();
        @$dom->loadHTML($response->getContent());
        $xpath = new DOMXPath($dom);
        self::assertSame('Publication SEO en masse', trim($xpath->evaluate('string(//title)')));
        self::assertSame('Publication SEO en masse', trim($xpath->evaluate('string(//h1)')));
        $action = $xpath->evaluate('string(//form[@id="seo-bulk-publish-form"]/@action)');
        self::assertStringContainsString('routeName=admin_seo_page_bulk_publish', $action);
        $returnUrl = $xpath->evaluate('string(//a[contains(.,"Retour aux pages SEO")]/@href)');
        parse_str(parse_url($returnUrl, PHP_URL_QUERY), $query);
        self::assertSame(SeoPageCrudController::class, $query[EA::CRUD_CONTROLLER_FQCN]);
        self::assertSame('index', $query[EA::CRUD_ACTION]);
        self::assertArrayNotHasKey(EA::ROUTE_NAME, $query);
        self::assertSame(0, $this->flushes);
    }

    /** @dataProvider entryPoints */
    public function testPublicationRedirectsToAWorkingEmptyScreenWithoutReposting(bool $direct): void
    {
        $page = $this->page(1);
        $url = $direct ? self::DIRECT_URL : 'https://example.test/admin?routeName=admin_seo_page_bulk_publish';
        $response = $this->request($url, 'POST', ['_token' => 'test-token', 'page_ids' => ['1', '1']]);
        self::assertSame(SeoPage::STATUS_PUBLISHED, $page->getStatus());
        self::assertTrue($page->isIndexable());
        $returnUrl = $this->assertAdminRedirect($response, 303);
        $flushes = $this->flushes;
        $html = $this->request($returnUrl)->getContent();
        file_put_contents(__DIR__ . '/rendered-bulk-empty.html', $html);
        self::assertStringContainsString('Aucune page en brouillon', $html);
        self::assertStringContainsString('1 page(s) publi', $html);
        self::assertStringNotContainsString('id="seo-bulk-publish-form"', $html);
        self::assertSame($flushes, $this->flushes);
    }

    public static function entryPoints(): array
    {
        return [[true], [false]];
    }

    /** @dataProvider invalidSubmissions */
    public function testInvalidOrEmptySubmissionReturnsToTheWorkingScreen(array $data): void
    {
        $page = $this->page(1);
        $url = $this->assertAdminRedirect($this->request(self::DIRECT_URL, 'POST', $data), 303);
        self::assertSame(200, $this->request($url)->getStatusCode());
        self::assertSame(SeoPage::STATUS_DRAFT, $page->getStatus());
        self::assertSame(0, $this->flushes);
    }

    public static function invalidSubmissions(): array
    {
        return [[['_token' => 'invalid', 'page_ids' => ['1']]], [['_token' => 'invalid', 'reviewed_page_ids' => ['1']]], [['_token' => 'test-token']]];
    }

    public function testDirectHeadRecoversContextWithoutPublishing(): void
    {
        $this->assertAdminRedirect($this->request(self::DIRECT_URL, 'HEAD'), 302);
        self::assertSame(0, $this->flushes);
    }

    public function testMixedSelectionPublishesOnlyEligiblePagesAndEscapesWarnings(): void
    {
        $good = $this->page(1);
        $bad = $this->page(2, 60)->setMainKeyword('<script>alert(1)</script>');
        $url = $this->assertAdminRedirect($this->request(self::DIRECT_URL, 'POST', [
            '_token' => 'test-token', 'page_ids' => ['1', '2'],
        ]), 303);
        self::assertSame(SeoPage::STATUS_PUBLISHED, $good->getStatus());
        self::assertSame(SeoPage::STATUS_DRAFT, $bad->getStatus());
        $this->session->getFlashBag()->add('warning', '<script>alert(1)</script>');
        $html = $this->request($url)->getContent();
        self::assertStringContainsString('1 page(s) publi', $html);
        self::assertStringContainsString('1 page(s) ignor', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testIneligibleAndAlreadyPublishedPagesAreNotPublishedAgain(): void
    {
        $weak = $this->page(1, 60);
        $missing = $this->page(2)->setMissingData(['Faits locaux insuffisants']);
        $published = $this->page(3, 85, SeoPage::STATUS_PUBLISHED)->setIndexable(true);
        $archived = $this->page(4, 85, SeoPage::STATUS_ARCHIVED);
        $url = $this->assertAdminRedirect($this->request(self::DIRECT_URL, 'POST', [
            '_token' => 'test-token', 'page_ids' => ['1', '2', '3', '4'],
        ]), 303);
        self::assertSame(200, $this->request($url)->getStatusCode());
        self::assertSame(SeoPage::STATUS_DRAFT, $weak->getStatus());
        self::assertSame(SeoPage::STATUS_DRAFT, $missing->getStatus());
        self::assertNull($published->getPublishedAt());
        self::assertSame(SeoPage::STATUS_ARCHIVED, $archived->getStatus());
    }

    public function testNoEditPermissionStillDeniesAccess(): void
    {
        $this->allowed = false;
        $this->expectException(AccessDeniedException::class);
        $this->request(self::DIRECT_URL);
    }

    public function testOptionalLocalDetailsStayVisibleAndDoNotPreventBulkPublication(): void
    {
        $page = $this->page(1, 100)->setMissingData(["Aucun fait local documenté sur le parc de chauffage à Valenciennes"]);
        $this->recalculatedScores[1] = 90;
        $url = $this->assertAdminRedirect($this->request(self::DIRECT_URL), 302);
        $html = $this->request($url)->getContent();
        self::assertStringContainsString('90/100', $html);
        self::assertStringContainsString('Éligible, à vérifier', $html);
        self::assertStringContainsString('parc de chauffage', $html);
        self::assertSame(100, $page->getQualityScore());
        self::assertSame(0, $this->flushes);
        file_put_contents(__DIR__ . '/rendered-bulk-advisories.html', $html);
        $this->request(self::DIRECT_URL, 'POST', ['_token' => 'test-token', 'page_ids' => ['1']]);
        self::assertSame(SeoPage::STATUS_PUBLISHED, $page->getStatus());
        self::assertSame(90, $page->getQualityScore());
        self::assertNotEmpty($page->getMissingData());
        self::assertNotEmpty($this->session->getFlashBag()->peek('warning'));
    }

    public function testCriticalReasonIsNotCancelledByAnOptionalWordInTheSameLine(): void
    {
        $page = $this->page(1, 100)->setMissingData(['Service non confirmé, tarif non communiqué']);
        $this->request(self::DIRECT_URL, 'POST', ['_token' => 'test-token', 'page_ids' => ['1']]);
        self::assertSame(SeoPage::STATUS_DRAFT, $page->getStatus());
        self::assertFalse($page->isIndexable());
    }

    /** @dataProvider individualIssues */
    public function testIndividualPublicationUsesTheSameRules(string $issue, bool $publish): void
    {
        $page = $this->page(1, 90)->setMissingData([$issue]);
        $response = $this->request('https://example.test/admin/seo-page/1/publish', 'POST', ['_token' => 'test-token'], ['page' => $page]);
        self::assertTrue($response->isRedirect());
        self::assertSame($publish ? SeoPage::STATUS_PUBLISHED : SeoPage::STATUS_DRAFT, $page->getStatus());
        self::assertSame($publish, $page->isIndexable());
    }

    public static function individualIssues(): array
    {
        return [["Faits locaux insuffisants sur l'état des toitures", true], ['Faits locaux insuffisants', false], ['[amelioration] Information inventée dans le texte', false]];
    }

    public function testListUsesCurrentScoreWithoutChangingStoredPage(): void
    {
        $page = $this->page(1, 100);
        $this->recalculatedScores[1] = 70;
        $url = $this->assertAdminRedirect($this->request(self::DIRECT_URL), 302);
        $html = $this->request($url)->getContent();
        self::assertStringContainsString('70/100', $html);
        self::assertStringContainsString('Bloquée', $html);
        self::assertSame(100, $page->getQualityScore());
        self::assertSame(0, $this->flushes);
    }

    public function testReviewRowsAreSelectableButNotPreselected(): void
    {
        $this->page(1);
        $review = $this->page(2)->setMissingData(['Faits locaux insuffisants <script>alert(1)</script>']);
        $url = $this->assertAdminRedirect($this->request(self::DIRECT_URL), 302);
        $html = $this->request($url)->getContent();
        file_put_contents(__DIR__ . '/rendered-bulk-review.html', $html);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        self::assertSame(1, $xpath->query('//input[@name="reviewed_page_ids[]" and @value="2" and not(@checked) and not(@disabled)]')->length);
        self::assertStringContainsString('Relecture nécessaire', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertSame(0, $this->flushes);
        self::assertSame(SeoPage::STATUS_DRAFT, $review->getStatus());
    }

    public function testBulkReviewNeedsExplicitSelectionAndKeepsItsAlerts(): void
    {
        $page = $this->page(1)->setMissingData(['Faits locaux insuffisants']);
        $this->request(self::DIRECT_URL, 'POST', ['_token' => 'test-token', 'page_ids' => ['1']]);
        self::assertSame(SeoPage::STATUS_DRAFT, $page->getStatus());
        self::assertFalse($page->isIndexable());
        $this->request(self::DIRECT_URL, 'POST', ['_token' => 'test-token', 'reviewed_page_ids' => ['1']]);
        self::assertSame(SeoPage::STATUS_PUBLISHED, $page->getStatus());
        self::assertTrue($page->isIndexable());
        self::assertSame(['Faits locaux insuffisants'], $page->getMissingData());
    }

    public function testOnlyReviewPagesStartWithAnEmptySelection(): void
    {
        $this->page(1)->setMissingData(['Faits locaux insuffisants']);
        $url = $this->assertAdminRedirect($this->request(self::DIRECT_URL), 302);
        $html = $this->request($url)->getContent();
        file_put_contents(__DIR__ . '/rendered-bulk-review-only.html', $html);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        self::assertSame('0', $xpath->evaluate('string(//span[@id="seo-selected-count"])'));
        self::assertSame(1, $xpath->query('//input[@id="seo-select-all-eligible" and @disabled]')->length);
        self::assertSame(1, $xpath->query('//input[@name="reviewed_page_ids[]" and not(@disabled) and not(@checked)]')->length);
    }

    public function testReviewConfirmationCannotOverrideCriticalOrScoreBlocks(): void
    {
        $critical = $this->page(1)->setMissingData(['Faits locaux insuffisants', 'Service non confirmé']);
        $weak = $this->page(2, 60)->setMissingData(['Page trop générique']);
        $this->request(self::DIRECT_URL, 'POST', ['_token' => 'test-token', 'reviewed_page_ids' => ['1', '2']]);
        self::assertSame(SeoPage::STATUS_DRAFT, $critical->getStatus());
        self::assertSame(SeoPage::STATUS_DRAFT, $weak->getStatus());
        self::assertFalse($critical->isIndexable());
        self::assertFalse($weak->isIndexable());
    }

    public function testIndividualReviewCanBeConfirmedWithoutErasingTheAlert(): void
    {
        $page = $this->page(1)->setMissingData(['Page trop générique']);
        $url = 'https://example.test/admin/seo-page/1/publish';
        $this->request($url, 'POST', ['_token' => 'test-token'], ['page' => $page]);
        self::assertSame(SeoPage::STATUS_DRAFT, $page->getStatus());
        $this->request($url, 'POST', ['_token' => 'test-token', 'editorial_review_confirmed' => '1'], ['page' => $page]);
        self::assertSame(SeoPage::STATUS_PUBLISHED, $page->getStatus());
        self::assertSame(['Page trop générique'], $page->getMissingData());
    }

    public function testIndividualConfirmationCannotBypassTruthOrCsrf(): void
    {
        $page = $this->page(1)->setMissingData(['[relecture] Information inventée dans le texte']);
        $url = 'https://example.test/admin/seo-page/1/publish';
        $this->request($url, 'POST', ['_token' => 'test-token', 'editorial_review_confirmed' => '1'], ['page' => $page]);
        self::assertSame(SeoPage::STATUS_DRAFT, $page->getStatus());
        $this->expectException(AccessDeniedException::class);
        $this->request($url, 'POST', ['_token' => 'invalid', 'editorial_review_confirmed' => '1'], ['page' => $page]);
    }

    public function testInactiveModuleStillReturnsNotFound(): void
    {
        $this->moduleEnabled = false;
        $this->expectException(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $this->request(self::DIRECT_URL);
    }
}
