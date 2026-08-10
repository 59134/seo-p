<?php

namespace App\Controller\Admin;

use App\Entity\SeoFact;
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

class SeoFactCrudController extends AbstractCrudController
{
    use SeoCrudPermissionsTrait;

    public static function getEntityFqcn(): string
    {
        return SeoFact::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            FormField::addPanel('Mode d emploi')
                ->setHelp('Les faits verifies sont la base donnee a Claude. Ajouter uniquement des informations vraies: services, zones couvertes, marques, garanties, process, limites et preuves. Claude ne doit pas inventer ce qui manque.'),
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom')
                ->setHelp('Nom court pour reconnaitre le fait dans l admin. Exemple: Zone Lille, Devis, Entretien chaudiere.')
                ->setColumns(6),
            ChoiceField::new('type', 'Type')->setChoices([
                'Entreprise' => SeoFact::TYPE_BUSINESS,
                'Service' => SeoFact::TYPE_SERVICE,
                'Local' => SeoFact::TYPE_LOCAL,
                'Preuve' => SeoFact::TYPE_PROOF,
                'Interdit / a ne pas inventer' => SeoFact::TYPE_FORBIDDEN_CLAIM,
                'Marque' => SeoFact::TYPE_BRAND,
                'FAQ' => SeoFact::TYPE_FAQ,
                'Tarif' => SeoFact::TYPE_PRICING,
            ])
                ->setHelp('Categorie du fait. Elle aide Claude a comprendre comment utiliser l information.')
                ->setColumns(6),
            TextField::new('locale', 'Langue')
                ->setHelp('Mettre fr pour le francais. Plus tard, ajouter une version par langue si besoin.')
                ->setColumns(4),
            IntegerField::new('priority', 'Priorite')
                ->setHelp('Importance du fait dans le contexte Claude. 100 = essentiel, 50 = utile, 10 = secondaire.')
                ->setColumns(4),
            BooleanField::new('valid', 'Actif')
                ->setHelp('Actif = ce fait peut etre donne a Claude. Inactif = conserve en base mais ignore.')
                ->setColumns(4),
            TextareaField::new('content', 'Fait verifie')
                ->setRequired(false)
                ->formatValue(static fn (?string $value): string => self::cleanFactPreview($value))
                ->setHelp('Information exacte que Claude peut utiliser. Elle doit etre vraie, claire et sans promesse incertaine.')
                ->setColumns(12),
            DateTimeField::new('updated_at', 'Modifie')->hideOnForm(),
            DateTimeField::new('created_at', 'Creation')->hideOnForm(),
        ];
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle(Crud::PAGE_INDEX, '1. Faits SEO verifies')
            ->setEntityLabelInSingular('Fait SEO')
            ->setEntityLabelInPlural('Faits SEO')
            ->setDefaultSort(['priority' => 'DESC', 'type' => 'ASC'])
            ->setHelp(Crud::PAGE_INDEX, 'ETAPE 1 - Ajouter ici les informations vraies que Claude a le droit d utiliser: services reels, villes couvertes, marques, garanties, preuves, process, limites, prix verifies. Plus ces faits sont precis, meilleures seront les pages.')
            ->setHelp(Crud::PAGE_NEW, 'Ajouter un fait verifie. Ne pas mettre de promesse incertaine: Claude ne doit utiliser que ce qui est vrai.')
            ->setHelp(Crud::PAGE_EDIT, 'Modifier ce fait verifie. Il sera reutilise par Claude pour les prochaines generations.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $import = Action::new('importJson', 'Importer JSON', 'fa fa-file-import')
            ->linkToRoute('admin_seo_import_json', ['type' => 'facts'])
            ->addCssClass('btn btn-info')
            ->createAsGlobalAction();

        if ($this->isGranted('m_create', SeoFact::class)) {
            $actions->add(Crud::PAGE_INDEX, $import);
        } else {
            $actions->remove(Crud::PAGE_INDEX, Action::NEW);
        }

        if (!$this->isGranted('m_edit', SeoFact::class)) {
            $actions
                ->remove(Crud::PAGE_INDEX, Action::EDIT)
                ->remove(Crud::PAGE_EDIT, Action::SAVE_AND_RETURN)
                ->remove(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE);
        }

        if (!$this->isGranted('m_delete', SeoFact::class)) {
            $actions->remove(Crud::PAGE_INDEX, Action::DELETE);
        }

        return $actions;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('name')
            ->add('type')
            ->add('locale')
            ->add('valid');
    }

    private static function cleanFactPreview(?string $value): string
    {
        $value = (string) $value;

        for ($i = 0; $i < 3; $i++) {
            $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($decoded === $value) {
                break;
            }

            $value = $decoded;
        }

        $value = trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?: '');

        if (function_exists('mb_strlen') && mb_strlen($value) > 140) {
            return mb_substr($value, 0, 140) . '...';
        }

        if (!function_exists('mb_strlen') && strlen($value) > 140) {
            return substr($value, 0, 140) . '...';
        }

        return $value;
    }
}
