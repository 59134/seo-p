<?php

namespace App\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;

trait SeoCrudPermissionsTrait
{
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyAccessUnlessGranted('m_create', static::getEntityFqcn());
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyAccessUnlessGranted('m_edit', static::getEntityFqcn());
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyAccessUnlessGranted('m_delete', static::getEntityFqcn());
        parent::deleteEntity($entityManager, $entityInstance);
    }
}
