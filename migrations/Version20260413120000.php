<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260413120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SCRUM-211: Create notification table for in-app notification center';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on mysql.'
        );

        $tableExists = (int) $this->connection->fetchOne(
            'SELECT COUNT(1)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :tableName',
            ['tableName' => 'notification']
        );

        if (0 === $tableExists) {
            $this->addSql(
                'CREATE TABLE `notification` (
                    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id`    INT NOT NULL,
                    `type`       VARCHAR(64) NOT NULL,
                    `title`      VARCHAR(255) NOT NULL,
                    `body`       LONGTEXT NOT NULL,
                    `payload`    JSON DEFAULT NULL,
                    `priority`   VARCHAR(16) NOT NULL DEFAULT \'medium\',
                    `read_at`    DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                    `created_at` DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                    PRIMARY KEY (`id`),
                    INDEX `notification_user_read_idx` (`user_id`, `read_at`),
                    CONSTRAINT `FK_notification_user`
                        FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
                        ON DELETE CASCADE
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on mysql.'
        );

        $tableExists = (int) $this->connection->fetchOne(
            'SELECT COUNT(1)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :tableName',
            ['tableName' => 'notification']
        );

        if ($tableExists > 0) {
            $this->addSql('DROP TABLE `notification`');
        }
    }
}
