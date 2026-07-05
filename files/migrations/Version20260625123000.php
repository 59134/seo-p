<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260625123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les mots cles pages a generer sur les seeds SEO.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seo_seed ADD page_keywords JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seo_seed DROP page_keywords');
    }
}
