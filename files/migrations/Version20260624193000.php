<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260624193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout du module SEO programmatique avec seeds, faits, prompts, pages et historique Claude.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE seo_fact (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, type VARCHAR(80) NOT NULL, locale VARCHAR(5) NOT NULL, content LONGTEXT NOT NULL, valid TINYINT(1) NOT NULL, priority INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE seo_seed (id INT AUTO_INCREMENT NOT NULL, service VARCHAR(180) NOT NULL, city VARCHAR(120) DEFAULT NULL, department VARCHAR(120) DEFAULT NULL, main_keyword VARCHAR(255) NOT NULL, secondary_keywords JSON NOT NULL, intent VARCHAR(255) DEFAULT NULL, business_value INT NOT NULL, data_completeness_score INT NOT NULL, locale VARCHAR(5) NOT NULL, priority INT NOT NULL, valid TINYINT(1) NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE seo_prompt_template (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, type VARCHAR(50) NOT NULL, locale VARCHAR(5) NOT NULL, system_prompt LONGTEXT NOT NULL, user_prompt LONGTEXT DEFAULT NULL, active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE seo_page (id INT AUTO_INCREMENT NOT NULL, seed_id INT DEFAULT NULL, slug VARCHAR(180) NOT NULL, locale VARCHAR(5) NOT NULL, title VARCHAR(70) NOT NULL, meta_description VARCHAR(180) NOT NULL, h1 VARCHAR(180) NOT NULL, intro LONGTEXT DEFAULT NULL, content JSON NOT NULL, faq JSON NOT NULL, schema_json JSON NOT NULL, internal_links JSON NOT NULL, image_alt_suggestions JSON NOT NULL, cta VARCHAR(120) DEFAULT NULL, quality_flags JSON NOT NULL, missing_data JSON NOT NULL, raw_claude_response LONGTEXT DEFAULT NULL, status VARCHAR(30) NOT NULL, indexable TINYINT(1) NOT NULL, canonical_url VARCHAR(255) DEFAULT NULL, main_keyword VARCHAR(255) DEFAULT NULL, quality_score INT NOT NULL, generated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', published_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_seo_page_slug_locale (slug, locale), INDEX IDX_3A6DA9B1D5E258C5 (seed_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE seo_generation_run (id INT AUTO_INCREMENT NOT NULL, seed_id INT DEFAULT NULL, page_id INT DEFAULT NULL, provider VARCHAR(50) NOT NULL, model VARCHAR(100) NOT NULL, prompt_hash VARCHAR(64) NOT NULL, request_payload JSON NOT NULL, response_payload JSON DEFAULT NULL, status VARCHAR(30) NOT NULL, input_tokens INT DEFAULT NULL, output_tokens INT DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_84657359D5E258C5 (seed_id), INDEX IDX_84657359C4663E4 (page_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE seo_page ADD CONSTRAINT FK_3A6DA9B1D5E258C5 FOREIGN KEY (seed_id) REFERENCES seo_seed (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE seo_generation_run ADD CONSTRAINT FK_84657359D5E258C5 FOREIGN KEY (seed_id) REFERENCES seo_seed (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE seo_generation_run ADD CONSTRAINT FK_84657359C4663E4 FOREIGN KEY (page_id) REFERENCES seo_page (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seo_generation_run DROP FOREIGN KEY FK_84657359D5E258C5');
        $this->addSql('ALTER TABLE seo_generation_run DROP FOREIGN KEY FK_84657359C4663E4');
        $this->addSql('ALTER TABLE seo_page DROP FOREIGN KEY FK_3A6DA9B1D5E258C5');
        $this->addSql('DROP TABLE seo_generation_run');
        $this->addSql('DROP TABLE seo_page');
        $this->addSql('DROP TABLE seo_prompt_template');
        $this->addSql('DROP TABLE seo_seed');
        $this->addSql('DROP TABLE seo_fact');
    }
}
