<?php

namespace App\Controller\Admin;

use App\Entity\Module;
use App\Service\StatsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Security;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;

class DashboardController extends AbstractDashboardController
{
    private $entitys, $UserRoles;

    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private StatsService $statService
    ) {
        $this->UserRoles = $this->security->getUser()->getRoles();
        $this->entitys = $entityManager->getRepository(Module::class)->findAll();
    }

    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        if ($this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY') || $this->security->getUser()->isValid() == false) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('admin/dashboard.html.twig', $this->statService->index());
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<img class="logoBack" src="uploads\web-time.webp" alt="">')
            ->setFaviconPath('uploads/favicon.ico')
            ->renderContentMaximized();
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToUrl('Voir mon site', 'fa-solid fa-right-from-bracket', $this->generateUrl('app_main'))->setLinkTarget('_blank');
        yield MenuItem::linkToDashboard('Tableau de bord', 'fa fa-home');

        yield MenuItem::section('Modules');
        foreach ($this->entitys as $entity) {
            if ($entity->getSection() == "Modules") {
                if ($entity->isValid() == true) {
                    foreach ($this->UserRoles as $RoleUser) {
                        if (in_array($RoleUser, $entity->getSee()) == true || $RoleUser === 'ROLE_ADMIN') {
                            yield MenuItem::linkToCrud($entity->getTitle(), $entity->getIcon(), "App\Entity\\" . $entity->getName());
                            break;
                        }
                    }
                }
            }
        }

        yield MenuItem::section('Catalogue');
        foreach ($this->entitys as $entity) {
            if ($entity->getSection() == "Catalogue") {
                if ($entity->isValid() == true) {
                    foreach ($this->UserRoles as $RoleUser) {
                        if (in_array($RoleUser, $entity->getSee()) == true || $RoleUser === 'ROLE_ADMIN') {
                            yield MenuItem::linkToCrud($entity->getTitle(), $entity->getIcon(), "App\Entity\\" . $entity->getName());
                            break;
                        }
                    }
                }
            }
        }

        yield MenuItem::section('Contact');
        foreach ($this->entitys as $entity) {
            if ($entity->getSection() == "Contact") {
                if ($entity->isValid() == true) {
                    foreach ($this->UserRoles as $RoleUser) {
                        if (in_array($RoleUser, $entity->getSee()) == true || $RoleUser === 'ROLE_ADMIN') {
                            yield MenuItem::linkToCrud($entity->getTitle(), $entity->getIcon(), "App\Entity\\" . $entity->getName());
                            break;
                        }
                    }
                }
            }
        }

        $referencementItems = $this->getVisibleModulesForSection('Référencement');
        if ($referencementItems !== []) {
            yield MenuItem::section('Référencement');
            foreach ($referencementItems as $entity) {
                yield $this->linkToModuleCrud($entity);
            }
        }

        $seoProgrammaticItems = $this->getVisibleModulesForSection('SEO programmatique');
        if ($seoProgrammaticItems !== []) {
            yield MenuItem::section('SEO programmatique');
            yield MenuItem::linkToRoute('0. Documentation SEO', 'fa fa-book', 'admin_seo_documentation');
            foreach ($seoProgrammaticItems as $entity) {
                yield $this->linkToModuleCrud($entity);
            }
        }
        if ($this->security->isGranted('ROLE_MODO') || $this->security->isGranted('ROLE_ADMIN')) {
            function entitysP($entitys)
            {
                $res = array();
                foreach ($entitys as $entity) {
                    if ($entity->getSection() == "Paramètres") {
                        if ($entity->isValid() == true) {
                            if ($entity->getName() == "ConfigAdmin") {
                                $res[] = MenuItem::linkToCrud($entity->getTitle(), $entity->getIcon(), "App\Entity\\" . $entity->getName())->setAction('edit')->setEntityId(1);
                            } else {
                                $res[] = MenuItem::linkToCrud($entity->getTitle(), $entity->getIcon(), "App\Entity\\" . $entity->getName());
                            }
                        }
                    }
                }

                return $res;
            }
            function entitysPA($entitys)
            {
                $res2 = array();
                foreach ($entitys as $entity) {
                    if ($entity->getSection() == "Paramètres avancés") {
                        if ($entity->isValid() == true) {
                            if ($entity->getName() == "Maintenance") {
                                $res2[] = MenuItem::linkToCrud($entity->getTitle(), $entity->getIcon(), "App\Entity\\" . $entity->getName())->setAction('edit')->setEntityId(1);
                            } elseif ($entity->getName() == "LogEntry") {
                                $res2[] = MenuItem::linkToCrud($entity->getTitle(), $entity->getIcon(), "Gedmo\Loggable\Entity\\" . $entity->getName());
                            } else {
                                $res2[] = MenuItem::linkToCrud($entity->getTitle(), $entity->getIcon(), "App\Entity\\" . $entity->getName());
                            }
                        }
                    }
                }
                // Ajouter le lien vers le template configDev
                $res2[] = MenuItem::linkToRoute('Configuration Dev', 'fab fa-dev', 'file_editor');
                return $res2;
            }
            yield MenuItem::section('Configurations');
            yield MenuItem::subMenu('Paramètres', 'fa fa-cog')->setSubItems(
                entitysP($this->entitys),
            );
            if ($this->security->isGranted('ROLE_ADMIN')) {
                yield MenuItem::subMenu('Paramètres avancés', 'fa fa-cogs')->setSubItems(
                    entitysPA($this->entitys),
                );
            }
        }
    }

    public function configureCrud(): Crud
    {
        return parent::configureCrud()
            ->setPageTitle(Crud::PAGE_INDEX, 'Tableau de bord')
            ->addFormTheme('@A2lixTranslationForm/bootstrap_4_layout.html.twig')
            ->addFormTheme('admin/fields/miniature.html.twig');
    }

    public function configureAssets(): Assets
    {
        return parent::configureAssets()
            ->addWebpackEncoreEntry('admin-app')
            ->addJsFile('js/jquery-3.6.1.min.js')
            ->addJsFile('js/bootstrap.min.js')
            ->addJsFile('tinymce/tinymce.min.js')
            ->addHtmlContentToHead('<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.22.0/ace.js" integrity="sha512-rwI+pLZtyg5V/B9L85D0B5Jw6GLpJttkECSiOF4COmhhSbTq7yvdh9PxVD82aKoXTYEKSPgFTxj1DeEO8iUFzw==" crossorigin="anonymous"></script>');
    }

    private function getVisibleModulesForSection(string $section): array
    {
        $items = [];

        foreach ($this->entitys as $entity) {
            if (!$entity instanceof Module || $entity->getSection() !== $section || $entity->isValid() !== true) {
                continue;
            }

            if ($this->canSeeModule($entity)) {
                $items[] = $entity;
            }
        }

        return $items;
    }

    private function canSeeModule(Module $module): bool
    {
        foreach ($this->UserRoles as $RoleUser) {
            if (in_array($RoleUser, $module->getSee(), true) || $RoleUser === 'ROLE_ADMIN') {
                return true;
            }
        }

        return false;
    }

    private function linkToModuleCrud(Module $module)
    {
        return MenuItem::linkToCrud($module->getTitle(), $module->getIcon(), "App\Entity\\" . $module->getName());
    }
}
