<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260625103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le choix du modele Claude sur les seeds SEO.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE seo_seed ADD claude_model_preference VARCHAR(20) DEFAULT 'auto' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seo_seed DROP claude_model_preference');
    }
}
