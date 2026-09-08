<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SeoDocumentationController extends AbstractController
{
    private const MODULE_VERSION = '1.2.1';

    #[Route('/admin/seo-documentation', name: 'admin_seo_documentation', methods: ['GET'])]
    public function index(): Response
    {
        if (
            !$this->isGranted('ROLE_ADMIN')
            && !$this->isGranted('ROLE_MODO')
            && !$this->isGranted('ROLE_REF')
            && !$this->isGranted('ROLE_DEV')
        ) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('admin/seo_documentation.html.twig', [
            'moduleVersion' => self::MODULE_VERSION,
        ]);
    }
}
