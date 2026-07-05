<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260626100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le lien vers la page prestation liee sur les seeds SEO.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seo_seed ADD service_page_url VARCHAR(255) DEFAULT NULL, ADD service_page_label VARCHAR(150) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seo_seed DROP service_page_url, DROP service_page_label');
    }
}
