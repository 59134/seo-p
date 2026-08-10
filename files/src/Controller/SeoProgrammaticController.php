<?php

namespace App\Controller;

use App\Repository\ModuleRepository;
use App\Repository\SeoPageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SeoProgrammaticController extends AbstractController
{
    public function __construct(
        private SeoPageRepository $seoPageRepository,
        private ModuleRepository $moduleRepository
    ) {
    }

    #[Route('/seo-local/{slug}', name: 'seo_programmatic_page_legacy', methods: ['GET'])]
    public function legacyRedirect(string $slug, Request $request): Response
    {
        $this->assertModuleEnabled();

        return $this->redirectToRoute('seo_programmatic_page', [
            'slug' => $slug,
            '_locale' => $request->getLocale(),
        ], Response::HTTP_MOVED_PERMANENTLY);
    }

    public function show(string $slug, Request $request): Response
    {
        $this->assertModuleEnabled();
        $locale = $request->getLocale();
        $page = $this->seoPageRepository->findPublishedBySlug($slug, $locale);

        if (!$page) {
            throw $this->createNotFoundException('Page SEO introuvable.');
        }

        return $this->render('pages/seo_programmatic/show.html.twig', [
            'page' => $page,
            'relatedPages' => $this->seoPageRepository->findRelatedPublishedPages($page),
            'preview' => false,
        ]);
    }

    private function assertModuleEnabled(): void
    {
        if (!$this->moduleRepository->findOneBy(['name' => 'SeoPage', 'valid' => true])) {
            throw $this->createNotFoundException('Module SEO programmatique indisponible.');
        }
    }
}
