<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SCRUM-262 / SCRUM-282: Módulo completo de logros.
 *
 * Crea las tablas del catálogo de condiciones, umbrales y logros,
 * agrega earned_achievements (JSON) a user para almacenar logros ganados,
 * y agrega notify_special y show_progress (TINYINT) a achievement.
 */
final class Version20260511000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SCRUM-262/SCRUM-282: Módulo de logros — tablas achievement, condition catalog, threshold options, achievement_badge (con seed) + earned_achievements en user + notify_special en achievement.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on MySQL.'
        );

        // ── 1. achievement_condition_catalog ──────────────────────────────────

        if (!$this->tableExists('achievement_condition_catalog')) {
            $this->addSql(
                'CREATE TABLE `achievement_condition_catalog` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `category_key` VARCHAR(64) NOT NULL,
                    `condition_key` VARCHAR(64) NOT NULL,
                    `condition_label` VARCHAR(128) NOT NULL,
                    `threshold_type` VARCHAR(16) NOT NULL,
                    `allows_custom_value` TINYINT(1) NOT NULL DEFAULT 1,
                    `min_value` NUMERIC(12, 2) DEFAULT NULL,
                    `max_value` NUMERIC(12, 2) DEFAULT NULL,
                    `active` TINYINT(1) NOT NULL DEFAULT 1,
                    `sort_order` INT UNSIGNED NOT NULL DEFAULT 100,
                    `created_at` DATETIME DEFAULT NULL,
                    `updated_at` DATETIME DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE INDEX `uq_condition` (`category_key`, `condition_key`),
                    INDEX `idx_condition_active` (`active`, `category_key`, `sort_order`)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
            );
        }

        // ── 2. achievement ────────────────────────────────────────────────────

        if (!$this->tableExists('achievement')) {
            $this->addSql(
                'CREATE TABLE `achievement` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `name` VARCHAR(128) NOT NULL,
                    `description` VARCHAR(255) DEFAULT NULL,
                    `category_key` VARCHAR(64) NOT NULL,
                    `condition_key` VARCHAR(64) NOT NULL,
                    `condition_context` JSON DEFAULT NULL,
                    `threshold_type` VARCHAR(16) NOT NULL,
                    `target_value` NUMERIC(12, 2) NOT NULL,
                    `comparison_operator` VARCHAR(3) NOT NULL DEFAULT "gte",
                    `reward_type` VARCHAR(32) NOT NULL,
                    `reward_value` NUMERIC(12, 2) DEFAULT NULL,
                    `badge_level` VARCHAR(64) DEFAULT NULL,
                    `badge_color` VARCHAR(32) DEFAULT NULL,
                    `badge_icon` VARCHAR(16) DEFAULT NULL,
                    `badge_label` VARCHAR(64) DEFAULT NULL,
                    `period_type` VARCHAR(16) NOT NULL DEFAULT "none",
                    `period_days` INT UNSIGNED DEFAULT NULL,
                    `period_deadline` DATE DEFAULT NULL,
                    `period_window_start` DATE DEFAULT NULL,
                    `is_visible_profile` TINYINT(1) NOT NULL DEFAULT 1,
                    `notify_in_app` TINYINT(1) NOT NULL DEFAULT 1,
                    `notify_special` TINYINT(1) NOT NULL DEFAULT 0,
                    `show_progress` TINYINT(1) NOT NULL DEFAULT 0,
                    `include_historical_data` TINYINT(1) NOT NULL DEFAULT 0,
                    `difficulty` VARCHAR(32) DEFAULT NULL COMMENT "NULL = logro normal; NOT NULL = es un reto contable por challenges_completed",
                    `sort_order` INT UNSIGNED NOT NULL DEFAULT 100,
                    `active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_by_id` INT DEFAULT NULL,
                    `updated_by_id` INT DEFAULT NULL,
                    `created_at` DATETIME DEFAULT NULL,
                    `updated_at` DATETIME DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    INDEX `idx_achievement_active_sort` (`active`, `sort_order`),
                    INDEX `idx_achievement_metric` (`category_key`, `condition_key`, `threshold_type`),
                    INDEX `idx_achievement_reward` (`reward_type`, `active`),
                    INDEX `idx_achievement_created_by` (`created_by_id`),
                    INDEX `idx_achievement_updated_by` (`updated_by_id`)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
            );
        }

        // ── 3. achievement_threshold_option ───────────────────────────────────

        if (!$this->tableExists('achievement_threshold_option')) {
            $this->addSql(
                'CREATE TABLE `achievement_threshold_option` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `condition_id` INT UNSIGNED NOT NULL,
                    `option_value` NUMERIC(12, 2) NOT NULL,
                    `option_label` VARCHAR(64) DEFAULT NULL,
                    `sort_order` INT UNSIGNED NOT NULL DEFAULT 100,
                    `active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` DATETIME DEFAULT NULL,
                    `updated_at` DATETIME DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    INDEX `idx_threshold_condition` (`condition_id`, `active`, `sort_order`),
                    CONSTRAINT `fk_threshold_condition`
                        FOREIGN KEY (`condition_id`) REFERENCES `achievement_condition_catalog` (`id`) ON DELETE CASCADE
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
            );
        }

        // ── 4. achievement_badge ───────────────────────────────────────────────

        if (!$this->tableExists('achievement_badge')) {
            $this->addSql(
                'CREATE TABLE achievement_badge (
                    id          INT AUTO_INCREMENT NOT NULL,
                    badge_key   VARCHAR(64)  NOT NULL,
                    icon        VARCHAR(32)  NOT NULL,
                    name        VARCHAR(64)  NOT NULL,
                    default_pts INT          NOT NULL DEFAULT 0,
                    color       VARCHAR(16)  NOT NULL,
                    badge_group VARCHAR(64)  NOT NULL,
                    sort_order  INT          NOT NULL DEFAULT 0,
                    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
                    UNIQUE INDEX UNIQ_BADGE_KEY (badge_key),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            );
            // Datos de badges gestionados exclusivamente por bin/seed-achievements.php
        }

        // ── 5. user.earned_achievements ───────────────────────────────────────

        if (!$this->columnExists('user', 'earned_achievements')) {
            $this->addSql("ALTER TABLE user ADD earned_achievements JSON NOT NULL COMMENT '(DC2Type:json)'");
            $this->addSql("UPDATE user SET earned_achievements = JSON_ARRAY() WHERE JSON_TYPE(earned_achievements) != 'ARRAY'");
        }

        // ── 7. user.achievement_progress ──────────────────────────────────────
        // Almacena el progreso parcial de logros aún no ganados.
        // Estructura: { "<achievementId>": <currentValue>, ... }

        if (!$this->columnExists('user', 'achievement_progress')) {
            $this->addSql("ALTER TABLE user ADD achievement_progress JSON COMMENT '(DC2Type:json)'");
            $this->addSql("UPDATE user SET achievement_progress = JSON_OBJECT()");
            $this->addSql("ALTER TABLE user MODIFY achievement_progress JSON NOT NULL COMMENT '(DC2Type:json)'");
        }

        // ── 8. achievement FKs (created_by_id / updated_by_id → user) ────────────
        // INT (signed) en ambos lados para que MySQL acepte el FK sin type mismatch.

        if (!$this->foreignKeyExists('achievement', 'FK_96737FF1B03A8386')) {
            $this->addSql(
                'ALTER TABLE `achievement` ADD CONSTRAINT `FK_96737FF1B03A8386`
                 FOREIGN KEY (`created_by_id`) REFERENCES `user` (`id`) ON DELETE SET NULL'
            );
        }

        if (!$this->foreignKeyExists('achievement', 'FK_96737FF1896DBBDE')) {
            $this->addSql(
                'ALTER TABLE `achievement` ADD CONSTRAINT `FK_96737FF1896DBBDE`
                 FOREIGN KEY (`updated_by_id`) REFERENCES `user` (`id`) ON DELETE SET NULL'
            );
        }

        // ── 9. achievement.period_window_start (PERIOD_TYPE_WINDOW) ──────────
        // Added after initial release. Idempotent: skipped if column already exists
        // (e.g. table was created fresh from the CREATE TABLE above).

        if ($this->tableExists('achievement') && !$this->columnExists('achievement', 'period_window_start')) {
            $this->addSql('ALTER TABLE `achievement` ADD `period_window_start` DATE DEFAULT NULL AFTER `period_deadline`');
        }

        // ── 6. user.anniversary_class_history — ya no se utiliza ──────────────

        if ($this->columnExists('user', 'anniversary_class_history')) {
            $this->addSql('ALTER TABLE user DROP COLUMN anniversary_class_history');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on MySQL.'
        );

        if ($this->columnExists('user', 'achievement_progress')) {
            $this->addSql('ALTER TABLE user DROP COLUMN achievement_progress');
        }

        if ($this->columnExists('user', 'earned_achievements')) {
            $this->addSql('ALTER TABLE user DROP COLUMN earned_achievements');
        }

        if (!$this->columnExists('user', 'anniversary_class_history')) {
            $this->addSql("ALTER TABLE user ADD anniversary_class_history JSON NOT NULL COMMENT '(DC2Type:json)'");
            $this->addSql('UPDATE user SET anniversary_class_history = JSON_ARRAY()');
        }

        if ($this->tableExists('achievement_badge')) {
            $this->addSql('DROP TABLE achievement_badge');
        }

        if ($this->tableExists('achievement_threshold_option')) {
            $this->addSql('DROP TABLE `achievement_threshold_option`');
        }

        if ($this->tableExists('achievement')) {
            // Eliminar FKs primero para no violar integridad referencial al DROP
            if ($this->foreignKeyExists('achievement', 'FK_96737FF1896DBBDE')) {
                $this->addSql('ALTER TABLE `achievement` DROP FOREIGN KEY `FK_96737FF1896DBBDE`');
            }
            if ($this->foreignKeyExists('achievement', 'FK_96737FF1B03A8386')) {
                $this->addSql('ALTER TABLE `achievement` DROP FOREIGN KEY `FK_96737FF1B03A8386`');
            }
            $this->addSql('DROP TABLE `achievement`');
        }

        if ($this->tableExists('achievement_condition_catalog')) {
            $this->addSql('DROP TABLE `achievement_condition_catalog`');
        }
    }

    private function tableExists(string $tableName): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(1) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :t',
            ['t' => $tableName]
        );
    }

    private function columnExists(string $tableName, string $columnName): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(1) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c',
            ['t' => $tableName, 'c' => $columnName]
        );
    }

    private function foreignKeyExists(string $tableName, string $constraintName): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(1) FROM information_schema.table_constraints
             WHERE table_schema = DATABASE()
               AND table_name = :t
               AND constraint_name = :c
               AND constraint_type = "FOREIGN KEY"',
            ['t' => $tableName, 'c' => $constraintName]
        );
    }
}
