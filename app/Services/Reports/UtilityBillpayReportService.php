<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > Utility Billpay" (legacy `utility_billpay_report` controller +
 * `utility_billpay_model`). Legacy ran 9 near-duplicate hand-built SQL
 * strings — one per {biller_code=ALL|set} x {source=ALL|CustomerApp|WebPOS|Kiosk}
 * combination, each repeated again in `getTotalSummary()`. Consolidated here
 * into two base queries (`billpayWebTransactionsQuery` for kiosk/WebPOS via
 * `billpay_web_transactions`, `billpayTransactionsQuery` for app/kiosk-no-card
 * via `billpay_transactions`) unioned per the selected source, reused for the
 * listing, the total summary, and the export — so the total always matches
 * what's listed instead of drifting from a separately hand-copied query.
 *
 * `billpay_web_transactions` had no index at all (status is always filtered
 * alongside the date range), `billpay_transactions` had no date index, and
 * `customers.mobile` — the join key from `billpay_transactions` via
 * `ezkard_accounts` — had no index whatsoever, forcing a full table scan for
 * every row. See the accompanying migration.
 */
class UtilityBillpayReportService
{
    public const COLUMNS = [
        ['key' => 'transaction_date', 'label' => 'Date Time'],
        ['key' => 'transaction_number', 'label' => 'Transaction Number'],
        ['key' => 'customer_name', 'label' => 'Customer Name'],
        ['key' => 'customer_mobile', 'label' => 'Customer Mobile'],
        ['key' => 'biller_code', 'label' => 'Utility Company'],
        ['key' => 'bill_account_no', 'label' => 'Account Number'],
        ['key' => 'bill_amount', 'label' => 'Amount Paid'],
        ['key' => 'source', 'label' => 'Source'],
    ];

    private const PER_PAGE = 300;

    private const SOURCE_CASE = "CASE WHEN c.client_id = 'quickpay' THEN 'KIOSK' WHEN c.client_id = '2014-01' THEN 'WebPOS' WHEN c.client_id LIKE 'SC%' THEN 'WebPOS' ELSE c.client_id END";

    private function billpayWebTransactionsQuery(string $source, ?string $billerCode, Carbon $from, Carbon $to): Builder
    {
        $query = DB::connection('mysuncash')->table('billpay_web_transactions as bwt')
            ->leftJoin('clients as c', 'c.id', '=', 'bwt.merchant_id')
            ->where('bwt.transaction_id', 'not like', '00%')
            ->where('bwt.status', 0)
            ->where('bwt.transaction_date', '>=', $from)
            ->where('bwt.transaction_date', '<', $to)
            ->select([
                'bwt.transaction_date',
                'bwt.transaction_id as transaction_number',
                'bwt.customer_name',
                'bwt.customer_mobile',
                'bwt.biller_code',
                'bwt.bill_account_no',
                'bwt.bill_amount',
                DB::raw(self::SOURCE_CASE.' as source'),
            ]);

        if (filled($billerCode)) {
            $query->where('bwt.biller_code', $billerCode);
        }
        if ($source === 'WebPOS') {
            $query->where('c.client_id', '!=', 'quickpay');
        } elseif ($source === 'Kiosk') {
            $query->where('c.client_id', 'quickpay');
        }

        return $query;
    }

