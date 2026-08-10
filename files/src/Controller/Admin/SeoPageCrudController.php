<?php

namespace App\Controller\Admin;

use App\Entity\SeoPage;
use App\Entity\SeoSeed;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class SeoPageCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return SeoPage::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            FormField::addPanel('Mode d emploi')
                ->setHelp('Relire la page générée avant publication. Publier seulement si le score qualité est au moins de 75, si les données manquantes sont corrigées et si la page apporte une vraie valeur locale.'),
            IdField::new('id')->hideOnForm(),
            AssociationField::new('seed', 'Seed')->setColumns(6),
            TextField::new('mainKeyword', 'Mot clé')->setColumns(6),
            TextField::new('slug', 'Slug')
                ->formatValue(function (?string $value, ?SeoPage $page): string {
                    if (!$value || !$page) {
                        return '';
                    }

                    $isPublic = $page->isPublishedIndexable();
                    $url = $this->generateUrl(
                        $isPublic ? 'seo_programmatic_page' : 'admin_seo_page_preview',
                        $isPublic ? ['slug' => $value] : ['id' => $page->getId()]
                    );
                    $title = $isPublic ? 'Ouvrir la page front' : 'Ouvrir la previsualisation admin';

                    return sprintf(
                        '<a href="%s" target="_blank" rel="noopener" title="%s">%s</a>',
                        htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    );
                })
                ->renderAsHtml()
                ->setColumns(6),
            TextField::new('locale', 'Langue')->hideOnIndex()->setColumns(2),
            ChoiceField::new('status', 'Statut')->setChoices([
                'Brouillon' => SeoPage::STATUS_DRAFT,
                'A relire' => SeoPage::STATUS_REVIEW,
                'Publie' => SeoPage::STATUS_PUBLISHED,
                'Archive' => SeoPage::STATUS_ARCHIVED,
            ])->setColumns(4),
            TextField::new('title', 'Title SEO')->setColumns(6),
            TextField::new('metaDescription', 'Meta description')->setColumns(6),
            BooleanField::new('indexable', 'Indexable')->setColumns(2),
            IntegerField::new('qualityScore', 'Score qualité')->setColumns(4),
            TextField::new('h1', 'H1')->hideOnIndex()->setColumns(12),
            TextareaField::new('intro', 'Introduction')
                ->setRequired(false)
                ->hideOnIndex()
                ->setColumns(12),
            TextareaField::new('contentJson', 'Sections JSON')
                ->setRequired(false)
                ->hideOnIndex()
                ->setFormTypeOption('attr', [
                    'class' => 'form-control seo-plain-text',
                    'rows' => 10,
                    'style' => 'min-height: 220px; max-height: 420px; font-family: monospace; font-size: 13px;',
                ])
                ->setColumns(12),
            TextareaField::new('faqJson', 'FAQ JSON')
                ->setRequired(false)
                ->hideOnIndex()
                ->setFormTypeOption('attr', [
                    'class' => 'form-control seo-plain-text',
                    'rows' => 8,
                    'style' => 'min-height: 180px; max-height: 320px; font-family: monospace; font-size: 13px;',
                ])
                ->setColumns(12),
            TextareaField::new('internalLinksJson', 'Liens internes JSON')
                ->setRequired(false)
                ->hideOnIndex()
                ->setFormTypeOption('attr', [
                    'class' => 'form-control seo-plain-text',
                    'rows' => 6,
                    'style' => 'min-height: 140px; max-height: 260px; font-family: monospace; font-size: 13px;',
                ])
                ->setColumns(12),
            TextareaField::new('templateCopyJson', 'Textes template JSON')
                ->setRequired(false)
                ->hideOnIndex()
                ->setFormTypeOption('attr', [
                    'class' => 'form-control seo-plain-text',
                    'rows' => 8,
                    'style' => 'min-height: 160px; max-height: 260px; font-family: monospace; font-size: 13px;',
                ])
                ->setHelp('Optionnel. Textes courts utilisés par le template front: points du hero, bloc situation, étapes, introduction des sections et CTA final.')
                ->setColumns(12),
            TextareaField::new('imageAltSuggestionsText', 'Suggestions alt images')->hideOnIndex()->hideOnForm(),
            TextField::new('imageUrl', 'Image SEO')
                ->setRequired(false)
                ->hideOnIndex()
                ->setColumns(6)
                ->setHelp('Automatique: image og:image de la prestation liee, puis photo d un album pertinent.'),
            TextField::new('imageAlt', 'ALT image SEO')
                ->setRequired(false)
                ->hideOnIndex()
                ->setColumns(6)
                ->setHelp('Decrit honnetement la photo. Ne pas inventer le lieu de prise de vue.'),
            TextField::new('imageSource', 'Source image')->hideOnIndex()->hideOnForm(),
            TextField::new('cta', 'CTA')
                ->hideOnIndex()
                ->setColumns(4)
                ->setFormTypeOption('attr', ['maxlength' => 38])
                ->setHelp('Libellé court de bouton uniquement. Exemple: Faire une demande, Tester mon éligibilité, Demander un devis.'),
            TextareaField::new('schemaJsonText', 'Schema JSON-LD')
                ->setRequired(false)
                ->hideOnIndex()
                ->setFormTypeOption('attr', [
                    'class' => 'form-control seo-plain-text',
                    'rows' => 8,
                    'style' => 'min-height: 160px; max-height: 260px; font-family: monospace; font-size: 13px;',
                ])
                ->setColumns(12),
            TextareaField::new('qualityFlagsText', 'Alertes qualité')->setRequired(false)->hideOnForm(),
            TextareaField::new('missingDataText', 'Donnees manquantes')
                ->setRequired(false)
                ->setColumns(12)
                ->setHelp('Une ligne par information manquante. Telephone, adresse, delai, forfait, aides ou prix sont des ameliorations non bloquantes. Les lignes critiques comme service non confirme, zone non couverte ou generation Claude echouee bloquent la publication.'),
            TextField::new('canonicalUrl', 'Canonical forcee')
                ->setRequired(false)
                ->hideOnIndex()
                ->setColumns(12)
                ->setHelp('Optionnel. Laisser vide pour utiliser automatiquement l URL publique de cette page comme canonical.'),
            TextareaField::new('rawClaudeResponse', 'Réponse brute Claude')
                ->setRequired(false)
                ->hideOnIndex()
                ->hideOnForm(),
            DateTimeField::new('generatedAt', 'Genere')->hideOnForm(),
            DateTimeField::new('publishedAt', 'Publie')->hideOnForm(),
            DateTimeField::new('updated_at', 'Modifie')->hideOnForm()->hideOnIndex(),
            DateTimeField::new('created_at', 'Creation')->hideOnForm()->hideOnIndex(),
        ];
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle(Crud::PAGE_INDEX, '3. Pages SEO programmatiques')
            ->setEntityLabelInSingular('Page SEO')
            ->setEntityLabelInPlural('Pages SEO')
            ->setDefaultSort(['updated_at' => 'DESC', 'qualityScore' => 'DESC'])
            ->setHelp(Crud::PAGE_INDEX, 'ÉTAPE 3 - Relire les pages générées. Une page n apparaît sur Google que si elle est publiée ET indexable. Publier seulement si le score qualité est au moins 75 et si le contenu est vraiment utile/local.')
            ->setHelp(Crud::PAGE_NEW, 'Création manuelle possible, mais le flux recommandé est: Faits vérifiés -> Seeds SEO -> Générer avec Claude -> Relire ici -> Publier.')
            ->setHelp(Crud::PAGE_EDIT, 'Vérifier le title, la meta, le H1, les sections, la FAQ, les alertes qualité et les données manquantes avant publication.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $preview = Action::new('previewSeoPage', 'Previsualiser', 'fa fa-eye')
            ->linkToRoute('admin_seo_page_preview', static fn (SeoPage $page): array => ['id' => $page->getId()])
            ->setHtmlAttributes([
                'target' => '_blank',
                'rel' => 'noopener',
            ]);

        $publish = Action::new('publishSeoPage', 'Publier', 'fa fa-check')
            ->linkToRoute('admin_seo_page_publish', static fn (SeoPage $page): array => ['id' => $page->getId()])
            ->displayIf(static fn (SeoPage $page): bool => $page->getStatus() !== SeoPage::STATUS_PUBLISHED);

        $unpublish = Action::new('unpublishSeoPage', 'Retirer', 'fa fa-ban')
            ->linkToRoute('admin_seo_page_unpublish', static fn (SeoPage $page): array => ['id' => $page->getId()])
            ->displayIf(static fn (SeoPage $page): bool => $page->getStatus() === SeoPage::STATUS_PUBLISHED);

        $resolveImage = Action::new('resolveSeoImage', 'Trouver une image', 'fa fa-image')
            ->linkToRoute('admin_seo_page_resolve_image', static fn (SeoPage $page): array => ['id' => $page->getId()])
            ->displayIf(static fn (SeoPage $page): bool => $page->getSeed() !== null);

        $improveSonnet5 = Action::new('improveSeoPageSonnet5', 'Optimiser Sonnet 5', 'fa fa-bolt')
            ->linkToRoute('admin_seo_page_improve_model', static fn (SeoPage $page): array => [
                'id' => $page->getId(),
                'model' => SeoSeed::CLAUDE_MODEL_SONNET_5,
            ])
            ->displayIf(static fn (SeoPage $page): bool => $page->getSeed() !== null && $page->getStatus() !== SeoPage::STATUS_PUBLISHED);

        $improveOpus = Action::new('improveSeoPageOpus', 'Optimiser Opus', 'fa fa-gem')
            ->linkToRoute('admin_seo_page_improve_model', static fn (SeoPage $page): array => [
                'id' => $page->getId(),
                'model' => SeoSeed::CLAUDE_MODEL_OPUS,
            ])
            ->displayIf(static fn (SeoPage $page): bool => $page->getSeed() !== null && $page->getStatus() !== SeoPage::STATUS_PUBLISHED);

        $improveFable = Action::new('improveSeoPageFable', 'Optimiser Fable', 'fa fa-star')
            ->linkToRoute('admin_seo_page_improve_model', static fn (SeoPage $page): array => [
                'id' => $page->getId(),
                'model' => SeoSeed::CLAUDE_MODEL_FABLE,
            ])
            ->displayIf(static fn (SeoPage $page): bool => $page->getSeed() !== null && $page->getStatus() !== SeoPage::STATUS_PUBLISHED);

        return $actions
            ->add(Crud::PAGE_INDEX, $preview)
            ->add(Crud::PAGE_INDEX, $publish)
            ->add(Crud::PAGE_INDEX, $unpublish)
            ->add(Crud::PAGE_EDIT, $preview)
            ->add(Crud::PAGE_EDIT, $publish)
            ->add(Crud::PAGE_EDIT, $unpublish)
            ->add(Crud::PAGE_EDIT, $resolveImage)
            ->add(Crud::PAGE_EDIT, $improveSonnet5)
            ->add(Crud::PAGE_EDIT, $improveOpus)
            ->add(Crud::PAGE_EDIT, $improveFable);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('slug')
            ->add('mainKeyword')
            ->add('status')
            ->add('indexable')
            ->add('qualityScore')
            ->add('locale');
    }
}
