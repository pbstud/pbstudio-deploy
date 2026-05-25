<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Correcciones de integridad referencial y seguridad detectadas en auditoría.
 *
 * 1. FK transaction.package_id → package.id ON DELETE SET NULL
 *    La migración 20260427 intentaba agregar este FK pero fallaba silenciosamente
 *    porque buscaba el constraint por nombre Doctrine-generado y no lo encontraba.
 *    Sin este FK, borrar un package deja transaction.package_id como referencia huérfana.
 *
 * 2. staff.password VARCHAR(64) → VARCHAR(255)
 *    bcrypt cabe en 64 chars pero Argon2id (soporte futuro) puede superar ese límite.
 *    Un truncado silencioso rompería la autenticación de todos los staff.
 */
final class Version20260518120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Auditoría BD: FK transaction.package_id ON DELETE SET NULL + staff.password VARCHAR(255)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on MySQL.'
        );

        // ── 1. FK transaction.package_id → package.id ON DELETE SET NULL ─────
        // Eliminar cualquier FK existente sobre package_id (puede tener nombre distinto).

        $existingFk = $this->connection->fetchOne(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :t
               AND COLUMN_NAME = :c
               AND REFERENCED_TABLE_NAME IS NOT NULL',
            ['t' => 'transaction', 'c' => 'package_id']
        );

        if ($existingFk) {
            $this->addSql(sprintf(
                'ALTER TABLE `transaction` DROP FOREIGN KEY `%s`',
                $existingFk
            ));
        }

        // Asegurar que la columna es nullable (prerequisito para ON DELETE SET NULL)
        $this->addSql('ALTER TABLE `transaction` MODIFY `package_id` INT DEFAULT NULL');

        if (!$this->foreignKeyExists('transaction', 'FK_723705D1F44CABFF')) {
            $this->addSql(
                'ALTER TABLE `transaction` ADD CONSTRAINT `FK_723705D1F44CABFF`
                 FOREIGN KEY (`package_id`) REFERENCES `package` (`id`) ON DELETE SET NULL'
            );
        }

        // ── 2. staff.password VARCHAR(64) → VARCHAR(255) ──────────────────────

        $passwordType = $this->connection->fetchOne(
            'SELECT COLUMN_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :t
               AND COLUMN_NAME = :c',
            ['t' => 'staff', 'c' => 'password']
        );

        // ── 3. achievement.is_repeatable → eliminado ────────────────────────
        if ($this->columnExists('achievement', 'is_repeatable')) {
            $this->addSql('ALTER TABLE `achievement` DROP COLUMN `is_repeatable`');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on MySQL.'
        );

        // ── 3. Revertir eliminación de achievement.is_repeatable ─────────────
        if (!$this->columnExists('achievement', 'is_repeatable')) {
            $this->addSql('ALTER TABLE `achievement` ADD COLUMN `is_repeatable` TINYINT(1) NOT NULL DEFAULT 0');
        }

        // ── 2. staff.password VARCHAR(255) → VARCHAR(64) ──────────────────────
        $this->addSql('ALTER TABLE `staff` MODIFY `password` VARCHAR(64) NOT NULL');

        // ── 1. Revertir FK transaction.package_id (sin ON DELETE SET NULL) ────
        if ($this->foreignKeyExists('transaction', 'FK_723705D1F44CABFF')) {
            $this->addSql('ALTER TABLE `transaction` DROP FOREIGN KEY `FK_723705D1F44CABFF`');
        }

        $this->addSql(
            'ALTER TABLE `transaction` ADD CONSTRAINT `FK_723705D1F44CABFF`
             FOREIGN KEY (`package_id`) REFERENCES `package` (`id`)'
        );
    }

    private function columnExists(string $tableName, string $columnName): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(1)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :t
               AND COLUMN_NAME = :c',
            ['t' => $tableName, 'c' => $columnName]
        );
    }

    private function foreignKeyExists(string $tableName, string $constraintName): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(1)
             FROM information_schema.table_constraints
             WHERE table_schema = DATABASE()
               AND table_name = :t
               AND constraint_name = :c
               AND constraint_type = "FOREIGN KEY"',
            ['t' => $tableName, 'c' => $constraintName]
        );
    }
}
