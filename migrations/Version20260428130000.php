<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SCRUM-94: create gift_card and gift_card_history tables with constraints and audit indexes';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on mysql.'
        );

        if (!$this->tableExists('gift_card')) {
            $this->addSql(
                'CREATE TABLE `gift_card` (
                    `id` INT NOT NULL AUTO_INCREMENT,
                    `code` VARCHAR(64) NOT NULL,
                    `package_id` INT NOT NULL,
                    `purchaser_user_id` INT NOT NULL,
                    `purchase_transaction_id` INT NOT NULL,
                    `recipient_user_id` INT DEFAULT NULL,
                    `redemption_transaction_id` INT DEFAULT NULL,
                    `status` VARCHAR(20) NOT NULL DEFAULT "generated",
                    `amount_snapshot` NUMERIC(10, 2) NOT NULL,
                    `package_name_snapshot` VARCHAR(255) NOT NULL,
                    `package_type_snapshot` VARCHAR(25) NOT NULL,
                    `package_total_classes_snapshot` INT NOT NULL,
                    `package_days_expiry_snapshot` SMALLINT NOT NULL,
                    `currency_snapshot` VARCHAR(10) NOT NULL DEFAULT "MXN",
                    `origin_channel` VARCHAR(20) NOT NULL,
                    `purchased_at` DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                    `assigned_at` DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)",
                    `redeemed_at` DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)",
                    `gift_expires_at` DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)",
                    `cancelled_at` DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)",
                    `cancellation_reason` LONGTEXT DEFAULT NULL,
                    `created_at` DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                    `updated_at` DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                    PRIMARY KEY (`id`),
                    UNIQUE INDEX `uniq_gift_card_code` (`code`),
                    UNIQUE INDEX `uniq_gift_card_purchase_transaction` (`purchase_transaction_id`),
                    UNIQUE INDEX `uniq_gift_card_redemption_transaction` (`redemption_transaction_id`),
                    INDEX `idx_gift_card_status` (`status`),
                    INDEX `idx_gift_card_origin_channel` (`origin_channel`),
                    INDEX `idx_gift_card_purchaser_user` (`purchaser_user_id`),
                    INDEX `idx_gift_card_recipient_user` (`recipient_user_id`),
                    INDEX `idx_gift_card_package` (`package_id`),
                    CONSTRAINT `fk_gift_card_package`
                        FOREIGN KEY (`package_id`) REFERENCES `package` (`id`) ON DELETE RESTRICT,
                    CONSTRAINT `fk_gift_card_purchaser_user`
                        FOREIGN KEY (`purchaser_user_id`) REFERENCES `user` (`id`) ON DELETE RESTRICT,
                    CONSTRAINT `fk_gift_card_recipient_user`
                        FOREIGN KEY (`recipient_user_id`) REFERENCES `user` (`id`) ON DELETE SET NULL,
                    CONSTRAINT `fk_gift_card_purchase_transaction`
                        FOREIGN KEY (`purchase_transaction_id`) REFERENCES `transaction` (`id`) ON DELETE RESTRICT,
                    CONSTRAINT `fk_gift_card_redemption_transaction`
                        FOREIGN KEY (`redemption_transaction_id`) REFERENCES `transaction` (`id`) ON DELETE SET NULL
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
            );
        }

        if (!$this->tableExists('gift_card_history')) {
            $this->addSql(
                'CREATE TABLE `gift_card_history` (
                    `id` INT NOT NULL AUTO_INCREMENT,
                    `gift_card_id` INT NOT NULL,
                    `action` VARCHAR(40) NOT NULL,
                    `actor_user_id` INT DEFAULT NULL,
                    `actor_staff_id` INT DEFAULT NULL,
                    `transaction_id` INT DEFAULT NULL,
                    `notes` LONGTEXT DEFAULT NULL,
                    `payload_json` JSON DEFAULT NULL,
                    `ip_address` VARCHAR(45) DEFAULT NULL,
                    `user_agent` VARCHAR(255) DEFAULT NULL,
                    `source_route` VARCHAR(255) DEFAULT NULL,
                    `source_context` VARCHAR(20) DEFAULT NULL,
                    `created_at` DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                    PRIMARY KEY (`id`),
                    INDEX `idx_gch_gift_card` (`gift_card_id`),
                    INDEX `idx_gch_action` (`action`),
                    INDEX `idx_gch_created_at` (`created_at`),
                    INDEX `idx_gch_actor_user` (`actor_user_id`),
                    INDEX `idx_gch_actor_staff` (`actor_staff_id`),
                    INDEX `idx_gch_transaction` (`transaction_id`),
                    CONSTRAINT `fk_gch_gift_card`
                        FOREIGN KEY (`gift_card_id`) REFERENCES `gift_card` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_gch_actor_user`
                        FOREIGN KEY (`actor_user_id`) REFERENCES `user` (`id`) ON DELETE SET NULL,
                    CONSTRAINT `fk_gch_actor_staff`
                        FOREIGN KEY (`actor_staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
                    CONSTRAINT `fk_gch_transaction`
                        FOREIGN KEY (`transaction_id`) REFERENCES `transaction` (`id`) ON DELETE SET NULL
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

        if ($this->tableExists('gift_card_history')) {
            $this->addSql('DROP TABLE `gift_card_history`');
        }

        if ($this->tableExists('gift_card')) {
            $this->addSql('DROP TABLE `gift_card`');
        }
    }

    private function tableExists(string $tableName): bool
    {
        $exists = (int) $this->connection->fetchOne(
            'SELECT COUNT(1)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :tableName',
            ['tableName' => $tableName]
        );

        return $exists > 0;
    }
}
