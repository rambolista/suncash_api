<?php

namespace App\Services\Kiosk;

use Illuminate\Support\Facades\DB;

/**
 * "Kiosk > Reports > Commission" tab (legacy `fastpay::kiosk_commission()` /
 * `getKioskCommissionReport()`). Reuses the exact same filtered transaction
 * row set as the "Transaction" tab (`KioskTransactionReportService::list()`
 * — legacy's own `kiosk_commission()` action calls the very same
 * `getKioskTransactionReport()` model method) and layers each row's
 * agent/SunCash/owner commission split on top via `KioskCommissionMath`
 * (legacy `Kiosk_commission_model::computeCommission()` — see that class's
 * docblock for the commission-math quirks preserved here).
 *
 * Also replicated: for `CARD_WITHDRAWAL` rows, the fee/VAT used for the
 * commission calculation is NOT `wtk.fee_amount`/`wtk.vat_amount` (which
 * bakes in a bank processing surcharge) but a fresh lookup against
 * `fees WHERE fee_type = 'KIOSK_CARD_WITHDRAWAL' AND channel = 'Kiosk'`
 * banded by amount (legacy `getCardWithdrawalFee()`, amount_to = 0 meaning
 * "open-ended"). Note `KioskAgentCommissionReportService` replicates a
 * DIFFERENT legacy helper for the same purpose (`calculate_fee()`, no
 * open-ended handling) — the two reports are not consistent with each
 * other in legacy, and that inconsistency is preserved rather than
 * "fixed" to match.
 */
class KioskCommissionReportService
{
    public function __construct(
        private readonly KioskTransactionReportService $transactions,
        private readonly KioskCommissionMath $math,
    ) {}

    public function listTerminals(): array
    {
        return $this->transactions->listTerminals();
    }

    public function listIslands(): array
    {
        return $this->transactions->listIslands();
    }

    private function cardWithdrawalFee(float $amount): array
    {
        $fee = DB::connection('mysuncash')->table('fees')
            ->where('fee_type', 'KIOSK_CARD_WITHDRAWAL')
            ->where('channel', 'Kiosk')
            ->where(function ($query) use ($amount) {
                $query->where(function ($q) use ($amount) {
                    $q->where('amount_to', 0)->where('amount_from', '<=', $amount);
                })->orWhere(function ($q) use ($amount) {
                    $q->where('amount_to', '!=', 0)->where('amount_from', '<=', $amount)->where('amount_to', '>=', $amount);
                });
            })
            ->first();

        if (! $fee) {
            return ['fee_amount' => 0.0, 'vat_amount' => 0.0];
        }

        $feeAmount = (float) $fee->fixed;
        $vatAmount = (float) $fee->vat_percentage > 0
            ? round($feeAmount * (float) $fee->vat_percentage / 100, 2)
            : (float) $fee->vat_charges_transfer_fees;

        return ['fee_amount' => $feeAmount, 'vat_amount' => $vatAmount];
    }

    public function list(string $dateFrom, string $dateTo, ?string $type = null, ?int $terminalId = null, ?int $islandId = null): array
    {
        $transactions = $this->transactions->list($dateFrom, $dateTo, $type, $terminalId, $islandId);

        $terminalCache = [];
        $profileCache = [];
        $feeCache = [];

        $rows = array_map(function ($row) use (&$terminalCache, &$profileCache, &$feeCache) {
            // Legacy remaps this one alias before computing commission (kept purely for the commission product-code match — display label is untouched).
            $commissionType = $row['transaction_type'] === 'LOAD_MOBILEWALLET_SANDDOLLAR' ? 'LOAD_SANDDOLLAR' : $row['transaction_type'];

            $feeAmount = $row['fee_amount'];
            $vatAmount = $row['vat_amount'];
            if ($row['transaction_type'] === 'CARD_WITHDRAWAL') {
                $cacheKey = number_format($row['amount'], 2);
                if (! array_key_exists($cacheKey, $feeCache)) {
                    $feeCache[$cacheKey] = $this->cardWithdrawalFee($row['amount']);
                }
                $feeAmount = $feeCache[$cacheKey]['fee_amount'];
                $vatAmount = $feeCache[$cacheKey]['vat_amount'];
            }

            $commission = $this->math->computeCommission($terminalCache, $profileCache, $commissionType, $row['terminal_id'], $row['amount'], $feeAmount);

            return [
                'datetime' => $row['datetime'],
                'terminal_code' => $row['terminal_code'],
                'location' => $row['location'],
                'island' => $row['island'],
                'product' => $row['product'],
                'transaction_id' => $row['transaction_id'],
                'customer_number' => $row['customer_number'],
                'amount' => $row['amount'],
                'fee_amount' => $feeAmount,
                'vat_amount' => $vatAmount,
                'agent_commission' => $commission['agent_commission'],
                'suncash_commission' => $commission['suncash_commission'],
                'owner_commission' => $commission['owner_commission'],
            ];
        }, $transactions['rows']);

        return ['rows' => $rows];
    }
}
