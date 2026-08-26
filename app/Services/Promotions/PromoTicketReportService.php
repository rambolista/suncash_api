<?php

namespace App\Services\Promotions;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Read-only report over `promo_entries` — one row per earned raffle ticket.
 * Mirrors legacy admin's wu_promo/reports screen. The table holds tens of
 * thousands of rows app-wide, so this is paginated server-side rather than
 * loaded wholesale into a client-side table.
 */
class PromoTicketReportService
{
    private const PER_PAGE = 25;

    public function list(string $dateFrom, string $dateTo, ?string $promoType, ?string $status, int $page): LengthAwarePaginator
    {
        $query = DB::connection('mysuncash')
            ->table('promo_entries as pe')
            ->join('customers as c', function ($join) {
                $join->on('c.id', '=', 'pe.customer_id')
                    ->where('c.is_sub_account', '0')
                    ->where('c.is_locked', '0')
                    ->where('c.status', 'A');
            })
            ->leftJoin('island as i', 'i.id', '=', 'c.island')
            ->leftJoin('promo_items as pit', 'pit.id', '=', 'pe.item_id')
            ->whereBetween(DB::raw('DATE(pe.create_date)'), [$dateFrom, $dateTo])
            ->selectRaw(
                "pe.id, pe.create_date,
                 CONCAT(c.first_name, ' ', c.last_name) as customer_name,
                 c.mobile as mobile_number,
                 CASE WHEN pe.other_service = 'all' THEN CONCAT('TICKET 0', pe.id)
                      ELSE CONCAT('TICKET ', pe.other_service, '0', pe.id) END as ticket,
                 pe.status,
                 pe.prize_type as type,
                 CASE pe.prize_type WHEN 'item' THEN pit.item_description WHEN 'cash' THEN pe.prize ELSE NULL END as prize,
                 pe.update_date as redeemed_date,
                 i.name as island"
            )
            ->orderByRaw("FIELD(pe.status, 'WON', 'CLAIMED', 'ACTIVE')")
            ->orderByDesc('pe.id');

        if ($promoType) {
            $query->where('pe.promo_type', $promoType);
        }
        if ($status) {
            $query->where('pe.status', $status);
        }

        return $query->paginate(self::PER_PAGE, ['*'], 'page', $page);
    }
}
