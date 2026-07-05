<?php

namespace App\Controller;

use App\Entity\AlbumCategorie;
use App\Entity\ArticleBlog;
use App\Entity\ArticleRef;
use App\Entity\Categorie;
use App\Entity\Coordonnee;
use App\Entity\CategorieBlog;
use App\Entity\Equipe;
use App\Entity\Lieu;
use App\Entity\Marque;
use App\Entity\Prestation;
use App\Entity\Produit;
use App\Entity\SeoPage;
use App\Entity\Vehicule;
use App\Entity\Module;
use App\Twig\AppExtension;
use Doctrine\ORM\EntityManagerInterface;
use phpDocumentor\Reflection\Types\Null_;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SitemapController extends AbstractController
{

    public function __construct(private EntityManagerInterface $em, private AppExtension $func) {}

    #[Route('/sitemap.xml', name: 'sitemap', defaults: ['_format' => 'xml'])]
    public function index(Request $request)
    {
        // On récupère le nom d'höte depuis l'url
        $hostname = $request->getSchemeAndHttpHost();

        // On initialise un tableau pour lister les urls
        $urls = [];

        $urls[] = ['loc' => $this->generateUrl('app_main')];
        $urls[] = ['loc' => $this->generateUrl('contact')];

        if ($this->em->getRepository(Module::class)->findBy(array('name' => "Video", 'valid' => 1))) {
            $urls[] = ['loc' => $this->generateUrl('video')];
        }
        if ($this->em->getRepository(Module::class)->findBy(array('name' => "Partenaire", 'valid' => 1))) {
            $urls[] = ['loc' => $this->generateUrl('partenaire')];
        }
        if ($this->em->getRepository(Module::class)->findBy(array('name' => "Galerie", 'valid' => 1))) {
            $urls[] = ['loc' => $this->generateUrl('galerie')];
        }
        if ($this->em->getRepository(Module::class)->findBy(array('name' => "Equipe", 'valid' => 1))) {
            $urls[] = ['loc' => $this->generateUrl('equipes')];
        }
        if ($this->em->getRepository(Module::class)->findBy(array('name' => "Produit", 'valid' => 1))) {
            $urls[] = ['loc' => $this->generateUrl('produits')];
        }
        if ($this->em->getRepository(Module::class)->findBy(array('name' => "Vehicule", 'valid' => 1))) {
            $urls[] = ['loc' => $this->generateUrl('vehicules')];
        }
        if ($this->em->getRepository(Module::class)->findBy(array('name' => "AlbumCategorie", 'valid' => 1))) {
            $urls[] = ['loc' => $this->generateUrl('albums')];
        }
        if ($this->em->getRepository(Module::class)->findBy(array('name' => "BeforeAfter", 'valid' => 1))) {
            $urls[] = ['loc' => $this->generateUrl('avantApres')];
        }
        if ($this->em->getRepository(Module::class)->findBy(array('name' => "Marque", 'valid' => 1))) {
            $urls[] = ['loc' => $this->generateUrl('marques')];
        }
        if ($this->em->getRepository(Module::class)->findBy(array('name' => "Prestation", 'valid' => 1))) {
            $urls[] = ['loc' => $this->generateUrl('prestations')];
        }
        if ($this->em->getRepository(Module::class)->findBy(array('name' => "Blog", 'valid' => 1))) {
            $urls[] = ['loc' => $this->generateUrl('blog')];
        }
        if ($this->em->getRepository(Module::class)->findBy(array('name' => "Carte", 'valid' => 1))) {
            $urls[] = ['loc' => $this->generateUrl('cartes')];
        }
        if ($this->em->getRepository(Module::class)->findBy(array('name' => "Agenda", 'valid' => 1))) {
            $urls[] = ['loc' => $this->generateUrl('agenda')];
        }
        if ($this->em->getRepository(Module::class)->findBy(array('name' => "Coordonnee", 'valid' => 1))) {
            $urls[] = ['loc' => $this->generateUrl('magasins')];
        }

        foreach ($this->em->getRepository(Categorie::class)->findBy(array('url' => Null, 'valid' => 1)) as $categorie) {
            if ($categorie->getUpdatedAt()) {
                $date = $categorie->getUpdatedAt()->format('Y-m-d');
            } else {
                $date = $categorie->getCreatedAt()->format('Y-m-d');
            }
            $urls[] = [
                'loc' => $this->generateUrl('app_categorie', [
                    'id' => $categorie->getId(),
                    'slug' => $categorie->getSlug(),
                ]),
                'lastmod' => $date
            ];
        }

        foreach ($this->em->getRepository(CategorieBlog::class)->findBy(array('valid' => 1)) as $blog) {
            if ($blog->getUpdatedAt()) {
                $date = $blog->getUpdatedAt()->format('Y-m-d');
            } else {
                $date = $blog->getCreatedAt()->format('Y-m-d');
            }

            $name = $this->func->format($blog->getName());
            $urls[] = [
                'loc' => $this->generateUrl('blog_cat', [
                    'categorieBlog' => $this->func->format($blog->getName()),
                    'id' => $blog->getId(),
                ]),
                'lastmod' => $date
            ];
        }

        foreach ($this->em->getRepository(ArticleBlog::class)->findBy(array('valid' => 1)) as $blog) {
            if ($blog->getUpdatedAt()) {
                $date = $blog->getUpdatedAt()->format('Y-m-d');
            } else {
                $date = $blog->getCreatedAt()->format('Y-m-d');
            }

            $name = $this->func->format($blog->getName());

            $urls[] = [
                'loc' => $this->generateUrl('blog_id', [
                    'categorieBlog' => $this->func->format($blog->getCategorieBlog()->getName()),
                    'id' => $blog->getId(),
                    'name' => $name,
                ]),
                'lastmod' => $date
            ];
        }

        foreach ($this->em->getRepository(ArticleRef::class)->findBy(array('valid' => 1)) as $artRef) {
            if ($artRef->getUpdatedAt()) {
                $date = $artRef->getUpdatedAt()->format('Y-m-d');
            } else {
                $date = $artRef->getCreatedAt()->format('Y-m-d');
            }

            $urls[] = [
                'loc' => $this->generateUrl('article-ref', [
                    'id' => $artRef->getId(),
                    'slug' => $artRef->getSlug(),
                ]),
                'lastmod' => $date
            ];
        }

        // foreach ($this->em->getRepository(Coordonnee::class)->findBy(array('valid' => 1)) as $lieu) {
        //     if ($lieu->getUpdatedAt()) {
        //         $date = $lieu->getUpdatedAt()->format('Y-m-d');
        //     } else {
        //         $date = $lieu->getCreatedAt()->format('Y-m-d');
        //     }
        //     $urls[] = [
        //         'loc' => $this->generateUrl('magasin', [
        //             'id' => $lieu->getId()
        //         ]),
        //         'lastmod' => $date
        //     ];
        // }

        // On ajoute les URLs "dynamiques"
        foreach ($this->em->getRepository(Equipe::class)->findBy(array('valid' => 1)) as $equipe) {
            if ($equipe->getUpdatedAt()) {
                $date = $equipe->getUpdatedAt()->format('Y-m-d');
            } else {
                $date = $equipe->getCreatedAt()->format('Y-m-d');
            }
            $urls[] = [
                'loc' => $this->generateUrl('equipe', [
                    'id' => $equipe->getId()
                ]),
                'lastmod' => $date
            ];
        }

        foreach ($this->em->getRepository(AlbumCategorie::class)->findBy(array('valid' => 1)) as $album) {
            if ($album->getUpdatedAt()) {
                $date = $album->getUpdatedAt()->format('Y-m-d');
            } else {
                $date = $album->getCreatedAt()->format('Y-m-d');
            }
            $urls[] = [
                'loc' => $this->generateUrl('album', [
                    'id' => $album->getId(),
                    'name' => $this->func->format($album->getName()),
                ]),
                'lastmod' => $date
            ];
        }

        foreach ($this->em->getRepository(Prestation::class)->findBy(array('valid' => 1)) as $presta) {
            if ($presta->getUpdatedAt()) {
                $date = $presta->getUpdatedAt()->format('Y-m-d');
            } else {
                $date = $presta->getCreatedAt()->format('Y-m-d');
            }
            $urls[] = [
                'loc' => $this->generateUrl('prestation', [
                    'id' => $presta->getId(),
                    'name' => $this->func->format($presta->getName()),
                ]),
                'lastmod' => $date
            ];
        }

        foreach ($this->em->getRepository(Marque::class)->findBy(array('valid' => 1)) as $marque) {
            if ($marque->getUpdatedAt()) {
                $date = $marque->getUpdatedAt()->format('Y-m-d');
            } else {
                $date = $marque->getCreatedAt()->format('Y-m-d');
            }
            $urls[] = [
                'loc' => $this->generateUrl('marqueProduits', [
                    'id' => $marque->getId(),
                ]),
                'lastmod' => $date
            ];
        }

        foreach ($this->em->getRepository(Vehicule::class)->findBy(array('valid' => 1)) as $vehicule) {
            if ($vehicule->getUpdatedAt()) {
                $date = $vehicule->getUpdatedAt()->format('Y-m-d');
            } else {
                $date = $vehicule->getCreatedAt()->format('Y-m-d');
            }
            $urls[] = [
                'loc' => $this->generateUrl('vehicule', [
                    'id' => $vehicule->getId(),
                    'name' => $this->func->format($vehicule->getName()),
                ]),
                'lastmod' => $date
            ];
        }

        foreach ($this->em->getRepository(Produit::class)->findBy(array('valid' => 1)) as $produit) {
            if ($produit->getUpdatedAt()) {
                $date = $produit->getUpdatedAt()->format('Y-m-d');
            } else {
                $date = $produit->getCreatedAt()->format('Y-m-d');
            }
            $urls[] = [
                'loc' => $this->generateUrl('produit', [
                    'id' => $produit->getId(),
                    'name' => $this->func->format($produit->getName()),
                ]),
                'lastmod' => $date
            ];
        }

        foreach ($this->em->getRepository(SeoPage::class)->findIndexablePages() as $seoPage) {
            if ($seoPage->getUpdatedAt()) {
                $date = $seoPage->getUpdatedAt()->format('Y-m-d');
            } elseif ($seoPage->getPublishedAt()) {
                $date = $seoPage->getPublishedAt()->format('Y-m-d');
            } else {
                $date = $seoPage->getCreatedAt()->format('Y-m-d');
            }

            $urls[] = [
                'loc' => $this->generateUrl('seo_programmatic_page', [
                    'slug' => $seoPage->getSlug(),
                ]),
                'lastmod' => $date,
            ];
        }

        // Fabrication de la reponse
        $response = new Response(
            $this->renderView('pages/sitemap.html.twig', [
                'urls' => $urls,
                'hostname' => $hostname
            ]),
            200
        );

        // Ajout des entêtes HTTP
        $response->headers->set('Content-Type', 'text/xml');

        // On envoie la réponse
        return $response;
    }
}
