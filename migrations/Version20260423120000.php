<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SCRUM-231 — Congelar / Descongelar paquetes activos con auditoría.
 *
 * up:
 *   1. Agrega is_frozen, frozen_at, frozen_days_remaining a transaction.
 *   2. Crea tabla transaction_freeze_log para el historial de acciones freeze/unfreeze.
 *
 * down: revierte ambos cambios.
 */
final class Version20260423120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SCRUM-231: agrega soporte de congelación a transaction y crea tabla transaction_freeze_log';
    }

    public function up(Schema $schema): void
    {
        // 1. Columnas de estado de congelación en la tabla transaction
        $this->addSql(
            'ALTER TABLE transaction
             ADD is_frozen       TINYINT(1)   NOT NULL DEFAULT 0     AFTER is_expired,
             ADD frozen_at       DATETIME     DEFAULT NULL            AFTER is_frozen,
             ADD frozen_days_remaining INT    DEFAULT NULL            AFTER frozen_at,
             ADD frozen_seconds_remaining INT DEFAULT NULL            AFTER frozen_days_remaining'
        );

        // 2. Tabla de auditoría de freeze/unfreeze
        $this->addSql(
            'CREATE TABLE transaction_freeze_log (
                id               INT          AUTO_INCREMENT NOT NULL,
                transaction_id   INT          NOT NULL,
                staff_id         INT          NOT NULL,
                action           VARCHAR(20)  NOT NULL COMMENT \'freeze|unfreeze\',
                reason           LONGTEXT     NOT NULL,
                original_expiration_at DATETIME DEFAULT NULL,
                days_remaining   INT          DEFAULT NULL,
                remaining_seconds INT         DEFAULT NULL,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_tfl_transaction  (transaction_id),
                INDEX idx_tfl_staff        (staff_id),
                INDEX idx_tfl_action       (action),
                INDEX idx_tfl_created_at   (created_at),
                CONSTRAINT fk_tfl_transaction
                    FOREIGN KEY (transaction_id) REFERENCES transaction (id) ON DELETE CASCADE,
                CONSTRAINT fk_tfl_staff
                    FOREIGN KEY (staff_id)       REFERENCES staff (id)       ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        // Eliminar primero la tabla hija para no violar FK
        $this->addSql('DROP TABLE IF EXISTS transaction_freeze_log');

        $table = $schema->getTable('transaction');

        if ($table->hasColumn('is_frozen')) {
            $this->addSql('ALTER TABLE transaction DROP COLUMN is_frozen');
        }

        if ($table->hasColumn('frozen_at')) {
            $this->addSql('ALTER TABLE transaction DROP COLUMN frozen_at');
        }

        if ($table->hasColumn('frozen_days_remaining')) {
            $this->addSql('ALTER TABLE transaction DROP COLUMN frozen_days_remaining');
        }

        if ($table->hasColumn('frozen_seconds_remaining')) {
            $this->addSql('ALTER TABLE transaction DROP COLUMN frozen_seconds_remaining');
        }
    }
}
