<?php

namespace App\Controller\Admin;

use App\Entity\SeoSeed;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class SeoSeedCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return SeoSeed::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            FormField::addPanel('Mode d emploi')
                ->setHelp('Un seed représente une page potentielle, par exemple "chauffagiste à Lille". Renseigner le service, la ville, les mots clés secondaires et surtout l intention utilisateur avant de générer avec Claude.'),
            IdField::new('id')->hideOnForm(),
            TextField::new('mainKeyword', 'Mot clé principal')->setColumns(6),
            TextField::new('service', 'Service')->setColumns(6),
            TextField::new('servicePageUrl', 'URL page prestation liée')
                ->setRequired(false)
                ->setHelp('Lien interne vers la page prestation principale. Exemple: /remplacement-chaudiere. Claude devra mailler la page locale vers cette page.')
                ->setColumns(6),
            TextField::new('servicePageLabel', 'Libellé lien prestation')
                ->setRequired(false)
                ->setHelp('Texte du lien. Exemple: Remplacement de chaudière. Si vide, le module génère un libellé depuis le service.')
                ->hideOnIndex()
                ->setColumns(6),
            TextField::new('city', 'Ville')->setColumns(4),
            TextField::new('department', 'Département')->setColumns(4),
            TextField::new('locale', 'Langue')->hideOnIndex()->setColumns(4),
            TextareaField::new('secondaryKeywordsText', 'Mots clés secondaires')
                ->setRequired(false)
                ->hideOnIndex()
                ->setFormTypeOption('attr', ['class' => 'form-control seo-plain-text'])
                ->setHelp('Un mot clé par ligne. Ces mots clés enrichissent cette page, ils ne créent pas de pages séparées.')
                ->setColumns(12),
            TextareaField::new('pageKeywordsText', 'Mots clés pages à générer')
                ->setRequired(false)
                ->hideOnIndex()
                ->setFormTypeOption('attr', ['class' => 'form-control seo-plain-text'])
                ->setHelp('Un mot clé par ligne. Ces mots clés créent des seeds/pages séparés. Format avancé: mot clé | service | ville | département | intention.')
                ->setColumns(12),
            TextareaField::new('intent', 'Intention utilisateur')
                ->setRequired(false)
                ->hideOnIndex()
                ->setColumns(12),
            TextareaField::new('notes', 'Notes et données spécifiques')
                ->setRequired(false)
                ->hideOnIndex()
                ->setColumns(12),
            IntegerField::new('businessValue', 'Valeur business')->setColumns(4),
            IntegerField::new('priority', 'Priorité')->setColumns(4),
            ChoiceField::new('claudeModelPreference', 'Modèle Claude')
                ->setChoices([
                    'Auto: Sonnet ou premium si prioritaire' => SeoSeed::CLAUDE_MODEL_AUTO,
                    'Toujours Sonnet 4.6' => SeoSeed::CLAUDE_MODEL_SONNET,
                    'Toujours Sonnet 5' => SeoSeed::CLAUDE_MODEL_SONNET_5,
                    'Toujours Opus' => SeoSeed::CLAUDE_MODEL_OPUS,
                    'Toujours Fable' => SeoSeed::CLAUDE_MODEL_FABLE,
                ])
                ->setHelp('Auto = Sonnet pour les pages normales. Si valeur business ou priorité >= 80, le module utilise le modèle premium configuré.')
                ->setColumns(4),
            IntegerField::new('dataCompletenessScore', 'Score données')->hideOnForm(),
            BooleanField::new('valid', 'Actif'),
            DateTimeField::new('updated_at', 'Modifié')->hideOnForm(),
            DateTimeField::new('created_at', 'Création')->hideOnForm(),
        ];
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle(Crud::PAGE_INDEX, '2. Seeds SEO programmatiques')
            ->setEntityLabelInSingular('Seed SEO')
            ->setEntityLabelInPlural('Seeds SEO')
            ->setDefaultSort(['priority' => 'DESC', 'businessValue' => 'DESC'])
            ->setHelp(Crud::PAGE_INDEX, 'ÉTAPE 2 - Créer ici les pages potentielles à générer. Exemple: mot clé principal "chauffagiste à Lille", service "chauffagiste", ville "Lille", intention "trouver un pro fiable pour une panne ou un entretien". Cliquer sur "Générer avec Claude" pour ce seed seul, ou sur "Générer pages mots clés" pour générer la page principale plus les pages enfants.')
            ->setHelp(Crud::PAGE_NEW, 'Un seed = une page SEO potentielle. Renseigner au minimum le mot clé principal, le service, la ville et l intention utilisateur.')
            ->setHelp(Crud::PAGE_EDIT, 'Depuis ce seed, cliquer sur "Générer avec Claude" pour créer uniquement cette page, ou sur "Générer pages mots clés" pour générer cette page et les pages listées dans Mots clés pages à générer.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $generate = Action::new('generateSeoPage', 'Générer avec Claude', 'fa fa-wand-magic-sparkles')
            ->linkToRoute('admin_seo_seed_generate', static fn (SeoSeed $seed): array => ['id' => $seed->getId()])
            ->displayIf(static fn (SeoSeed $seed): bool => $seed->isValid());

        $generateKeywordPages = Action::new('generateKeywordPages', 'Générer pages mots clés', 'fa fa-sitemap')
            ->linkToRoute('admin_seo_seed_generate_keyword_pages', static fn (SeoSeed $seed): array => ['id' => $seed->getId()])
            ->displayIf(static fn (SeoSeed $seed): bool => $seed->isValid() && count($seed->getPageKeywords()) > 0);

        return $actions
            ->add(Crud::PAGE_INDEX, $generate)
            ->add(Crud::PAGE_INDEX, $generateKeywordPages)
            ->add(Crud::PAGE_EDIT, $generate)
            ->add(Crud::PAGE_EDIT, $generateKeywordPages);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('mainKeyword')
            ->add('service')
            ->add('city')
            ->add('department')
            ->add('locale')
            ->add('claudeModelPreference')
            ->add('valid');
    }
}
