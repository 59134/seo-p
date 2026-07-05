<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260629113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout des textes dynamiques du template SEO programmatique.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seo_page ADD template_copy JSON DEFAULT NULL');
        $this->addSql("UPDATE seo_page SET template_copy = '{}' WHERE template_copy IS NULL");
        $this->addSql('ALTER TABLE seo_page CHANGE template_copy template_copy JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seo_page DROP template_copy');
    }
}
