<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Base schema migration.
 *
 * Crea las tablas base del sistema con IF NOT EXISTS.
 * Solo incluye las columnas e índices del schema original.
 * Las migraciones posteriores se encargan de agregar columnas, índices y tablas nuevas.
 */
final class Version20240101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Base schema: create all core tables in their original form';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on mysql.'
        );

        // ── Tablas independientes (sin FK) ──────────────────────────────────
        // branch_office: sin zone/plaza/address/phone (los agrega 20260408)
        $this->addSql('CREATE TABLE IF NOT EXISTS `branch_office` (
            `id`         INT AUTO_INCREMENT NOT NULL,
            `name`       VARCHAR(255) NOT NULL,
            `is_active`  TINYINT(1) NOT NULL,
            `public`     TINYINT(1) DEFAULT 0 NOT NULL,
            `place`      VARCHAR(100) DEFAULT NULL,
            `slug`       VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME DEFAULT NULL,
            `updated_at` DATETIME DEFAULT NULL,
            UNIQUE INDEX UNIQ_81301E575E237E06 (`name`),
            PRIMARY KEY(`id`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS `configuration` (
            `id`     INT AUTO_INCREMENT NOT NULL,
            `module` VARCHAR(100) NOT NULL,
            `data`   LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\',
            PRIMARY KEY(`id`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS `discipline` (
            `id`          INT AUTO_INCREMENT NOT NULL,
            `name`        VARCHAR(100) NOT NULL,
            `description` LONGTEXT DEFAULT NULL,
            `is_active`   TINYINT(1) NOT NULL,
            `created_at`  DATETIME DEFAULT NULL,
            `updated_at`  DATETIME DEFAULT NULL,
            UNIQUE INDEX UNIQ_75BEEE3F5E237E06 (`name`),
            PRIMARY KEY(`id`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS `package` (
            `id`             INT AUTO_INCREMENT NOT NULL,
            `total_classes`  INT NOT NULL,
            `amount`         NUMERIC(10,2) NOT NULL,
            `type`           VARCHAR(25) NOT NULL,
            `days_expiry`    SMALLINT NOT NULL,
            `is_unlimited`   TINYINT(1) NOT NULL,
            `alt_text`       VARCHAR(150) DEFAULT NULL,
            `is_active`      TINYINT(1) NOT NULL,
            `new_user`       TINYINT(1) DEFAULT 0 NOT NULL,
            `public`         TINYINT(1) DEFAULT 0 NOT NULL,
            `special_price`  NUMERIC(10,2) DEFAULT NULL,
            `discount_info`  VARCHAR(50) DEFAULT NULL,
            `created_at`     DATETIME DEFAULT NULL,
            `updated_at`     DATETIME DEFAULT NULL,
            PRIMARY KEY(`id`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS `post` (
            `id`         INT AUTO_INCREMENT NOT NULL,
            `type`       VARCHAR(50) NOT NULL,
            `title`      VARCHAR(255) NOT NULL,
            `slug`       VARCHAR(255) NOT NULL,
            `content`    LONGTEXT NOT NULL,
            `is_active`  TINYINT(1) NOT NULL,
            `created_at` DATETIME DEFAULT NULL,
            `updated_at` DATETIME DEFAULT NULL,
            UNIQUE INDEX UNIQ_5A8A6C8D989D9B62 (`slug`),
            PRIMARY KEY(`id`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // staff: color_hex existe en el schema original, 20260407 lo mueve a staff_profile y lo elimina aquí
        $this->addSql('CREATE TABLE IF NOT EXISTS `staff` (
            `id`         INT AUTO_INCREMENT NOT NULL,
            `username`   VARCHAR(25) NOT NULL,
            `password`   VARCHAR(64) NOT NULL,
            `email`      VARCHAR(60) DEFAULT NULL,
            `last_login` DATETIME DEFAULT NULL,
            `roles`      LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\',
            `permissions` LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\',
            `is_active`  TINYINT(1) DEFAULT NULL,
            `color_hex`  VARCHAR(7) DEFAULT NULL,
            `deleted`    TINYINT(1) DEFAULT 0 NOT NULL,
            UNIQUE INDEX UNIQ_426EF392F85E0677 (`username`),
            UNIQUE INDEX UNIQ_426EF392E7927C74 (`email`),
            PRIMARY KEY(`id`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS `coupon` (
            `id`                  INT AUTO_INCREMENT NOT NULL,
            `name`                VARCHAR(100) NOT NULL,
            `code`                VARCHAR(20) NOT NULL,
            `date_start`          DATE DEFAULT NULL,
            `date_end`            DATE DEFAULT NULL,
            `discount`            NUMERIC(4,2) NOT NULL,
            `uses_total`          INT NOT NULL,
            `apply_special_price` TINYINT(1) DEFAULT 0 NOT NULL,
            `used`                INT DEFAULT 0 NOT NULL,
            `created_at`          DATETIME DEFAULT NULL,
            `updated_at`          DATETIME DEFAULT NULL,
            UNIQUE INDEX UNIQ_64BF3F0277153098 (`code`),
            PRIMARY KEY(`id`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // ── Tablas con FK a branch_office / staff / discipline ───────────────
        $this->addSql('CREATE TABLE IF NOT EXISTS `user` (
            `id`                      INT AUTO_INCREMENT NOT NULL,
            `branch_office_id`        INT DEFAULT NULL,
            `name`                    VARCHAR(100) NOT NULL,
            `lastname`                VARCHAR(100) DEFAULT NULL,
            `phone`                   VARCHAR(15) DEFAULT NULL,
            `birthday`                DATE DEFAULT NULL,
            `emergency_contact_name`  VARCHAR(255) DEFAULT NULL,
            `emergency_contact_phone` VARCHAR(15) DEFAULT NULL,
            `free_session`            TINYINT(1) NOT NULL,
            `conekta_id`              VARCHAR(25) DEFAULT NULL,
            `email`                   VARCHAR(180) NOT NULL,
            `enabled`                 TINYINT(1) NOT NULL,
            `password`                VARCHAR(255) NOT NULL,
            `last_login`              DATETIME DEFAULT NULL,
            `confirmation_token`      VARCHAR(180) DEFAULT NULL,
            `password_requested_at`   DATETIME DEFAULT NULL,
            `roles`                   LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\',
            `created_at`              DATETIME DEFAULT NULL,
            `updated_at`              DATETIME DEFAULT NULL,
            UNIQUE INDEX UNIQ_8D93D649E7927C74 (`email`),
            UNIQUE INDEX UNIQ_8D93D649C05FB297 (`confirmation_token`),
            INDEX IDX_8D93D649FD2AF2F7 (`branch_office_id`),
            PRIMARY KEY(`id`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // exercise_room: sin seat_layout, lo agrega 20260309
        $this->addSql('CREATE TABLE IF NOT EXISTS `exercise_room` (
            `id`                   INT AUTO_INCREMENT NOT NULL,
            `discipline_id`        INT DEFAULT NULL,
            `branch_office_id`     INT DEFAULT NULL,
            `name`                 VARCHAR(100) NOT NULL,
            `capacity`             INT NOT NULL,
            `type`                 VARCHAR(25) NOT NULL,
            `is_active`            TINYINT(1) NOT NULL,
            `places_not_available` LONGTEXT DEFAULT NULL COMMENT \'(DC2Type:simple_array)\',
            `created_at`           DATETIME DEFAULT NULL,
            `updated_at`           DATETIME DEFAULT NULL,
            INDEX IDX_2BBA3329A5522701 (`discipline_id`),
            INDEX IDX_2BBA3329FD2AF2F7 (`branch_office_id`),
            PRIMARY KEY(`id`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // staff_profile: sin color_hex, lo agrega 20260407
        $this->addSql('CREATE TABLE IF NOT EXISTS `staff_profile` (
            `id`               INT AUTO_INCREMENT NOT NULL,
            `staff_id`         INT DEFAULT NULL,
            `firstname`        VARCHAR(100) DEFAULT NULL,
            `paternal_surname` VARCHAR(100) DEFAULT NULL,
            `maternal_surname` VARCHAR(100) DEFAULT NULL,
            `photo`            VARCHAR(100) DEFAULT NULL,
            `telephone`        VARCHAR(50) DEFAULT NULL,
            `address`          LONGTEXT DEFAULT NULL,
            `description`      LONGTEXT DEFAULT NULL,
            `admission_at`     DATE DEFAULT NULL,
            `created_at`       DATETIME DEFAULT NULL,
            `updated_at`       DATETIME DEFAULT NULL,
            UNIQUE INDEX UNIQ_DDE1BDB9D4D57CD (`staff_id`),
            PRIMARY KEY(`id`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS `instructors_disciplines` (
            `staff_id`      INT NOT NULL,
            `discipline_id` INT NOT NULL,
            INDEX IDX_2DE67534D4D57CD (`staff_id`),
            INDEX IDX_2DE67534A5522701 (`discipline_id`),
            PRIMARY KEY(`staff_id`, `discipline_id`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS `staff_branch_office` (
            `staff_id`         INT NOT NULL,
            `branch_office_id` INT NOT NULL,
            INDEX IDX_C2DC633FD4D57CD (`staff_id`),
            INDEX IDX_C2DC633FFD2AF2F7 (`branch_office_id`),
            PRIMARY KEY(`staff_id`, `branch_office_id`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // session: sin seat_layout (20260309) ni session_status_date_time_idx (20260323)
        $this->addSql('CREATE TABLE IF NOT EXISTS `session` (
            `id`                  INT AUTO_INCREMENT NOT NULL,
            `exercise_room_id`    INT DEFAULT NULL,
            `discipline_id`       INT DEFAULT NULL,
            `instructor_id`       INT DEFAULT NULL,
            `branch_office_id`    INT DEFAULT NULL,
            `date_start`          DATE NOT NULL,
            `time_start`          TIME NOT NULL,
            `exercise_room_capacity` INT NOT NULL,
            `type`                VARCHAR(25) NOT NULL,
            `status`              SMALLINT NOT NULL,
            `information`         VARCHAR(150) DEFAULT NULL,
            `places_not_available` LONGTEXT DEFAULT NULL COMMENT \'(DC2Type:simple_array)\',
            `available_capacity`  INT NOT NULL,
            `created_at`          DATETIME DEFAULT NULL,
            `updated_at`          DATETIME DEFAULT NULL,
            INDEX IDX_D044D5D48E0287D6 (`exercise_room_id`),
            INDEX IDX_D044D5D4A5522701 (`discipline_id`),
            INDEX IDX_D044D5D48C4FC193 (`instructor_id`),
            INDEX IDX_D044D5D4FD2AF2F7 (`branch_office_id`),
            PRIMARY KEY(`id`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // transaction: sin expired_at (20240920), sin indexes extra (20240322/20240421/20260309)
        $this->addSql('CREATE TABLE IF NOT EXISTS `transaction` (
            `id`                    INT AUTO_INCREMENT NOT NULL,
            `user_id`               INT DEFAULT NULL,
            `package_id`            INT DEFAULT NULL,
            `branch_office_id`      INT DEFAULT NULL,
            `coupon_id`             INT DEFAULT NULL,
            `package_total_classes` INT NOT NULL,
            `package_is_unlimited`  TINYINT(1) NOT NULL,
            `package_amount`        NUMERIC(10,2) NOT NULL,
            `package_type`          VARCHAR(25) NOT NULL,
            `package_days_expiry`   SMALLINT NOT NULL,
            `charge_method`         VARCHAR(25) NOT NULL,
            `charge_id`             VARCHAR(25) DEFAULT NULL,
            `charge_auth_code`      VARCHAR(10) DEFAULT NULL,
            `card_name`             VARCHAR(255) DEFAULT NULL,
            `card_type`             VARCHAR(25) DEFAULT NULL,
            `card_brand`            VARCHAR(25) DEFAULT NULL,
            `card_issuer`           VARCHAR(25) DEFAULT NULL,
            `card_last4`            VARCHAR(5) DEFAULT NULL,
            `is_expired`            TINYINT(1) NOT NULL,
            `expiration_at`         DATETIME DEFAULT NULL,
            `is_completed`          TINYINT(1) NOT NULL,
            `status`                INT NOT NULL,
            `refunded_at`           DATETIME DEFAULT NULL,
            `error_code`            VARCHAR(255) DEFAULT NULL,
            `error_message`         VARCHAR(255) DEFAULT NULL,
            `have_sessions_available` TINYINT(1) NOT NULL,
            `discount`              SMALLINT DEFAULT 0 NOT NULL,
            `package_special_price` NUMERIC(10,2) DEFAULT NULL,
            `coupon_discount`       NUMERIC(4,2) DEFAULT NULL,
            `total`                 NUMERIC(10,2) DEFAULT \'0.00\' NOT NULL,
            `created_at`            DATETIME DEFAULT NULL,
            `updated_at`            DATETIME DEFAULT NULL,
            INDEX IDX_723705D1A76ED395 (`user_id`),
            INDEX IDX_723705D1F44CABFF (`package_id`),
            INDEX IDX_723705D1FD2AF2F7 (`branch_office_id`),
            INDEX IDX_723705D166C5951B (`coupon_id`),
            PRIMARY KEY(`id`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // reservation: sin attended (20240316), changed_at (20240411), ratings (20260309), indexes extra (20240322/20260309/20260323)
        $this->addSql('CREATE TABLE IF NOT EXISTS `reservation` (
            `id`               INT AUTO_INCREMENT NOT NULL,
            `user_id`          INT DEFAULT NULL,
            `transaction_id`   INT DEFAULT NULL,
            `session_id`       INT DEFAULT NULL,
            `is_available`     TINYINT(1) NOT NULL,
            `place_number`     SMALLINT NOT NULL,
            `cancellation_at`  DATETIME DEFAULT NULL,
            `created_at`       DATETIME DEFAULT NULL,
            `updated_at`       DATETIME DEFAULT NULL,
            INDEX IDX_42C84955A76ED395 (`user_id`),
            INDEX IDX_42C849552FC0CB0F (`transaction_id`),
            INDEX IDX_42C84955613FECDF (`session_id`),
            PRIMARY KEY(`id`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // ── tablas restantes ─────────────────────────────────────────────────
        $this->addSql('CREATE TABLE IF NOT EXISTS `coupon_package` (
            `coupon_id`  INT NOT NULL,
            `package_id` INT NOT NULL,
            INDEX IDX_3100240366C5951B (`coupon_id`),
            INDEX IDX_31002403F44CABFF (`package_id`),
            PRIMARY KEY(`coupon_id`, `package_id`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS `coupon_history` (
            `id`             INT AUTO_INCREMENT NOT NULL,
            `coupon_id`      INT NOT NULL,
            `transaction_id` INT NOT NULL,
            `user_id`        INT NOT NULL,
            `discount`       NUMERIC(4,2) NOT NULL,
            `created_at`     DATETIME DEFAULT NULL,
            `updated_at`     DATETIME DEFAULT NULL,
            INDEX IDX_C8D233DD66C5951B (`coupon_id`),
            INDEX IDX_C8D233DD2FC0CB0F (`transaction_id`),
            INDEX IDX_C8D233DDA76ED395 (`user_id`),
            PRIMARY KEY(`id`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS `waiting_list` (
            `user_id`      INT NOT NULL,
            `session_id`   INT NOT NULL,
            `is_available` TINYINT(1) NOT NULL,
            `error`        VARCHAR(255) DEFAULT NULL,
            `created_at`   DATETIME DEFAULT NULL,
            `updated_at`   DATETIME DEFAULT NULL,
            INDEX IDX_E4F3965BA76ED395 (`user_id`),
            INDEX IDX_E4F3965B613FECDF (`session_id`),
            PRIMARY KEY(`user_id`, `session_id`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // messenger_messages: la crea 20240316 con IF NOT EXISTS

        // ── Foreign keys (solo si no existen ya) ─────────────────────────────
        $this->addForeignKeyIfNotExists('coupon_package',      'FK_3100240366C5951B', 'ALTER TABLE `coupon_package` ADD CONSTRAINT `FK_3100240366C5951B` FOREIGN KEY (`coupon_id`) REFERENCES `coupon` (`id`) ON DELETE CASCADE');
        $this->addForeignKeyIfNotExists('coupon_package',      'FK_31002403F44CABFF', 'ALTER TABLE `coupon_package` ADD CONSTRAINT `FK_31002403F44CABFF` FOREIGN KEY (`package_id`) REFERENCES `package` (`id`) ON DELETE CASCADE');
        $this->addForeignKeyIfNotExists('coupon_history',      'FK_C8D233DD66C5951B', 'ALTER TABLE `coupon_history` ADD CONSTRAINT `FK_C8D233DD66C5951B` FOREIGN KEY (`coupon_id`) REFERENCES `coupon` (`id`)');
        $this->addForeignKeyIfNotExists('coupon_history',      'FK_C8D233DD2FC0CB0F', 'ALTER TABLE `coupon_history` ADD CONSTRAINT `FK_C8D233DD2FC0CB0F` FOREIGN KEY (`transaction_id`) REFERENCES `transaction` (`id`)');
        $this->addForeignKeyIfNotExists('coupon_history',      'FK_C8D233DDA76ED395', 'ALTER TABLE `coupon_history` ADD CONSTRAINT `FK_C8D233DDA76ED395` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)');
        $this->addForeignKeyIfNotExists('exercise_room',       'FK_2BBA3329A5522701', 'ALTER TABLE `exercise_room` ADD CONSTRAINT `FK_2BBA3329A5522701` FOREIGN KEY (`discipline_id`) REFERENCES `discipline` (`id`)');
        $this->addForeignKeyIfNotExists('exercise_room',       'FK_2BBA3329FD2AF2F7', 'ALTER TABLE `exercise_room` ADD CONSTRAINT `FK_2BBA3329FD2AF2F7` FOREIGN KEY (`branch_office_id`) REFERENCES `branch_office` (`id`)');
        $this->addForeignKeyIfNotExists('reservation',         'FK_42C84955A76ED395', 'ALTER TABLE `reservation` ADD CONSTRAINT `FK_42C84955A76ED395` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)');
        $this->addForeignKeyIfNotExists('reservation',         'FK_42C849552FC0CB0F', 'ALTER TABLE `reservation` ADD CONSTRAINT `FK_42C849552FC0CB0F` FOREIGN KEY (`transaction_id`) REFERENCES `transaction` (`id`)');
        $this->addForeignKeyIfNotExists('reservation',         'FK_42C84955613FECDF', 'ALTER TABLE `reservation` ADD CONSTRAINT `FK_42C84955613FECDF` FOREIGN KEY (`session_id`) REFERENCES `session` (`id`)');
        $this->addForeignKeyIfNotExists('session',             'FK_D044D5D48E0287D6', 'ALTER TABLE `session` ADD CONSTRAINT `FK_D044D5D48E0287D6` FOREIGN KEY (`exercise_room_id`) REFERENCES `exercise_room` (`id`)');
        $this->addForeignKeyIfNotExists('session',             'FK_D044D5D4A5522701', 'ALTER TABLE `session` ADD CONSTRAINT `FK_D044D5D4A5522701` FOREIGN KEY (`discipline_id`) REFERENCES `discipline` (`id`)');
        $this->addForeignKeyIfNotExists('session',             'FK_D044D5D48C4FC193', 'ALTER TABLE `session` ADD CONSTRAINT `FK_D044D5D48C4FC193` FOREIGN KEY (`instructor_id`) REFERENCES `staff` (`id`)');
        $this->addForeignKeyIfNotExists('session',             'FK_D044D5D4FD2AF2F7', 'ALTER TABLE `session` ADD CONSTRAINT `FK_D044D5D4FD2AF2F7` FOREIGN KEY (`branch_office_id`) REFERENCES `branch_office` (`id`)');
        $this->addForeignKeyIfNotExists('instructors_disciplines', 'FK_2DE67534D4D57CD', 'ALTER TABLE `instructors_disciplines` ADD CONSTRAINT `FK_2DE67534D4D57CD` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE');
        $this->addForeignKeyIfNotExists('instructors_disciplines', 'FK_2DE67534A5522701', 'ALTER TABLE `instructors_disciplines` ADD CONSTRAINT `FK_2DE67534A5522701` FOREIGN KEY (`discipline_id`) REFERENCES `discipline` (`id`) ON DELETE CASCADE');
        $this->addForeignKeyIfNotExists('staff_branch_office', 'FK_C2DC633FD4D57CD', 'ALTER TABLE `staff_branch_office` ADD CONSTRAINT `FK_C2DC633FD4D57CD` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE');
        $this->addForeignKeyIfNotExists('staff_branch_office', 'FK_C2DC633FFD2AF2F7', 'ALTER TABLE `staff_branch_office` ADD CONSTRAINT `FK_C2DC633FFD2AF2F7` FOREIGN KEY (`branch_office_id`) REFERENCES `branch_office` (`id`) ON DELETE CASCADE');
        $this->addForeignKeyIfNotExists('staff_profile',       'FK_DDE1BDB9D4D57CD', 'ALTER TABLE `staff_profile` ADD CONSTRAINT `FK_DDE1BDB9D4D57CD` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE');
        $this->addForeignKeyIfNotExists('transaction',         'FK_723705D1A76ED395', 'ALTER TABLE `transaction` ADD CONSTRAINT `FK_723705D1A76ED395` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)');
        $this->addForeignKeyIfNotExists('transaction',         'FK_723705D1F44CABFF', 'ALTER TABLE `transaction` ADD CONSTRAINT `FK_723705D1F44CABFF` FOREIGN KEY (`package_id`) REFERENCES `package` (`id`)');
        $this->addForeignKeyIfNotExists('transaction',         'FK_723705D1FD2AF2F7', 'ALTER TABLE `transaction` ADD CONSTRAINT `FK_723705D1FD2AF2F7` FOREIGN KEY (`branch_office_id`) REFERENCES `branch_office` (`id`)');
        $this->addForeignKeyIfNotExists('transaction',         'FK_723705D166C5951B', 'ALTER TABLE `transaction` ADD CONSTRAINT `FK_723705D166C5951B` FOREIGN KEY (`coupon_id`) REFERENCES `coupon` (`id`)');
        $this->addForeignKeyIfNotExists('user',                'FK_8D93D649FD2AF2F7', 'ALTER TABLE `user` ADD CONSTRAINT `FK_8D93D649FD2AF2F7` FOREIGN KEY (`branch_office_id`) REFERENCES `branch_office` (`id`)');
        $this->addForeignKeyIfNotExists('waiting_list',        'FK_E4F3965BA76ED395', 'ALTER TABLE `waiting_list` ADD CONSTRAINT `FK_E4F3965BA76ED395` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)');
        $this->addForeignKeyIfNotExists('waiting_list',        'FK_E4F3965B613FECDF', 'ALTER TABLE `waiting_list` ADD CONSTRAINT `FK_E4F3965B613FECDF` FOREIGN KEY (`session_id`) REFERENCES `session` (`id`)');
    }

    public function down(Schema $schema): void
    {
        // El down de la migración base no elimina las tablas para evitar pérdida accidental de datos.
        $this->abortIf(true, 'Down migration not supported for base schema — would destroy all data.');
    }

    private function addForeignKeyIfNotExists(string $tableName, string $constraintName, string $sql): void
    {
        $exists = (int) $this->connection->fetchOne(
            'SELECT COUNT(1)
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME        = :tableName
               AND CONSTRAINT_NAME   = :constraintName
               AND CONSTRAINT_TYPE   = \'FOREIGN KEY\'',
            [
                'tableName'      => $tableName,
                'constraintName' => $constraintName,
            ]
        );

        if (0 === $exists) {
            $this->addSql($sql);
        }
    }
}
