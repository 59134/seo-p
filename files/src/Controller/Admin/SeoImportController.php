<?php

namespace App\Controller\Admin;

use App\Service\SeoJsonImporter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SeoImportController extends AbstractController
{
    public function __construct(
        private readonly SeoJsonImporter $importer,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    #[Route('/admin/seo/import/{type}', name: 'admin_seo_import_json', methods: ['GET', 'POST'])]
    public function import(string $type, Request $request): Response
    {
        $type = in_array($type, [SeoJsonImporter::SCOPE_FACTS, SeoJsonImporter::SCOPE_SEEDS], true)
            ? $type
            : SeoJsonImporter::SCOPE_ALL;

        $result = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('seo_import_' . $type, (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Jeton de securite invalide. Recharge la page puis relance l import.');

                return $this->redirectToRoute('admin_seo_import_json', ['type' => $type]);
            }

            $file = $request->files->get('json_file');

            if (!$file instanceof UploadedFile) {
                $this->addFlash('danger', 'Aucun fichier JSON selectionne.');

                return $this->redirectToRoute('admin_seo_import_json', ['type' => $type]);
            }

            if (strtolower((string) $file->getClientOriginalExtension()) !== 'json') {
                $this->addFlash('danger', 'Format refuse: importe un fichier .json.');

                return $this->redirectToRoute('admin_seo_import_json', ['type' => $type]);
            }

            try {
                $result = $this->importer->importFile(
                    $file->getPathname(),
                    $type,
                    $request->request->get('update') === '1',
                    false
                );
            } catch (\Throwable $exception) {
                $this->addFlash('danger', $exception->getMessage());

                return $this->redirectToRoute('admin_seo_import_json', ['type' => $type]);
            }

            $errors = $result['stats']['facts']['errors'] + $result['stats']['seeds']['errors'];

            if ($errors > 0) {
                $this->addFlash('warning', sprintf('Import termine avec %d erreur(s). Les lignes valides ont ete importees.', $errors));
            } else {
                $this->addFlash('success', 'Import JSON termine.');
            }
        }

        return $this->render('admin/seo_import.html.twig', [
            'type' => $type,
            'title' => $this->titleForType($type),
            'returnUrl' => $this->returnUrlForType($type),
            'result' => $result,
        ]);
    }

    private function titleForType(string $type): string
    {
        return match ($type) {
            SeoJsonImporter::SCOPE_FACTS => 'Importer des faits SEO verifies',
            SeoJsonImporter::SCOPE_SEEDS => 'Importer des seeds SEO',
            default => 'Importer un fichier SEO JSON',
        };
    }

    private function returnUrlForType(string $type): string
    {
        $controller = $type === SeoJsonImporter::SCOPE_FACTS
            ? SeoFactCrudController::class
            : SeoSeedCrudController::class;

        return $this->adminUrlGenerator
            ->setController($controller)
            ->setAction('index')
            ->generateUrl();
    }
}
