<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Tools > Customer Management > Preferences > Christmas Promotion"
 * (legacy `tools::update_customer_promo_status()` +
 * `tools_model::update_customer_promo_status()`, `customer_promo_status`
 * table). Legacy only shows this toggle at all when a live call to
 * `https://prod.mysuncash.com/api/pos.php?method=getChristmasPromoStatus`
 * says the promo is currently active — a seasonal, environment-specific
 * gate not worth replicating; the toggle is always shown here instead,
 * since hiding/showing it changes nothing about the underlying read/write.
 */
class CustomerPromoService
{
    private const PROMO_TYPE = 'ChristmasPromotion';

    public function isActive(int $customerId): bool
    {
        return DB::connection('mysuncash')->table('customer_promo_status')
            ->where('customer_id', $customerId)
            ->where('promo_type', self::PROMO_TYPE)
            ->value('status') === 'ACTIVE';
    }

    public function setActive(int $customerId, bool $active, User $actor): void
    {
        $status = $active ? 'ACTIVE' : 'INACTIVE';
        $actorName = $actor->name ?? $actor->email;

        $table = DB::connection('mysuncash')->table('customer_promo_status');
        $existing = $table->where('customer_id', $customerId)->where('promo_type', self::PROMO_TYPE)->first();

        if ($existing) {
            $table->where('id', $existing->id)->update(['status' => $status, 'user_id_modify' => $actor->id, 'update_date' => now()]);
        } else {
            $table->insert(['customer_id' => $customerId, 'status' => $status, 'promo_type' => self::PROMO_TYPE, 'user_id_modify' => $actor->id, 'create_date' => now()]);
        }

        ActivityLog::recordAction($actor, 'Tools - Customer Management', 'promo_status_updated', "Set Christmas Promotion to {$status} for customer {$customerId} (by {$actorName})");
    }
}
