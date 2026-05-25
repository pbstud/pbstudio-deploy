<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260420183332 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('home_content')) {
            $this->addSql('CREATE TABLE home_content (id INT AUTO_INCREMENT NOT NULL, banner_desktop VARCHAR(150) DEFAULT NULL, banner_mobile VARCHAR(150) DEFAULT NULL, box1_image VARCHAR(150) DEFAULT NULL, box1_title VARCHAR(150) DEFAULT NULL, box1_description VARCHAR(255) DEFAULT NULL, box1_url VARCHAR(255) DEFAULT NULL, box1_link_label VARCHAR(120) DEFAULT NULL, box2_image VARCHAR(150) DEFAULT NULL, box2_title VARCHAR(150) DEFAULT NULL, box2_description VARCHAR(255) DEFAULT NULL, box2_url VARCHAR(255) DEFAULT NULL, box2_link_label VARCHAR(120) DEFAULT NULL, contact_email VARCHAR(255) DEFAULT NULL, contact_facebook VARCHAR(255) DEFAULT NULL, contact_instagram VARCHAR(255) DEFAULT NULL, contact_whatsapp VARCHAR(255) DEFAULT NULL, updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS home_content');
    }

    private function tableExists(string $tableName): bool
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(1) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t',
            ['t' => $tableName]
        );

        return $count > 0;
    }
}
