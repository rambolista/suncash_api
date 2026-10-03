<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > Client Summary" (legacy `administrator::clients_summary` /
 * `clients_summary_filter` -> `clients_model::client_list_filtered`).
 *
 * Same rows and columns as legacy, with these deliberate differences:
 *  - Registration dates: `SUBSTRING(creation_date,1,10) BETWEEN a AND b` can't
 *    use an index (and `creation_date` is a VARCHAR). The equivalent
 *    `creation_date >= a AND creation_date < b+1day` compares the same
 *    10-character day prefix, and can.
 *  - Legacy's result page re-posted the status filter as `client_status`
 *    while the controller read `client_status_id`, so after the first search
 *    the Status filter silently did nothing. The filter works here.
 *  - The Client dropdown only needs id + client_id; legacy loaded the full
 *    `client_list()` (joins billpay applications, 16 columns) for it.
 *
 * Kept as legacy: the date-range defaults (a start with no end runs to today
 * in America/New_York; an end with no start drops the date filter), rows in
 * client id order, and a blank status for clients whose status id has no
 * `client_status` row (-1 pending, 2).
 */
class ClientSummaryReportService
{
    /** Dropdown options. */
    public function clients(): array
    {
        return DB::connection('mysuncash')->table('clients')
            ->orderBy('client_id')
            ->get(['id', 'client_id'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'label' => $c->client_id])
            ->all();
    }

    public function list(?int $clientId, ?int $statusId, ?string $start, ?string $end): array
    {
        $query = DB::connection('mysuncash')->table('clients as c')
            ->leftJoin('client_status as s', 'c.client_status_id', '=', 's.id')
            ->select(['c.id', 'c.client_id', 'c.user_name', 'c.client_prefund', 'c.client_settlement', 'c.client_status_id', 's.status', 'c.creation_date'])
            ->when($clientId, fn ($q) => $q->where('c.id', $clientId))
            ->when($statusId !== null, fn ($q) => $q->where('c.client_status_id', $statusId))
            ->orderBy('c.id');

        if (filled($start) && blank($end)) {
            $end = Carbon::now('America/New_York')->toDateString();
        } elseif (blank($start)) {
            $start = $end = null;
        }

        if (filled($start)) {
            $query->where('c.creation_date', '>=', $start)
                ->where('c.creation_date', '<', Carbon::parse($end)->addDay()->toDateString());
        }

        return $query->get()->map(fn ($r) => [
            'id' => (int) $r->id,
            'client_id' => $r->client_id,
            'user_name' => $r->user_name,
            'prefund' => number_format((float) $r->client_prefund, 2),
            'settlement' => number_format((float) $r->client_settlement, 2),
            'status' => $r->status ?? '',
            'is_active' => (string) $r->client_status_id === '0',
            'creation_date' => $r->creation_date,
        ])->all();
    }
}
