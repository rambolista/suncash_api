<?php

namespace App\Services\Kiosk;

use Illuminate\Support\Facades\DB;

/**
 * "Kiosk > Reports > Agent Commission" tab (legacy
 * `fastpay::kiosk_agent_commission()` / `getKioskAgentCommissionReport()`).
 *
 * Despite the name, this is NOT grouped by any agent/manager entity — legacy
 * never joins `kiosk_manager` here. It's the per-transaction Commission
 * report's SAME underlying rows, aggregated by `(terminal_id, product)` over
 * the date range, showing only the `agent_commission` leg (summed) plus
 * transaction count/amount/fees per group — `suncash_commission`/
 * `owner_commission` are computed per-transaction (via `KioskCommissionMath`,
 * same as the Commission tab) but never surfaced or accumulated, matching
 * legacy exactly.
 *
 * Reuses `KioskTransactionReportService::list()` for the row source, same as
 * the Commission tab, but legacy's own SQL for this specific report has only
 * 3 UNION branches (main + SUNCASH_VOUCHER + UNIBUCKS_VOUCHER) — there is no
 * CREDIT_VOUCHER arm, so CREDIT_VOUCHER transactions are silently excluded
 * from this report specifically. Replicated by filtering those rows out
 * after fetching, rather than by writing a second near-duplicate UNION query.
 *
 * Legacy quirk preserved: rows are bucketed under a grouping key that remaps
 * `LOAD_MOBILEWALLET_SANDDOLLAR` to `LOAD_SANDDOLLAR`, but the row-matching
 * step inside the aggregation loop compares each row's RAW (un-remapped)
 * type against that same key — so a `LOAD_MOBILEWALLET_SANDDOLLAR` row can
 * never satisfy `raw_type === 'LOAD_SANDDOLLAR'` and is dropped from every
 * group. Net effect: `LOAD_MOBILEWALLET_SANDDOLLAR` transactions never
 * appear in this report's output at all. Replicated here as an explicit
 * filter (same end result, without reproducing the confusing double-keying).
 *
 * Legacy also uses a DIFFERENT CARD_WITHDRAWAL fee override than the
 * Commission tab: `Kiosk_commission_model::calculate_fee()` (band condition
 * requires `amount_to > 0`, no open-ended-tier handling), not
 * `getCardWithdrawalFee()` (`amount_to = 0` means open-ended). The two
 * legacy reports are inconsistent with each other here — replicated as-is
 * rather than harmonized. Only `fee_amount` is used (not VAT), matching
 * legacy's own comment that "vat is not included in the commission".
 */
class KioskAgentCommissionReportService
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

    /** Legacy `Kiosk_commission_model::calculate_fee()` — fetches all KIOSK_CARD_WITHDRAWAL/Kiosk fee bands once, first `amount_to > 0` band match wins. */
    private function cardWithdrawalFeeBands(): array
    {
        return DB::connection('mysuncash')->table('fees')
            ->where('fee_type', 'KIOSK_CARD_WITHDRAWAL')
            ->where('channel', 'Kiosk')
            ->get(['amount_from', 'amount_to', 'fixed'])
            ->all();
    }

    private function cardWithdrawalFee(array $bands, float $amount): float
    {
        foreach ($bands as $band) {
            if ((float) $band->amount_to > 0 && $amount >= (float) $band->amount_from && $amount <= (float) $band->amount_to) {
                return (float) $band->fixed;
            }
        }

        return 0.0;
    }

    public function list(string $dateFrom, string $dateTo, ?string $type = null, ?int $terminalId = null, ?int $islandId = null): array
    {
        $transactions = $this->transactions->list($dateFrom, $dateTo, $type, $terminalId, $islandId)['rows'];

        // Legacy's UNION for this report has no CREDIT_VOUCHER arm, and
        // LOAD_MOBILEWALLET_SANDDOLLAR rows never survive its grouping-key
        // mismatch (see class docblock) — both excluded here to match.
        $transactions = array_filter($transactions, fn ($row) => ! in_array($row['transaction_type'], ['CREDIT_VOUCHER', 'LOAD_MOBILEWALLET_SANDDOLLAR'], true));

        $groups = [];
        foreach ($transactions as $row) {
            $groups["{$row['terminal_id']}|{$row['transaction_type']}"][] = $row;
        }

        $cardWithdrawalBands = null;
        $terminalCache = [];
        $profileCache = [];

        $result = [];
        foreach ($groups as $groupRows) {
            $terminalId = $groupRows[0]['terminal_id'];
            $transactionType = $groupRows[0]['transaction_type'];

            $totalFees = 0.0;
            $agentCommission = 0.0;
            $sumAmount = 0.0;

            foreach ($groupRows as $row) {
                $feeAmount = $row['fee_amount'];
                if ($transactionType === 'CARD_WITHDRAWAL') {
                    $cardWithdrawalBands ??= $this->cardWithdrawalFeeBands();
                    $feeAmount = $this->cardWithdrawalFee($cardWithdrawalBands, $row['amount']);
                }

                $totalFees += $feeAmount;
                $sumAmount += $row['amount'];
                $agentCommission += $this->math->computeCommission($terminalCache, $profileCache, $transactionType, $terminalId, $row['amount'], $feeAmount)['agent_commission'];
            }

            $result[] = [
                'terminal_code' => $groupRows[0]['terminal_code'],
                'island' => $groupRows[0]['island'] ?: 'Unassigned',
                'location' => $groupRows[0]['location'] ?: 'Unassigned',
                'product' => $groupRows[0]['product'],
                'transaction_count' => count($groupRows),
                'amount' => round($sumAmount, 2),
                'total_fees' => round($totalFees, 2),
                'agent_commission' => round($agentCommission, 2),
            ];
        }

        return ['rows' => $result];
    }
}
