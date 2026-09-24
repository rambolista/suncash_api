<?php

namespace App\Services\Kiosk;

use App\Models\Mysuncash\Island;
use App\Models\Mysuncash\KioskBranch;
use App\Models\Mysuncash\KioskTerminal;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Kiosk > Reports > Credit Voucher" tab (legacy `fastpay::credit_voucher_report()`
 * / `fastpay_kiosk_model::credit_voucher_report()`). A dedicated, narrower
 * view of what `KioskTransactionReportService`'s CREDIT_VOUCHER union branch
 * already covers — this report adds legacy's own Voucher Code filter and a
 * Kiosk Branch filter the general Transaction Report doesn't have, so it's
 * kept as its own tab/query rather than reusing that service.
 *
 * `webpos_transaction_kiosk` already carries `idxStatusTransactionDate` from
 * the Transaction Report's own optimization — `status` and the date range
 * are exactly this report's leading filters too, so no new index is needed
 * on that table. `universal_vouchers.voucher_code` is already indexed.
 * Ordering by `transaction_date` (not `id`, which legacy uses) lets the
 * same composite index satisfy the ORDER BY too — confirmed via EXPLAIN to
 * drop the `Using filesort` that ordering by `id` would otherwise add.
 *
 * Legacy's `Feature`/`ErrorMessage` columns come from
 * `JSON_UNQUOTE(JSON_EXTRACT(other_info, ...))` in SQL, which throws on this
 * MySQL version whenever `other_info` isn't valid JSON (most rows store a
 * plain string like `"SUNCASH_VOUCHER"` instead) — decoded in PHP instead,
 * which simply treats anything non-JSON as absent rather than erroring.
 */
class KioskCreditVoucherReportService
{
    public const COLUMNS = [
        ['key' => 'transaction_date', 'label' => 'Date/Time'],
        ['key' => 'branch', 'label' => 'Branch'],
        ['key' => 'terminal', 'label' => 'Kiosk'],
        ['key' => 'location', 'label' => 'Location'],
        ['key' => 'island', 'label' => 'Island'],
        ['key' => 'product', 'label' => 'Product'],
        ['key' => 'transaction_id', 'label' => 'Transaction ID'],
        ['key' => 'voucher_code', 'label' => 'Voucher Code'],
        ['key' => 'cash_received', 'label' => 'Cash Received'],
        ['key' => 'fee_amount', 'label' => 'Fee'],
        ['key' => 'vat_amount', 'label' => 'VAT'],
        ['key' => 'total_fees', 'label' => 'Total Fees'],
        ['key' => 'product_amount', 'label' => 'Product Amount'],
        ['key' => 'feature', 'label' => 'Feature'],
        ['key' => 'error_message', 'label' => 'Error Message'],
    ];

    /** legacy `voucher_product_id` for the Credit Voucher product. */
    private const CREDIT_VOUCHER_PRODUCT_ID = 3;

