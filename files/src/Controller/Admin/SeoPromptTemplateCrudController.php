<?php

namespace App\Controller\Admin;

use App\Entity\SeoPromptTemplate;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class SeoPromptTemplateCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return SeoPromptTemplate::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            FormField::addPanel('Mode d emploi')
                ->setHelp('Les templates permettent de personnaliser les consignes envoyees a Claude. Garder {{context_json}} dans le prompt utilisateur pour injecter les donnees SEO. Un seul template actif par type/langue est recommande.'),
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom')->setColumns(6),
            TextField::new('type', 'Type')->setColumns(3),
            TextField::new('locale', 'Langue')->setColumns(3),
            BooleanField::new('active', 'Actif'),
            TextareaField::new('systemPrompt', 'Prompt systeme')
                ->setRequired(false)
                ->setColumns(12),
            TextareaField::new('userPrompt', 'Prompt utilisateur')
                ->setRequired(false)
                ->setColumns(12)
                ->setHelp('Utiliser {{context_json}} pour injecter le contexte genere.'),
            DateTimeField::new('updated_at', 'Modifie')->hideOnForm(),
            DateTimeField::new('created_at', 'Creation')->hideOnForm(),
        ];
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle(Crud::PAGE_INDEX, '4. Prompts SEO')
            ->setEntityLabelInSingular('Template SEO')
            ->setEntityLabelInPlural('Templates SEO')
            ->setHelp(Crud::PAGE_INDEX, 'ETAPE 4 AVANCEE - Optionnel. Les prompts servent a personnaliser les consignes donnees a Claude. Tu peux laisser vide au debut: le module contient deja un prompt par defaut.')
            ->setHelp(Crud::PAGE_NEW, 'Creer un prompt seulement si tu veux changer le style ou les consignes SEO. Garder {{context_json}} dans le prompt utilisateur.')
            ->setHelp(Crud::PAGE_EDIT, 'Un seul prompt actif par type/langue est recommande. Si aucun prompt actif n existe, le prompt par defaut du module est utilise.');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('name')
            ->add('type')
            ->add('locale')
            ->add('active');
    }
}
