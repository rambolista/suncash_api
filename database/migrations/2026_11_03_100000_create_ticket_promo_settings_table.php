<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `ticket_promo_settings` — the free raffle-ticket prizes managed by "Promotions > Promo Ticket Settings". Legacy adds it
 * in `api/migration_file.php`; this is the same definition (latin1, so legacy and the new admin share the table), created
 * only where it doesn't exist yet. `idx_promo_type_status` serves both the list and the one-promo-per-draw-day check.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('mysuncash')->statement('CREATE TABLE IF NOT EXISTS `ticket_promo_settings` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `ticket_count` INT(11) DEFAULT 1,
            `quantity` INT(11) DEFAULT 0,
            `remaining_quantity` INT(11) DEFAULT 0,
            `description` VARCHAR(100) DEFAULT NULL,
            `promo_type` VARCHAR(100) DEFAULT NULL,
            `service` VARCHAR(100) DEFAULT NULL,
            `service_ref` VARCHAR(150) DEFAULT NULL,
            `draw_type` VARCHAR(100) DEFAULT \'weekly_draw\',
            `target_group_type` VARCHAR(30) DEFAULT \'all\',
            `target_group` TEXT DEFAULT NULL,
            `draw_date` TIMESTAMP NULL DEFAULT NULL,
            `created_date` TIMESTAMP NULL DEFAULT NULL,
            `updated_date` TIMESTAMP NULL DEFAULT NULL,
            `status` ENUM(\'USED\',\'ACTIVE\',\'DELETED\') DEFAULT \'ACTIVE\',
            PRIMARY KEY (`id`),
            KEY `idx_promo_type_status` (`promo_type`, `status`)
        ) ENGINE=INNODB DEFAULT CHARSET=latin1');
    }

    public function down(): void
    {
        // Left in place: legacy owns this table and may hold data.
    }
};
