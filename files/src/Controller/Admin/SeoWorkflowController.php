<?php

namespace App\Controller\Admin;

use App\Entity\SeoPage;
use App\Entity\SeoSeed;
use App\Repository\SeoPageRepository;
use App\Repository\SeoSeedRepository;
use App\Service\ClaudeSeoGenerator;
use App\Service\SeoPageImageResolver;
use App\Service\SeoSeedExpander;
use Doctrine\ORM\EntityManagerInterface;
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
        private SeoPageRepository $seoPageRepository
    ) {
    }

    #[Route('/admin/seo-seed/{id}/generate', name: 'admin_seo_seed_generate', methods: ['GET'])]
    public function generate(SeoSeed $seed, ClaudeSeoGenerator $generator, Request $request): Response
    {
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

    #[Route('/admin/seo-seed/{id}/generate-keyword-pages', name: 'admin_seo_seed_generate_keyword_pages', methods: ['GET'])]
    public function generateKeywordPages(SeoSeed $seed, SeoSeedExpander $seedExpander, ClaudeSeoGenerator $generator, Request $request): Response
    {
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

    #[Route('/admin/seo-seed/generate-batch', name: 'admin_seo_seed_generate_batch', methods: ['GET'])]
    public function generateBatch(SeoSeedRepository $seedRepository, SeoSeedExpander $seedExpander): Response
    {
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

    #[Route('/admin/seo-page/{id}/improve', name: 'admin_seo_page_improve', methods: ['GET'])]
    #[Route('/admin/seo-page/{id}/improve/{model}', name: 'admin_seo_page_improve_model', methods: ['GET'])]
    public function improve(SeoPage $page, ClaudeSeoGenerator $generator, Request $request): Response
    {
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

    #[Route('/admin/seo-page/{id}/publish', name: 'admin_seo_page_publish', methods: ['GET'])]
    public function publish(SeoPage $page): Response
    {
        if ($page->getQualityScore() < 75) {
            $this->addFlash('danger', 'Publication refusee: le score qualite doit etre au moins de 75.');

            return $this->redirectToSeoPage($page);
        }

        $blockingMissingData = $this->blockingMissingData($page->getMissingData());

        if (count($blockingMissingData) > 0) {
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

    #[Route('/admin/seo-page/{id}/unpublish', name: 'admin_seo_page_unpublish', methods: ['GET'])]
    public function unpublish(SeoPage $page): Response
    {
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
        return $this->render('pages/seo_programmatic/show.html.twig', [
            'page' => $page,
            'relatedPages' => $this->seoPageRepository->findRelatedPublishedPages($page, null, false),
            'preview' => true,
        ]);
    }

    #[Route('/admin/seo-page/{id}/resolve-image', name: 'admin_seo_page_resolve_image', methods: ['GET'])]
    public function resolveImage(SeoPage $page, SeoPageImageResolver $imageResolver): Response
    {
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
        $repository = $this->entityManager->getRepository(SeoPage::class);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('seo_page_bulk_publish', $request->request->get('_token'))) {
                $this->addFlash('danger', 'Jeton de sécurité invalide. Recharge la page.');

                return $this->redirectToRoute('admin_seo_page_bulk_publish');
            }

            $formData = $request->request->all();
            $selectedIds = array_values(array_unique(array_filter(array_map(
                static fn (mixed $id): int => (int) $id,
                (array) ($formData['page_ids'] ?? [])
            ))));

            if (!$selectedIds) {
                $this->addFlash('warning', 'Sélectionne au moins une page éligible.');

                return $this->redirectToRoute('admin_seo_page_bulk_publish');
            }

            $published = 0;
            $blocked = [];
            $cleanedSlugs = 0;

            foreach ($repository->findBy(['id' => $selectedIds]) as $page) {
                if (!in_array($page->getStatus(), [SeoPage::STATUS_DRAFT, SeoPage::STATUS_REVIEW], true)) {
                    $blocked[] = sprintf('%s : statut non publiable', $page->getMainKeyword());
                    continue;
                }

                $blockers = $this->bulkPublicationBlockers($page);

                if ($blockers) {
                    $blocked[] = sprintf('%s : %s', $page->getMainKeyword(), implode(', ', $blockers));
                    continue;
                }

                if ($this->promoteCleanSlugBeforePublication($page)) {
                    $cleanedSlugs++;
                }

                $page
                    ->setStatus(SeoPage::STATUS_PUBLISHED)
                    ->setIndexable(true)
                    ->setPublishedAt(new \DateTimeImmutable());
                $published++;
            }

            $this->entityManager->flush();

            if ($published > 0) {
                $this->addFlash('success', sprintf(
                    '%d page(s) publiée(s) et ajoutée(s) au sitemap%s.',
                    $published,
                    $cleanedSlugs > 0 ? sprintf(', %d URL(s) nettoyée(s)', $cleanedSlugs) : ''
                ));
            }

            if ($blocked) {
                $this->addFlash('warning', sprintf(
                    '%d page(s) ignorée(s) : %s',
                    count($blocked),
                    implode(' / ', array_slice($blocked, 0, 5)) . (count($blocked) > 5 ? ' / ...' : '')
                ));
            }

            return $this->redirectToRoute('admin_seo_page_bulk_publish');
        }

        $pages = $repository->findBy([
            'status' => [SeoPage::STATUS_DRAFT, SeoPage::STATUS_REVIEW],
        ], [
            'qualityScore' => 'DESC',
            'updated_at' => 'DESC',
        ]);
        $rows = [];
        $eligibleCount = 0;

        foreach ($pages as $page) {
            $blockers = $this->bulkPublicationBlockers($page);
            $eligible = count($blockers) === 0;
            $eligibleCount += $eligible ? 1 : 0;
            $rows[] = [
                'page' => $page,
                'eligible' => $eligible,
                'blockers' => $blockers,
            ];
        }

        $pageListUrl = $this->adminUrlGenerator
            ->setController(SeoPageCrudController::class)
            ->setAction('index')
            ->generateUrl();

        return $this->render('admin/seo_bulk_publish.html.twig', [
            'rows' => $rows,
            'eligibleCount' => $eligibleCount,
            'pageListUrl' => $pageListUrl,
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
        return array_values(array_filter($missingData, function (string $item): bool {
            $normalized = $this->normalizeForSearch($item);

            foreach ($this->softMissingDataPatterns() as $softPattern) {
                if (str_contains($normalized, $softPattern)) {
                    return false;
                }
            }

            foreach ($this->criticalMissingDataPatterns() as $criticalPattern) {
                if (str_contains($normalized, $criticalPattern)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @return string[]
     */
    private function bulkPublicationBlockers(SeoPage $page): array
    {
        $blockers = [];

        if ($page->getQualityScore() < 75) {
            $blockers[] = sprintf('score qualité %d/100, minimum 75', $page->getQualityScore());
        }

        $blockingMissingData = $this->blockingMissingData($page->getMissingData());

        if ($blockingMissingData) {
            $blockers[] = 'données critiques : ' . $this->shortMissingDataList($blockingMissingData);
        }

        return $blockers;
    }

    /**
     * Donnees vraiment bloquantes pour l'indexation: si elles manquent,
     * la page risque d'etre fausse, generique ou issue d'une generation echouee.
     */
    private function criticalMissingDataPatterns(): array
    {
        return [
            'generation_claude',
            'erreur claude',
            'fait local',
            'faits locaux',
            'preuve metier',
            'preuves metier',
            'donnees metier insuffisantes',
            'service non',
            'prestation non',
            'activite non',
            'zone non',
            'ville non couverte',
            'secteur non couvert',
            'couverture non',
            'entreprise inconnue',
            'nom entreprise',
            'nom de l entreprise',
        ];
    }

    /**
     * Donnees utiles mais non obligatoires: Claude les signale pour enrichir
     * la page, mais elles ne doivent pas bloquer une publication valide.
     */
    private function softMissingDataPatterns(): array
    {
        return [
            'canonical',
            'canonique',
            'telephone',
            'numero de telephone',
            'adresse',
            'rue',
            'code postal',
            'delai',
            'forfait',
            'aide',
            'eligibilite',
            'condition d eligibilite',
            'prix',
            'tarif',
            'marque',
            'certification',
            'garantie',
        ];
    }

    private function normalizeForSearch(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strtolower(trim(preg_replace('/\s+/', ' ', $value) ?: ''));

        if (!function_exists('iconv')) {
            return $value;
        }

        $asciiValue = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return is_string($asciiValue) && $asciiValue !== '' ? $asciiValue : $value;
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
