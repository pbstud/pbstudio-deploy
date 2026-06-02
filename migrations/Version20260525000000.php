<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SCRUM-302 — Sync schema after Symfony 7 upgrade
 *
 * Safe / idempotent: all RENAME INDEX and DROP INDEX operations check existence first
 * so the migration runs correctly whether the DB was built via migrations (custom names)
 * or via doctrine:schema:create (Doctrine-generated names).
 *
 * Changes:
 *  - achievement: ensure difficulty DEFAULT NULL; rename FK indexes to Doctrine-generated names
 *  - achievement_badge: add NOT NULL to default_pts, sort_order, is_active; rename unique index
 *  - achievement_condition_catalog: drop manual unique index (uniqueness enforced by ORM)
 *  - package: drop manual index idx_package_has_restrictions
 *  - transaction: drop manual index; add NOT NULL to is_frozen; add FK constraint for package_id
 *  - transaction_freeze_log: add NOT NULL to action
 *  - user: drop manual index idx_user_enabled_anniversary
 *  - messenger_messages: replace 3 individual indexes with 1 composite index (SF7)
 */
final class Version20260525000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SCRUM-302: sync schema after SF7 upgrade — index cleanup, NOT NULL constraints, FK constraints, messenger_messages SF7 index';
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function getDb(): string
    {
        return (string) $this->connection->fetchOne('SELECT DATABASE()');
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$this->getDb(), $table, $indexName]
        );
    }

    private function foreignKeyExists(string $table, string $fkName): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.table_constraints
             WHERE table_schema = ? AND table_name = ? AND constraint_name = ? AND constraint_type = ?',
            [$this->getDb(), $table, $fkName, 'FOREIGN KEY']
        );
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ? AND column_name = ?',
            [$this->getDb(), $table, $column]
        );
    }

    // ── Up ───────────────────────────────────────────────────────────────────

    public function up(Schema $schema): void
    {
        // ── achievement: difficulty DEFAULT NULL ─────────────────────────────
        $this->addSql('ALTER TABLE achievement CHANGE difficulty difficulty VARCHAR(32) DEFAULT NULL');

        // ── achievement: rename FK indexes (only if old name exists) ─────────
        if ($this->indexExists('achievement', 'idx_achievement_created_by')) {
            $this->addSql('ALTER TABLE achievement RENAME INDEX idx_achievement_created_by TO IDX_96737FF1B03A8386');
        }
        if ($this->indexExists('achievement', 'idx_achievement_updated_by')) {
            $this->addSql('ALTER TABLE achievement RENAME INDEX idx_achievement_updated_by TO IDX_96737FF1896DBBDE');
        }

        // ── achievement_badge: NOT NULL columns ──────────────────────────────
        $this->addSql('ALTER TABLE achievement_badge CHANGE default_pts default_pts INT NOT NULL, CHANGE sort_order sort_order INT NOT NULL, CHANGE is_active is_active TINYINT(1) NOT NULL');

        // ── achievement_badge: rename unique index (only if old name exists) ─
        if ($this->indexExists('achievement_badge', 'uniq_badge_key')) {
            $this->addSql('ALTER TABLE achievement_badge RENAME INDEX uniq_badge_key TO UNIQ_5190D21355B8DFCF');
        }
        if ($this->indexExists('achievement_badge', 'UNIQ_BADGE_KEY')) {
            $this->addSql('ALTER TABLE achievement_badge RENAME INDEX UNIQ_BADGE_KEY TO UNIQ_5190D21355B8DFCF');
        }

        // ── achievement_condition_catalog: drop manual index ─────────────────
        if ($this->indexExists('achievement_condition_catalog', 'uq_condition')) {
            $this->addSql('DROP INDEX uq_condition ON achievement_condition_catalog');
        }

        // ── package: drop manual index ───────────────────────────────────────
        if ($this->indexExists('package', 'idx_package_has_restrictions')) {
            $this->addSql('DROP INDEX idx_package_has_restrictions ON package');
        }

        // ── transaction: drop manual index ───────────────────────────────────
        if ($this->indexExists('transaction', 'idx_transaction_package_has_restrictions')) {
            $this->addSql('DROP INDEX idx_transaction_package_has_restrictions ON transaction');
        }

        // ── transaction: is_frozen NOT NULL ──────────────────────────────────
        $this->addSql('ALTER TABLE transaction CHANGE is_frozen is_frozen TINYINT(1) NOT NULL');

        // ── transaction: FK package_id ON DELETE SET NULL ─────────────────────
        if (!$this->foreignKeyExists('transaction', 'FK_723705D1F44CABFF')) {
            $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_723705D1F44CABFF FOREIGN KEY (package_id) REFERENCES package (id) ON DELETE SET NULL');
        }

        // ── transaction_freeze_log: action NOT NULL ───────────────────────────
        $this->addSql('ALTER TABLE transaction_freeze_log CHANGE action action VARCHAR(20) NOT NULL');

        // ── user: drop manual index ───────────────────────────────────────────
        if ($this->indexExists('user', 'idx_user_enabled_anniversary')) {
            $this->addSql('DROP INDEX idx_user_enabled_anniversary ON user');
        }

        // ── messenger_messages: SF7 composite index ───────────────────────────
        if ($this->indexExists('messenger_messages', 'IDX_75EA56E0E3BD61CE')) {
            $this->addSql('DROP INDEX IDX_75EA56E0E3BD61CE ON messenger_messages');
        }
        if ($this->indexExists('messenger_messages', 'IDX_75EA56E0FB7336F0')) {
            $this->addSql('DROP INDEX IDX_75EA56E0FB7336F0 ON messenger_messages');
        }
        if ($this->indexExists('messenger_messages', 'IDX_75EA56E016BA31DB')) {
            $this->addSql('DROP INDEX IDX_75EA56E016BA31DB ON messenger_messages');
        }
        if (!$this->indexExists('messenger_messages', 'IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750')) {
            $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
        }
    }

    // ── Down ─────────────────────────────────────────────────────────────────

    public function down(Schema $schema): void
    {
        // ── messenger_messages ────────────────────────────────────────────────
        if ($this->indexExists('messenger_messages', 'IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750')) {
            $this->addSql('DROP INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages');
        }
        if (!$this->indexExists('messenger_messages', 'IDX_75EA56E0E3BD61CE')) {
            $this->addSql('CREATE INDEX IDX_75EA56E0E3BD61CE ON messenger_messages (available_at)');
        }
        if (!$this->indexExists('messenger_messages', 'IDX_75EA56E0FB7336F0')) {
            $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0 ON messenger_messages (queue_name)');
        }
        if (!$this->indexExists('messenger_messages', 'IDX_75EA56E016BA31DB')) {
            $this->addSql('CREATE INDEX IDX_75EA56E016BA31DB ON messenger_messages (delivered_at)');
        }

        // ── user ──────────────────────────────────────────────────────────────
        // Nota: este índice original referenciaba columnas que ya no existen en user
        // (is_enabled → renombrada a 'enabled'; anniversary_month/anniversary_day → eliminadas).
        // Solo se recrea si todas las columnas existen para evitar fallo en down().
        if (
            !$this->indexExists('user', 'idx_user_enabled_anniversary')
            && $this->columnExists('user', 'enabled')
            && $this->columnExists('user', 'anniversary_month')
            && $this->columnExists('user', 'anniversary_day')
        ) {
            $this->addSql('CREATE INDEX idx_user_enabled_anniversary ON user (enabled, anniversary_month, anniversary_day)');
        }

        // ── transaction_freeze_log ────────────────────────────────────────────
        $this->addSql('ALTER TABLE transaction_freeze_log CHANGE action action VARCHAR(20) DEFAULT NULL');

        // ── transaction ───────────────────────────────────────────────────────
        if ($this->foreignKeyExists('transaction', 'FK_723705D1F44CABFF')) {
            $this->addSql('ALTER TABLE transaction DROP FOREIGN KEY FK_723705D1F44CABFF');
        }
        $this->addSql('ALTER TABLE transaction CHANGE is_frozen is_frozen TINYINT(1) DEFAULT 0');
        if (!$this->indexExists('transaction', 'idx_transaction_package_has_restrictions')) {
            $this->addSql('CREATE INDEX idx_transaction_package_has_restrictions ON transaction (package_has_restrictions)');
        }

        // ── package ───────────────────────────────────────────────────────────
        if (!$this->indexExists('package', 'idx_package_has_restrictions')) {
            $this->addSql('CREATE INDEX idx_package_has_restrictions ON package (has_restrictions)');
        }

        // ── achievement_condition_catalog ─────────────────────────────────────
        if (!$this->indexExists('achievement_condition_catalog', 'uq_condition')) {
            $this->addSql('CREATE UNIQUE INDEX uq_condition ON achievement_condition_catalog (category_key, condition_key)');
        }

        // ── achievement_badge ─────────────────────────────────────────────────
        if ($this->indexExists('achievement_badge', 'UNIQ_5190D21355B8DFCF')) {
            $this->addSql('ALTER TABLE achievement_badge RENAME INDEX UNIQ_5190D21355B8DFCF TO uniq_badge_key');
        }
        $this->addSql('ALTER TABLE achievement_badge CHANGE default_pts default_pts INT DEFAULT 0, CHANGE sort_order sort_order INT DEFAULT 0, CHANGE is_active is_active TINYINT(1) DEFAULT 1');

        // ── achievement ───────────────────────────────────────────────────────
        if ($this->indexExists('achievement', 'IDX_96737FF1896DBBDE')) {
            $this->addSql('ALTER TABLE achievement RENAME INDEX IDX_96737FF1896DBBDE TO idx_achievement_updated_by');
        }
        if ($this->indexExists('achievement', 'IDX_96737FF1B03A8386')) {
            $this->addSql('ALTER TABLE achievement RENAME INDEX IDX_96737FF1B03A8386 TO idx_achievement_created_by');
        }
        $this->addSql('ALTER TABLE achievement CHANGE difficulty difficulty VARCHAR(32) DEFAULT NULL');
    }
}
