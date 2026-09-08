<?php

namespace App\Controller\Admin;

use App\Entity\SeoPage;
use App\Entity\SeoSeed;
use App\Repository\ModuleRepository;
use App\Repository\SeoPageRepository;
use App\Repository\SeoSeedRepository;
use App\Service\ClaudeSeoGenerator;
use App\Service\SeoPageImageResolver;
use App\Service\SeoQualityScorer;
use App\Service\SeoPublicationPolicy;
use App\Service\SeoSeedExpander;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SeoWorkflowController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AdminUrlGenerator $adminUrlGenerator,
        private SeoPageRepository $seoPageRepository,
        private ModuleRepository $moduleRepository,
        private SeoQualityScorer $qualityScorer
    ) {
    }

    #[Route('/admin/seo-seed/{id}/generate', name: 'admin_seo_seed_generate', methods: ['POST'])]
    public function generate(SeoSeed $seed, ClaudeSeoGenerator $generator, Request $request): Response
    {
        $this->assertSeedGenerationAccess();
        $this->assertCsrf($request, 'seo_seed_generate');

        if ($existingPage = $this->seoPageRepository->findActiveForSeed($seed)) {
            $this->addFlash('warning', 'Une page existe deja pour ce seed. Utilise le bouton Optimiser depuis la page SEO.');

            return $this->redirectToSeoPage($existingPage);
        }

        $seed->refreshDataCompletenessScore();
        $this->entityManager->flush();

        $modelPreference = $this->getModelPreferenceFromRequest($request);
        $model = $generator->resolveModelForSeed($seed, $modelPreference);
        $page = $generator->generate($seed, $modelPreference);

        if ($page->getQualityScore() >= 75) {
            $this->addFlash('success', sprintf('Page SEO generee avec %s. Elle est prete pour relecture avant publication.', $model));
        } else {
            $this->addFlash('warning', sprintf('Page SEO generee avec %s en brouillon: donnees insuffisantes ou controle qualite a revoir.', $model));
        }

        return $this->redirect($this->adminUrlGenerator
            ->setController(SeoPageCrudController::class)
            ->setAction('edit')
            ->setEntityId($page->getId())
            ->generateUrl());
    }

    #[Route('/admin/seo-seed/{id}/generate-keyword-pages', name: 'admin_seo_seed_generate_keyword_pages', methods: ['POST'])]
    public function generateKeywordPages(SeoSeed $seed, SeoSeedExpander $seedExpander, ClaudeSeoGenerator $generator, Request $request): Response
    {
        $this->assertSeedGenerationAccess();
        $this->assertCsrf($request, 'seo_seed_generate_keyword_pages');

        $seed->refreshDataCompletenessScore();
        $this->entityManager->flush();

        $items = $seedExpander->createOrFindSeedsFromPageKeywords($seed);

        if (!$items) {
            $this->addFlash('warning', 'Aucun mot cle page a generer sur ce seed.');

            return $this->redirectToSeed($seed);
        }

        $modelPreference = $this->getModelPreferenceFromRequest($request);
        $created = 0;
        $updated = 0;
        $generated = 0;
        $regenerated = 0;
        $skipped = 0;
        $errors = 0;
        $archivedMalformed = $seedExpander->getLastArchivedMalformedCount();

        if ($seedExpander->findPageForSeed($seed)) {
            $skipped++;
        } else {
            try {
                $generator->generate($seed, $modelPreference);
                $generated++;
            } catch (\Throwable) {
                $errors++;
            }
        }

        foreach ($items as $item) {
            $childSeed = $item['seed'];
            $created += $item['created'] ? 1 : 0;
            $updated += (!$item['created'] && ($item['updated'] ?? false)) ? 1 : 0;

            $existingPage = $seedExpander->findPageForSeed($childSeed);

            if ($existingPage) {
                if ($item['updated'] ?? false) {
                    try {
                        $generator->improvePage($existingPage, $modelPreference);
                        $regenerated++;
                    } catch (\Throwable) {
                        $errors++;
                    }

                    continue;
                }

                $skipped++;
                continue;
            }

            try {
                $generator->generate($childSeed, $modelPreference);
                $generated++;
            } catch (\Throwable) {
                $errors++;
            }
        }

        if ($generated > 0 || $regenerated > 0 || $updated > 0 || $archivedMalformed > 0) {
            $this->addFlash('success', sprintf('%d seed(s) cree(s), %d seed(s) corrige(s), %d seed(s) combine(s) archive(s), %d page(s) generee(s), %d page(s) regeneree(s), %d deja existante(s).', $created, $updated, $archivedMalformed, $generated, $regenerated, $skipped));
        } else {
            $this->addFlash('warning', sprintf('%d seed(s) cree(s), aucune nouvelle page generee, %d deja existante(s).', $created, $skipped));
        }

        if ($errors > 0) {
            $this->addFlash('danger', sprintf('%d generation(s) ont echoue. Voir Historique Claude.', $errors));
        }

        return $this->redirect($this->adminUrlGenerator
            ->setController(SeoPageCrudController::class)
            ->setAction('index')
            ->generateUrl());
    }

    #[Route('/admin/seo-seed/generate-batch', name: 'admin_seo_seed_generate_batch', methods: ['POST'])]
    public function generateBatch(SeoSeedRepository $seedRepository, SeoSeedExpander $seedExpander, Request $request): Response
    {
        $this->assertSeedGenerationAccess();
        $this->assertCsrf($request, 'seo_seed_generate_batch_setup');

        $itemsBySeedId = [];
        $createdChildren = 0;
        $updatedChildren = 0;
        $archivedMalformed = 0;

        foreach ($seedRepository->findReadyForGeneration(0) as $sourceSeed) {
            if (count($sourceSeed->getPageKeywords()) === 0) {
                continue;
            }

            $expandedItems = $seedExpander->createOrFindSeedsFromPageKeywords($sourceSeed);
            $archivedMalformed += $seedExpander->getLastArchivedMalformedCount();

            if (!$seedExpander->findPageForSeed($sourceSeed)) {
                $this->addSeedToBatch($itemsBySeedId, $sourceSeed, 'generate');
            }

            foreach ($expandedItems as $expandedItem) {
                $childSeed = $expandedItem['seed'];
                $existingPage = $seedExpander->findPageForSeed($childSeed);
                $createdChildren += $expandedItem['created'] ? 1 : 0;
                $updatedChildren += (!$expandedItem['created'] && ($expandedItem['updated'] ?? false)) ? 1 : 0;

                if (!$existingPage) {
                    $this->addSeedToBatch($itemsBySeedId, $childSeed, 'generate');
                } elseif ($expandedItem['updated'] ?? false) {
                    $this->addSeedToBatch($itemsBySeedId, $childSeed, 'improve');
                }
            }
        }

        // Le second passage inclut aussi les seeds autonomes sans page.
        foreach ($seedRepository->findReadyForGeneration(0) as $seed) {
            if (!$seedExpander->findPageForSeed($seed)) {
                $this->addSeedToBatch($itemsBySeedId, $seed, 'generate');
            }
        }

        $items = array_values($itemsBySeedId);
        usort($items, static function (array $left, array $right): int {
            return [$right['priority'], $right['businessValue'], $left['id']]
                <=> [$left['priority'], $left['businessValue'], $right['id']];
        });

        $pageListUrl = $this->adminUrlGenerator
            ->setController(SeoPageCrudController::class)
            ->setAction('index')
            ->generateUrl();

        return $this->render('admin/seo_batch_generate.html.twig', [
            'items' => $items,
            'createdChildren' => $createdChildren,
            'updatedChildren' => $updatedChildren,
            'archivedMalformed' => $archivedMalformed,
            'pageListUrl' => $pageListUrl,
        ]);
    }

    #[Route('/admin/seo-seed/{id}/generate-batch-item', name: 'admin_seo_seed_generate_batch_item', methods: ['POST'])]
    public function generateBatchItem(
        SeoSeed $seed,
        ClaudeSeoGenerator $generator,
        SeoSeedExpander $seedExpander,
        Request $request
    ): JsonResponse {
        $this->assertSeedGenerationAccess();
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload) || !$this->isCsrfTokenValid('seo_seed_generate_batch', $payload['_token'] ?? null)) {
            return $this->json(['ok' => false, 'message' => 'Jeton de sécurité invalide. Recharge la page.'], 403);
        }

        if (!$seed->isValid()) {
            return $this->json(['ok' => false, 'message' => 'Ce seed est inactif.'], 422);
        }

        $mode = ($payload['mode'] ?? null) === 'improve' ? 'improve' : 'generate';
        $seed->refreshDataCompletenessScore();
        $this->entityManager->flush();
        $model = $generator->resolveModelForSeed($seed);

        // L'authentification est deja chargee. Liberer la session permet aux autres
        // requetes du lot de travailler en parallele pendant l'appel a Claude.
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        try {
            $page = $seedExpander->findPageForSeed($seed);

            if ($page && $mode === 'improve') {
                $page = $generator->improvePage($page);
                $status = 'improved';
                $message = 'Page régénérée';
            } elseif ($page) {
                $status = 'skipped';
                $message = 'Page déjà existante';
            } else {
                $page = $generator->generate($seed);
                $status = 'generated';
                $message = 'Page générée';
            }

            return $this->json([
                'ok' => true,
                'status' => $status,
                'message' => $message,
                'keyword' => $seed->getMainKeyword(),
                'model' => $model,
                'qualityScore' => $page->getQualityScore(),
            ]);
        } catch (\Throwable $exception) {
            return $this->json([
                'ok' => false,
                'message' => $exception->getMessage(),
                'keyword' => $seed->getMainKeyword(),
                'model' => $model,
            ], 500);
        }
    }

    #[Route('/admin/seo-page/{id}/improve', name: 'admin_seo_page_improve', methods: ['POST'])]
    #[Route('/admin/seo-page/{id}/improve/{model}', name: 'admin_seo_page_improve_model', methods: ['POST'])]
    public function improve(SeoPage $page, ClaudeSeoGenerator $generator, Request $request): Response
    {
        $this->assertPageEditAccess();
        $this->assertCsrf($request, 'seo_page_improve');

        $seed = $page->getSeed();

        if (!$seed) {
            $this->addFlash('danger', 'Optimisation impossible: cette page n est liee a aucun seed SEO.');

            return $this->redirectToSeoPage($page);
        }

        $modelPreference = $this->getModelPreferenceFromRequest($request);
        $model = $generator->resolveModelForSeed($seed, $modelPreference);

        try {
            $page = $generator->improvePage($page, $modelPreference);
        } catch (\Throwable $exception) {
            $this->addFlash('danger', sprintf('Optimisation avec %s impossible: %s', $model, $exception->getMessage()));

            return $this->redirectToSeoPage($page);
        }

        if ($page->getQualityScore() >= 75) {
            $this->addFlash('success', sprintf('Page SEO optimisee avec %s. Relire avant publication.', $model));
        } else {
            $this->addFlash('warning', sprintf('Page SEO optimisee avec %s, mais elle reste a completer avant publication.', $model));
        }

        return $this->redirectToSeoPage($page);
    }

    #[Route('/admin/seo-page/{id}/publish', name: 'admin_seo_page_publish', methods: ['POST'])]
    public function publish(SeoPage $page, Request $request): Response
    {
        $this->assertPageEditAccess();
        $this->assertCsrf($request, 'seo_page_publish');
        $this->refreshQuality($page);

        if ($page->getQualityScore() < 75) {
            $this->entityManager->flush();
            $this->addFlash('danger', 'Publication refusee: le score qualite doit etre au moins de 75.');

            return $this->redirectToSeoPage($page);
        }

        $blockingMissingData = $this->blockingMissingData($page->getMissingData());

        if (count($blockingMissingData) > 0) {
            $this->entityManager->flush();
            $this->addFlash('danger', sprintf(
                'Publication refusee: %d donnee(s) critique(s) manquante(s). Corrige ou supprime les lignes dans "Donnees manquantes" puis sauvegarde. Canonical forcee vide = OK. Blocages: %s',
                count($blockingMissingData),
                $this->shortMissingDataList($blockingMissingData)
            ));

            return $this->redirectToSeoPage($page);
        }

        $cleanSlug = $this->promoteCleanSlugBeforePublication($page);

        $page
            ->setStatus(SeoPage::STATUS_PUBLISHED)
            ->setIndexable(true)
            ->setPublishedAt(new \DateTimeImmutable());

        $this->entityManager->flush();
        $this->addFlash('success', 'Page SEO publiee et ajoutee au sitemap.');

        if ($cleanSlug) {
            $this->addFlash('success', sprintf('URL SEO nettoyee automatiquement: /%s', $cleanSlug));
        }

        if (count($page->getMissingData()) > 0) {
            $this->addFlash('warning', 'La page est publiee, mais des donnees manquantes non bloquantes restent notees pour amelioration future.');
        }

        return $this->redirectToSeoPage($page);
    }

    #[Route('/admin/seo-page/{id}/unpublish', name: 'admin_seo_page_unpublish', methods: ['POST'])]
    public function unpublish(SeoPage $page, Request $request): Response
    {
        $this->assertPageEditAccess();
        $this->assertCsrf($request, 'seo_page_unpublish');

        $page
            ->setStatus(SeoPage::STATUS_REVIEW)
            ->setIndexable(false);

        $this->entityManager->flush();
        $this->addFlash('success', 'Page SEO retiree de l indexation.');

        return $this->redirectToSeoPage($page);
    }

    #[Route('/admin/seo-page/{id}/preview', name: 'admin_seo_page_preview', methods: ['GET'])]
    public function preview(SeoPage $page): Response
    {
        $this->assertPageEditAccess();

        return $this->render('pages/seo_programmatic/show.html.twig', [
            'page' => $page,
            'relatedPages' => $this->seoPageRepository->findRelatedPublishedPages($page, null, false),
            'preview' => true,
        ]);
    }

    #[Route('/admin/seo-page/{id}/resolve-image', name: 'admin_seo_page_resolve_image', methods: ['POST'])]
    public function resolveImage(SeoPage $page, SeoPageImageResolver $imageResolver, Request $request): Response
    {
        $this->assertPageEditAccess();
        $this->assertCsrf($request, 'seo_page_resolve_image');

        $result = $imageResolver->resolve($page, true);

        if ($result['resolved']) {
            $this->entityManager->flush();
            $this->addFlash('success', $result['message']);
        } else {
            $this->addFlash('warning', $result['message']);
        }

        return $this->redirectToSeoPage($page);
    }

    #[Route('/admin/seo-page/bulk-publish', name: 'admin_seo_page_bulk_publish', methods: ['GET', 'POST'])]
    public function bulkPublish(Request $request): Response
    {
        $this->assertPageEditAccess();
        $bulkPublishUrl = $this->adminUrlGenerator
            ->unsetAll()
            ->setDashboard(DashboardController::class)
            ->setRoute('admin_seo_page_bulk_publish')
            ->generateUrl();

        // EasyAdmin templates require a dashboard context, absent on a direct route.
        if (!$request->isMethod('POST') && null === $request->attributes->get(EA::CONTEXT_REQUEST_ATTRIBUTE)) {
            return $this->redirect($bulkPublishUrl);
        }

        $repository = $this->entityManager->getRepository(SeoPage::class);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('seo_page_bulk_publish', $request->request->get('_token'))) {
                $this->addFlash('danger', 'Jeton de sécurité invalide. Recharge la page.');

                return $this->redirect($bulkPublishUrl, Response::HTTP_SEE_OTHER);
            }

            $formData = $request->request->all();
            $selectedIds = array_values(array_unique(array_filter(array_map(
                static fn (mixed $id): int => (int) $id,
                (array) ($formData['page_ids'] ?? [])
            ))));

            if (!$selectedIds) {
                $this->addFlash('warning', 'Sélectionne au moins une page éligible.');

                return $this->redirect($bulkPublishUrl, Response::HTTP_SEE_OTHER);
            }

            $published = 0;
            $publishedWithAdvisories = 0;
            $blocked = [];
            $cleanedSlugs = 0;

            foreach ($repository->findBy(['id' => $selectedIds]) as $page) {
                $this->refreshQuality($page);

                if (!in_array($page->getStatus(), [SeoPage::STATUS_DRAFT, SeoPage::STATUS_REVIEW], true)) {
                    $blocked[] = sprintf('%s : statut non publiable', $page->getMainKeyword());
                    continue;
                }

                $blockers = $this->bulkPublicationBlockers($page);

                if ($blockers) {
                    $blocked[] = sprintf('%s : %s', $page->getMainKeyword(), implode(', ', $blockers));
                    continue;
                }

                try {
                    $slugWasCleaned = null !== $this->promoteCleanSlugBeforePublication($page);

                    $page
                        ->setStatus(SeoPage::STATUS_PUBLISHED)
                        ->setIndexable(true)
                        ->setPublishedAt(new \DateTimeImmutable());

                    // Chaque slug publie devient visible par la verification suivante.
                    // Cela evite que deux pages du meme lot reservent la meme URL propre.
                    $this->entityManager->flush();
                    $published++;
                    $publishedWithAdvisories += SeoPublicationPolicy::classify($page->getMissingData())['advisory'] !== [] ? 1 : 0;
                    $cleanedSlugs += $slugWasCleaned ? 1 : 0;
                } catch (\Throwable $exception) {
                    $blocked[] = sprintf(
                        '%s : erreur technique (%s)',
                        $page->getMainKeyword() ?: $page->getSlug(),
                        $exception->getMessage()
                    );
                    break;
                }
            }

            $this->entityManager->flush();

            if ($published > 0) {
                $this->addFlash('success', sprintf(
                    '%d page(s) publiée(s) et ajoutée(s) au sitemap%s.',
                    $published,
                    $cleanedSlugs > 0 ? sprintf(', %d URL(s) nettoyée(s)', $cleanedSlugs) : ''
                ));
            }

            if ($publishedWithAdvisories > 0) {
                $this->addFlash('warning', sprintf('%d page(s) publiee(s) conservent des precisions facultatives a verifier.', $publishedWithAdvisories));
            }

            if ($blocked) {
                $this->addFlash('warning', sprintf(
                    '%d page(s) ignorée(s) : %s',
                    count($blocked),
                    implode(' / ', array_slice($blocked, 0, 5)) . (count($blocked) > 5 ? ' / ...' : '')
                ));
            }

            return $this->redirect($bulkPublishUrl, Response::HTTP_SEE_OTHER);
        }

        $pages = $repository->findForBulkPublication();
        $rows = [];
        $eligibleCount = 0;

        foreach ($pages as $page) {
            // Recalculate for display only, without writes or per-page comparison queries.
            $quality = $this->qualityScorer->scorePage($page, false);
            $blockers = $this->bulkPublicationBlockers($page, $quality['score']);
            $eligible = count($blockers) === 0;
            $eligibleCount += $eligible ? 1 : 0;
            $rows[] = [
                'page' => $page,
                'eligible' => $eligible,
                'blockers' => $blockers,
                'score' => $quality['score'],
                'advisories' => SeoPublicationPolicy::classify($page->getMissingData())['advisory'],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $pageListUrl = $this->adminUrlGenerator
            ->unsetAll()
            ->setDashboard(DashboardController::class)
            ->setController(SeoPageCrudController::class)
            ->setAction('index')
            ->generateUrl();

        return $this->render('admin/seo_bulk_publish.html.twig', [
            'rows' => $rows,
            'eligibleCount' => $eligibleCount,
            'pageListUrl' => $pageListUrl,
            'bulkPublishUrl' => $bulkPublishUrl,
        ]);
    }

    private function redirectToSeoPage(SeoPage $page): Response
    {
        return $this->redirect($this->adminUrlGenerator
            ->setController(SeoPageCrudController::class)
            ->setAction('edit')
            ->setEntityId($page->getId())
            ->generateUrl());
    }

    private function redirectToSeed(SeoSeed $seed): Response
    {
        return $this->redirect($this->adminUrlGenerator
            ->setController(SeoSeedCrudController::class)
            ->setAction('edit')
            ->setEntityId($seed->getId())
            ->generateUrl());
    }

    private function getModelPreferenceFromRequest(Request $request): ?string
    {
        $modelPreference = $request->attributes->get('model') ?: $request->query->get('model');

        return is_string($modelPreference) && trim($modelPreference) !== '' ? trim($modelPreference) : null;
    }

    /**
     * @param array<int, array<string, int|string|null>> $itemsBySeedId
     */
    private function addSeedToBatch(array &$itemsBySeedId, SeoSeed $seed, string $mode): void
    {
        $seedId = $seed->getId();

        if (!$seedId) {
            return;
        }

        $itemsBySeedId[$seedId] = [
            'id' => $seedId,
            'keyword' => (string) $seed->getMainKeyword(),
            'city' => $seed->getCity(),
            'mode' => $mode,
            'priority' => $seed->getPriority(),
            'businessValue' => $seed->getBusinessValue(),
            'url' => $this->generateUrl('admin_seo_seed_generate_batch_item', ['id' => $seedId]),
        ];
    }

    private function promoteCleanSlugBeforePublication(SeoPage $page): ?string
    {
        $slug = (string) $page->getSlug();
        $cleanSlug = preg_replace('/-\d+$/', '', $slug) ?: $slug;

        if ($cleanSlug === $slug || $cleanSlug === '') {
            return null;
        }

        $repository = $this->entityManager->getRepository(SeoPage::class);
        $conflict = $repository->findOneBy([
            'slug' => $cleanSlug,
            'locale' => $page->getLocale(),
        ]);

        if (!$conflict) {
            $page->setSlug($cleanSlug);

            return $cleanSlug;
        }

        if ($conflict->getId() === $page->getId() || $conflict->isPublishedIndexable()) {
            return null;
        }

        $conflict->setSlug($this->uniqueArchiveSlug($cleanSlug, $page->getLocale(), $conflict));
        $page->setSlug($cleanSlug);

        return $cleanSlug;
    }

    private function uniqueArchiveSlug(string $slug, string $locale, SeoPage $excludedPage): string
    {
        $base = $this->limitSlug($slug . '-archive-' . ($excludedPage->getId() ?: uniqid('', false)), 170);
        $candidate = $base;
        $suffix = 2;
        $repository = $this->entityManager->getRepository(SeoPage::class);

        while ($existingPage = $repository->findOneBy(['slug' => $candidate, 'locale' => $locale])) {
            if ($existingPage->getId() === $excludedPage->getId()) {
                break;
            }

            $candidate = $this->limitSlug($base . '-' . $suffix);
            $suffix++;
        }

        return $candidate;
    }

    private function limitSlug(string $slug, int $length = 180): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($slug, 0, $length);
        }

        return substr($slug, 0, $length);
    }

    private function blockingMissingData(array $missingData): array
    {
        return SeoPublicationPolicy::classify($missingData)['blocking'];
    }

    /**
     * @return string[]
     */
    private function bulkPublicationBlockers(SeoPage $page, ?int $currentScore = null): array
    {
        $blockers = [];

        $currentScore ??= $page->getQualityScore();
        if ($currentScore < 75) {
            $blockers[] = sprintf('score qualité %d/100, minimum 75', $currentScore);
        }

        $blockingMissingData = $this->blockingMissingData($page->getMissingData());

        if ($blockingMissingData) {
            $blockers[] = 'données critiques : ' . $this->shortMissingDataList($blockingMissingData);
        }

        return $blockers;
    }

    private function assertSeedGenerationAccess(): void
    {
        $this->assertModuleEnabled('SeoSeed');
        $this->assertModuleEnabled('SeoPage');
        $this->denyAccessUnlessGranted('m_edit', SeoSeed::class);
        $this->denyAccessUnlessGranted('m_create', SeoPage::class);
    }

    private function assertPageEditAccess(): void
    {
        $this->assertModuleEnabled('SeoPage');
        $this->denyAccessUnlessGranted('m_edit', SeoPage::class);
    }

    private function assertModuleEnabled(string $moduleName): void
    {
        if (!$this->moduleRepository->findOneBy(['name' => $moduleName, 'valid' => true])) {
            throw $this->createNotFoundException(sprintf('Module %s indisponible.', $moduleName));
        }
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de securite invalide. Recharge la page puis recommence.');
        }
    }

    private function refreshQuality(SeoPage $page): void
    {
        $result = $this->qualityScorer->scorePage($page);
        $page
            ->setQualityScore($result['score'])
            ->setQualityFlags($result['flags']);
    }

    private function shortMissingDataList(array $missingData): string
    {
        $items = array_slice($missingData, 0, 5);
        $list = implode(' / ', $items);

        if (count($missingData) > 5) {
            $list .= sprintf(' / +%d autre(s)', count($missingData) - 5);
        }

        return $list;
    }
}