    public function listBranches(): array
    {
        return KioskBranch::where('status', KioskBranch::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name'])->all();
    }

    public function listTerminalsForBranch(int $branchId): array
    {
        return KioskTerminal::where('kiosk_branch_id', $branchId)->orderBy('name')->get(['id', 'name'])->all();
    }

    public function listIslands(): array
    {
        return Island::orderBy('name')->get(['id', 'name'])->all();
    }

    private function filteredQuery(Carbon $from, Carbon $to, ?int $branchId, ?int $terminalId, ?int $islandId, ?string $voucherCode): Builder
    {
        $query = DB::connection('mysuncash')->table('webpos_transaction_kiosk as wtk')
            ->join('kiosk_terminal as kt', 'kt.id', '=', 'wtk.terminal_id')
            ->join('kiosk_branch as kb', 'kb.id', '=', 'wtk.branch_id')
            ->leftJoin('island as i', 'i.id', '=', 'kt.island')
            ->join('universal_vouchers as uv', function ($join) {
                $join->on('uv.voucher_code', '=', 'wtk.trans_ref_id')->where('uv.voucher_product_id', self::CREDIT_VOUCHER_PRODUCT_ID);
            })
            ->where('wtk.transaction_type', 'VOUCHER')
            ->where('wtk.status', 0)
            ->where('wtk.transaction_date', '>=', $from)
            ->where('wtk.transaction_date', '<', $to);

        if ($branchId) {
            $query->where('wtk.branch_id', $branchId);
        }
        if ($terminalId) {
            $query->where('wtk.terminal_id', $terminalId);
        }
        if ($islandId) {
            $query->where('kt.island', $islandId);
        }
        if (filled($voucherCode)) {
            $query->where('wtk.trans_ref_id', $voucherCode);
        }

        return $query;
    }

    private function decodeOtherInfo(?string $otherInfo): array
    {
        $decoded = json_decode((string) $otherInfo, true);
        if (! is_array($decoded)) {
            return ['feature' => null, 'error_message' => null];
        }

        $error = $decoded['error'] ?? null;
        $error = is_string($error) ? json_decode($error, true) : $error;

        return [
            'feature' => $decoded['feature'] ?? null,
            'error_message' => is_array($error) ? ($error['message'] ?? null) : null,
        ];
    }

    private function present(object $row): array
    {
        $info = $this->decodeOtherInfo($row->other_info);

        return [
            'transaction_date' => $row->transaction_date,
            'branch' => $row->branch,
            'terminal' => $row->terminal,
            'location' => $row->location ?: 'Unassigned',
            'island' => $row->island ?: 'Unassigned',
            'product' => 'Credit Voucher',
            'transaction_id' => $row->transaction_id,
            'voucher_code' => $row->trans_ref_id,
            'cash_received' => number_format((float) $row->total_amount, 2),
            'fee_amount' => number_format((float) $row->fee_amount, 2),
            'vat_amount' => number_format((float) $row->vat_amount, 2),
            'total_fees' => number_format((float) $row->fee_amount + (float) $row->vat_amount, 2),
            'product_amount' => number_format((float) $row->amount, 2),
            'feature' => $info['feature'] ?: 'Check Log File',
            'error_message' => $info['error_message'] ?: 'Check Log File',
        ];
    }

    public function list(string $from, string $to, ?int $branchId, ?int $terminalId, ?int $islandId, ?string $voucherCode): array
    {
        $fromDt = Carbon::parse($from)->startOfDay();
        $toDt = Carbon::parse($to)->addDay()->startOfDay();

        return $this->filteredQuery($fromDt, $toDt, $branchId, $terminalId, $islandId, $voucherCode)
            ->select(['wtk.transaction_date', 'wtk.transaction_id', 'wtk.trans_ref_id', 'wtk.amount', 'wtk.fee_amount', 'wtk.vat_amount', 'wtk.total_amount', 'wtk.other_info', 'kt.name as terminal', 'kt.location', 'kb.name as branch', 'i.name as island'])
            ->orderByDesc('wtk.transaction_date')
            ->get()
            ->map(fn ($row) => $this->present($row))
            ->all();
    }

    /** Same filtered query, aggregated — always consistent with what's listed/exported. */
    public function totalSummary(string $from, string $to, ?int $branchId, ?int $terminalId, ?int $islandId, ?string $voucherCode): array
    {
        $fromDt = Carbon::parse($from)->startOfDay();
        $toDt = Carbon::parse($to)->addDay()->startOfDay();

        $row = $this->filteredQuery($fromDt, $toDt, $branchId, $terminalId, $islandId, $voucherCode)
            ->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(wtk.total_amount), 0) as total_cash_received, COALESCE(SUM(wtk.fee_amount), 0) as total_fees, COALESCE(SUM(wtk.vat_amount), 0) as total_vat, COALESCE(SUM(wtk.fee_amount + wtk.vat_amount), 0) as grand_total_fees, COALESCE(SUM(wtk.amount), 0) as total_product_amount')
            ->first();

        return [
            'transaction_count' => (int) $row->transaction_count,
            'total_cash_received' => (float) $row->total_cash_received,
            'total_fees' => (float) $row->total_fees,
            'total_vat' => (float) $row->total_vat,
            'grand_total_fees' => (float) $row->grand_total_fees,
            'total_product_amount' => (float) $row->total_product_amount,
        ];
    }
}
