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
        $this->addColumnIfNotExists('transaction', 'is_frozen       TINYINT(1)   NOT NULL DEFAULT 0');
        $this->addColumnIfNotExists('transaction', 'frozen_at       DATETIME     DEFAULT NULL');
        $this->addColumnIfNotExists('transaction', 'frozen_days_remaining INT    DEFAULT NULL');
        $this->addColumnIfNotExists('transaction', 'frozen_seconds_remaining INT DEFAULT NULL');

        // 2. Tabla de auditoría de freeze/unfreeze
        if (!$this->tableExists('transaction_freeze_log')) {
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
        } // end if !tableExists
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

    private function tableExists(string $tableName): bool
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(1) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t',
            ['t' => $tableName]
        );

        return $count > 0;
    }

    private function addColumnIfNotExists(string $tableName, string $columnDefinition): void
    {
        // Extraer nombre de columna (primera palabra de la definición)
        $columnName = preg_split('/\s+/', trim($columnDefinition))[0];

        $exists = (int) $this->connection->fetchOne(
            'SELECT COUNT(1) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c',
            ['t' => $tableName, 'c' => $columnName]
        );

        if (0 === $exists) {
            $this->addSql(sprintf('ALTER TABLE `%s` ADD %s', $tableName, $columnDefinition));
        }
    }
}
