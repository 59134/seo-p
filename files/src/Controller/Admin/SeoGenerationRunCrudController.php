<?php

namespace App\Controller\Admin;

use App\Entity\SeoGenerationRun;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class SeoGenerationRunCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return SeoGenerationRun::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            FormField::addPanel('Mode d emploi')
                ->setHelp('Cet historique sert au diagnostic: modele utilise, statut, tokens, erreurs Claude et payloads. Il ne sert pas a publier; la publication se fait depuis Pages SEO.'),
            IdField::new('id')->hideOnForm(),
            AssociationField::new('seed', 'Seed'),
            AssociationField::new('page', 'Page'),
            TextField::new('provider', 'Provider'),
            TextField::new('model', 'Modele'),
            TextField::new('status', 'Statut'),
            TextField::new('promptHash', 'Hash prompt')->hideOnIndex(),
            IntegerField::new('maxTokens', 'Max tokens')->hideOnForm()->setSortable(false),
            IntegerField::new('inputTokens', 'Tokens input'),
            IntegerField::new('outputTokens', 'Tokens output'),
            TextField::new('stopReason', 'Stop reason')->hideOnForm()->setSortable(false),
            TextareaField::new('errorMessage', 'Erreur')
                ->setRequired(false)
                ->hideOnIndex(),
            ArrayField::new('requestPayload', 'Payload requete')->hideOnIndex(),
            ArrayField::new('responsePayload', 'Payload reponse')->hideOnIndex(),
            DateTimeField::new('createdAt', 'Creation'),
        ];
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle(Crud::PAGE_INDEX, '5. Historique generations SEO')
            ->setEntityLabelInSingular('Generation SEO')
            ->setEntityLabelInPlural('Generations SEO')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setHelp(Crud::PAGE_INDEX, 'ETAPE 5 DIAGNOSTIC - Ici tu vois les appels Claude: modele utilise, statut, tokens, erreurs et reponses. Ce module sert a comprendre ce qui s est passe, pas a publier.')
            ->setHelp(Crud::PAGE_DETAIL, 'Detail technique de generation Claude.');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('status')
            ->add('provider')
            ->add('model')
            ->add('createdAt');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->remove(Crud::PAGE_INDEX, Action::NEW)
            ->remove(Crud::PAGE_INDEX, Action::EDIT)
            ->remove(Crud::PAGE_INDEX, Action::DELETE);
    }
}
