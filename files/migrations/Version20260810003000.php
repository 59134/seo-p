<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260810003000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout de l image SEO reelle, de son texte alternatif et de sa provenance.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seo_page ADD image_url VARCHAR(500) DEFAULT NULL, ADD image_alt VARCHAR(255) DEFAULT NULL, ADD image_source VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seo_page DROP image_url, DROP image_alt, DROP image_source');
    }
}
