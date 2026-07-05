<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260624210000 extends AbstractMigration
{
    private const SEO_ROLES_JSON = '["ROLE_ADMIN","ROLE_MODO","ROLE_REF","ROLE_DEV"]';

    public function getDescription(): string
    {
        return 'Ajoute les modules SEO programmatiques dans la table module avec les droits admin, modo, ref et dev.';
    }

    public function up(Schema $schema): void
    {
        $this->insertModule('SeoFact', '1. Faits verifies', 'fa fa-shield');
        $this->insertModule('SeoSeed', '2. Seeds SEO', 'fa fa-list');
        $this->insertModule('SeoPage', '3. Pages SEO', 'fa fa-search');
        $this->insertModule('SeoPromptTemplate', '4. Prompts SEO', 'fa fa-terminal');
        $this->insertModule('SeoGenerationRun', '5. Historique Claude', 'fa fa-history');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM `module` WHERE `name` IN ('SeoFact', 'SeoSeed', 'SeoPage', 'SeoPromptTemplate', 'SeoGenerationRun') AND `section` = 'SEO programmatique'");
    }

    private function insertModule(string $name, string $title, string $icon): void
    {
        $roles = self::SEO_ROLES_JSON;
        $this->addSql("
            INSERT INTO `module` (`name`, `valid`, `section`, `icon`, `title`, `see`, `created`, `updated`, `deleted`)
            SELECT '$name', 1, 'SEO programmatique', '$icon', '$title', '$roles', '$roles', '$roles', '$roles'
            WHERE NOT EXISTS (
                SELECT 1 FROM `module` WHERE `name` = '$name'
            )
        ");
    }
}
