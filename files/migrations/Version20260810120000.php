<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260810120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les index de lecture publique et de maillage du module SEO programmatique.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_seo_page_public ON seo_page (status, indexable, locale, quality_score)');
        $this->addSql('CREATE INDEX idx_seo_page_seed_status ON seo_page (seed_id, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_seo_page_public ON seo_page');
        $this->addSql('DROP INDEX idx_seo_page_seed_status ON seo_page');
    }
}
