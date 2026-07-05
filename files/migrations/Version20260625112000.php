<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260625112000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Passe l intention utilisateur des seeds SEO en texte long.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seo_seed CHANGE intent intent LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seo_seed CHANGE intent intent VARCHAR(255) DEFAULT NULL');
    }
}
