<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > VAT" (legacy `vat` controller -> `transactions_model::vat_transactions`).
 * Daily / Weekly / Monthly / Custom tabs all run the same query over a date
 * window: completed (`status = 0`) MONEY_TRANSFER rows of `webpos_transaction`,
 * listed with their amount, fee, VAT and stamp tax, plus the window's totals.
 *
 * Same rows and totals as legacy. What changed is only how they're found:
 *  - `SUBSTRING(transaction_date,1,10) BETWEEN a AND b` -> `transaction_date >=
 *    a AND < b+1day` (the same days, but able to use an index), backed by
 *    (transaction_type, status, transaction_date) — all three filters in one range.
 *  - `SELECT *` -> only the six columns shown.
 *  - Rows are ordered by (date, id); legacy had no
 *    ORDER BY, so its order was whatever the planner produced.
 *
 * Totals are summed from the listed rows (PHP, like legacy) so the strip can't
 * disagree with the table; NULL amounts count as 0 either way.
 */
class VatReportService
{
    public function report(string $from, string $to): array
    {
        $rows = DB::connection('mysuncash')->table('webpos_transaction')
            ->where('transaction_type', 'MONEY_TRANSFER')
            ->where('status', 0)
            ->where('transaction_date', '>=', Carbon::parse($from)->startOfDay()->toDateTimeString())
            ->where('transaction_date', '<', Carbon::parse($to)->addDay()->startOfDay()->toDateTimeString())
            ->orderBy('transaction_date')->orderBy('id')
            ->get(['transaction_id', 'transaction_date', 'transaction_type', 'amount', 'fee_amount', 'vat_amount', 'stamp_amount']);

        $total = ['amount' => 0.0, 'fees' => 0.0, 'vat' => 0.0, 'stamp' => 0.0];
        $data = [];
        foreach ($rows as $r) {
            $total['amount'] += (float) $r->amount;
            $total['fees'] += (float) $r->fee_amount;
            $total['vat'] += (float) $r->vat_amount;
            $total['stamp'] += (float) $r->stamp_amount;
            $data[] = [
                'transaction_id' => $r->transaction_id,
                'transaction_date' => Carbon::parse($r->transaction_date)->format('F d Y'),
                'sort_date' => $r->transaction_date,
                'transaction_type' => $r->transaction_type,
                'amount' => number_format((float) $r->amount, 2),
                'fee' => number_format((float) $r->fee_amount, 2),
                'vat' => number_format((float) $r->vat_amount, 2),
                'stamp' => number_format((float) $r->stamp_amount, 2),
            ];
        }

        return [
            'data' => $data,
            'count' => count($data),
            'total' => array_map(fn ($v) => number_format($v, 2), $total),
        ];
    }
}