    private function billpayTransactionsQuery(string $source, ?string $billerCode, Carbon $from, Carbon $to): Builder
    {
        $query = DB::connection('mysuncash')->table('billpay_transactions as bt')
            ->leftJoin('ezkard_accounts as eza', 'eza.id', '=', 'bt.ezkard_id')
            ->leftJoin('customers as c', 'c.mobile', '=', 'eza.mobile_number')
            ->leftJoin('kiosk_utility_accounts as kua', 'kua.bill_account_no', '=', 'bt.bill_account_no')
            ->where('bt.transaction_date', '>=', $from)
            ->where('bt.transaction_date', '<', $to)
            ->groupBy('bt.id')
            ->select([
                'bt.transaction_date',
                DB::raw("COALESCE(bt.reference_no, '-1') as transaction_number"),
                DB::raw("CASE WHEN bt.ezkard_id = -1 THEN kua.bill_account_name ELSE CONCAT(c.first_name, ' ', c.last_name) END as customer_name"),
                DB::raw('CASE WHEN bt.ezkard_id = -1 THEN kua.customer_mobile ELSE eza.card_number END as customer_mobile'),
                'bt.biller_code',
                'bt.bill_account_no',
                'bt.bill_amount',
                DB::raw("CASE WHEN bt.ezkard_id = -1 THEN 'KIOSK' ELSE 'CustomerApp' END as source"),
            ]);

        if (filled($billerCode)) {
            $query->where('bt.biller_code', $billerCode);
        }
        if ($source === 'CustomerApp') {
            // Legacy INNER-joins ezkard_accounts/customers for this source, i.e. only rows with a resolved app account.
            $query->where('bt.ezkard_id', '!=', -1)->whereNotNull('eza.id')->whereNotNull('c.id');
        } elseif ($source === 'Kiosk') {
            $query->where('bt.ezkard_id', -1);
        }

        return $query;
    }

    /** @return Builder unioned query, not yet ordered/paginated */
    private function baseQuery(?string $source, ?string $billerCode, string $from, string $to): Builder
    {
        $source = filled($source) ? $source : 'ALL';
        $fromDt = Carbon::parse($from)->startOfDay();
        $toDt = Carbon::parse($to)->addDay()->startOfDay();

        $queries = [];
        if (in_array($source, ['ALL', 'WebPOS', 'Kiosk'], true)) {
            $queries[] = $this->billpayWebTransactionsQuery($source, $billerCode, $fromDt, $toDt);
        }
        if (in_array($source, ['ALL', 'CustomerApp', 'Kiosk'], true)) {
            $queries[] = $this->billpayTransactionsQuery($source, $billerCode, $fromDt, $toDt);
        }

        $union = array_shift($queries);
        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        return $union;
    }

    private function present(object $row): array
    {
        return [
            'transaction_date' => $row->transaction_date,
            'transaction_number' => $row->transaction_number,
            'customer_name' => trim((string) $row->customer_name) ?: '—',
            'customer_mobile' => $row->customer_mobile,
            'biller_code' => $row->biller_code,
            'bill_account_no' => $row->bill_account_no,
            'bill_amount' => number_format((float) $row->bill_amount, 2),
            'source' => $row->source,
        ];
    }

    public function paginatedList(?string $source, ?string $billerCode, string $from, string $to, int $page): array
    {
        $paginator = $this->baseQuery($source, $billerCode, $from, $to)
            ->orderByDesc('transaction_date')
            ->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));

        return [
            'data' => $paginator->getCollection()->map(fn ($row) => $this->present($row))->all(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    public function exportRows(?string $source, ?string $billerCode, string $from, string $to): array
    {
        return $this->baseQuery($source, $billerCode, $from, $to)
            ->orderByDesc('transaction_date')
            ->get()
            ->map(fn ($row) => $this->present($row))
            ->all();
    }

    /** Same union query, aggregated — always consistent with the listed/exported rows. */
    public function totalSummary(?string $source, ?string $billerCode, string $from, string $to): array
    {
        $row = DB::connection('mysuncash')->query()
            ->fromSub($this->baseQuery($source, $billerCode, $from, $to), 't')
            ->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(bill_amount), 0) as transaction_amount')
            ->first();

        return [
            'transaction_count' => (int) $row->transaction_count,
            'transaction_amount' => (float) $row->transaction_amount,
        ];
    }

    /** Legacy `getbillercode()` — distinct billers seen across both tables. */
    public function billerCodes(): array
    {
        $connection = DB::connection('mysuncash');
        $fromWeb = $connection->table('billpay_web_transactions')->where('transaction_id', 'not like', '00%')->pluck('biller_code')->all();
        $fromApp = $connection->table('billpay_transactions')->pluck('biller_code')->all();

        $codes = array_unique(array_filter([...$fromWeb, ...$fromApp]));
        sort($codes);

        return array_values($codes);
    }
}
