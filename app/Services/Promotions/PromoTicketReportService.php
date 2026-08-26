<?php

namespace App\Services\Promotions;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only report over `promo_entries` — one row per earned raffle ticket.
 * Mirrors legacy admin's wu_promo/reports screen. The table holds tens of
 * thousands of rows app-wide, so the list view is paginated server-side
 * rather than loaded wholesale into a client-side table; exports are capped
 * generously instead of streamed row-by-row, since a single date-filtered
 * export realistically stays well under those caps.
 */
class PromoTicketReportService
{
    private const PER_PAGE = 25;

    private const CSV_LIMIT = 20000;

    private const PDF_LIMIT = 1000;

    public const COLUMNS = [
        ['key' => 'id', 'label' => 'ID'],
        ['key' => 'create_date', 'label' => 'Created'],
        ['key' => 'customer_name', 'label' => 'Customer'],
        ['key' => 'mobile_number', 'label' => 'Mobile'],
        ['key' => 'island', 'label' => 'Island'],
        ['key' => 'ticket', 'label' => 'Ticket'],
        ['key' => 'status', 'label' => 'Status'],
        ['key' => 'type', 'label' => 'Type'],
        ['key' => 'prize', 'label' => 'Prize'],
        ['key' => 'redeemed_date', 'label' => 'Redeemed'],
    ];

    private function baseQuery(string $dateFrom, string $dateTo, ?string $promoType, ?string $status): Builder
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

        return $query;
    }

    public function list(string $dateFrom, string $dateTo, ?string $promoType, ?string $status, int $page): LengthAwarePaginator
    {
        return $this->baseQuery($dateFrom, $dateTo, $promoType, $status)
            ->paginate(self::PER_PAGE, ['*'], 'page', $page);
    }

    public function listForCsv(string $dateFrom, string $dateTo, ?string $promoType, ?string $status): Collection
    {
        return $this->baseQuery($dateFrom, $dateTo, $promoType, $status)->limit(self::CSV_LIMIT)->get();
    }

    public function listForPdf(string $dateFrom, string $dateTo, ?string $promoType, ?string $status): array
    {
        $query = $this->baseQuery($dateFrom, $dateTo, $promoType, $status);
        $totalCount = $query->count();
        $rows = $query->limit(self::PDF_LIMIT)->get();

        return [
            'rows' => $rows,
            'total_count' => $totalCount,
            'truncated' => $totalCount > self::PDF_LIMIT,
        ];
    }
}
